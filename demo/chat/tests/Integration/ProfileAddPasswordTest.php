<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\PasswordUpdatedSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\ConfirmAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestAddPasswordActionDTO;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the profile add-password-via-email handler (HIL-406): a
 * signed-in user with no password AND no verified email (SMS-only or legacy OAuth)
 * proves an email with a one-time code, then sets a password keyed on it. Step 1
 * (request) issues an `email_add` code to a free email but refuses one already
 * verified by another account (the code is mailed to the entered address, so a
 * stranger's verified email must never be coded); step 2 (confirm) length-checks
 * the password before burning the code, verifies the code against the challenge
 * minted for this user, then writes a verified `password` identity and fans a
 * password_updated(MODE_ADDED) signal.
 *
 * Verification codes are only logged by the dev-stub deliverer, never surfaced to a
 * browser, so the confirm path cannot be driven from e2e; these tests seed a
 * known-code challenge through the verifications object collection instead — the
 * same level HIL-402 (ProfileSetPasswordTest) and the other code flows are tested
 * at. Requires the test DB reset before run (composer run test:db-reset).
 */
final class ProfileAddPasswordTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string NEW_PASSWORD = 'a-brand-new-secret';
    private const string CODE = '424242';
    private const int MAX_ATTEMPTS = 5;
    private const int TTL_SECONDS = 3600;

    /**
     * A request for a free email issues an `email_add` challenge for the session user.
     *
     * @throws HilosException When setup or the request handler fails
     */
    public function testRequestIssuesEmailAddChallengeForAFreeEmail(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'add-pw-req-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $agent->authenticateSession($token, $userId, null);

            new ProfilePage($agent)->onAction(
                'add-pw-req-ak',
                ChatSignalConstants::ADD_PASSWORD_REQUEST,
                new RequestAddPasswordActionDTO($email),
            );

            $challenge = $this->verifications()->findActive(
                VerificationType::EMAIL_ADD,
                $email,
                self::MAX_ATTEMPTS,
            );
            $this->assertNotNull($challenge, 'A free email must be issued a code');
            $this->assertSame($userId, $challenge->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A request for an email verified by another account is refused with no code sent.
     *
     * @throws HilosException When setup fails
     */
    public function testRequestRefusesAnEmailVerifiedByAnotherAccount(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'add-pw-taken-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $agent->authenticateSession($token, $userId, null);

            $otherId = Hilos::$db->users->actions->createWithName('Email Owner')->id;
            $this->insertVerifiedIdentity($otherId, $email, IdentityType::MAGIC_LINK);

            $rejected = false;
            try {
                new ProfilePage($agent)->onAction(
                    'add-pw-taken-ak',
                    ChatSignalConstants::ADD_PASSWORD_REQUEST,
                    new RequestAddPasswordActionDTO($email),
                );
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertSame('That email is already in use', $exception->getMessage());
            }
            $this->assertTrue($rejected, "Another account's verified email must be refused");

            $this->assertNull(
                $this->verifications()->findActive(VerificationType::EMAIL_ADD, $email, self::MAX_ATTEMPTS),
                'No code may be issued for an in-use email',
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A confirm with the right code writes a verified password identity and signals success.
     *
     * @throws HilosException When setup or the confirm handler fails
     */
    public function testConfirmCreatesVerifiedPasswordIdentityAndSignalsAdded(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'add-pw-ok-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $agent->authenticateSession($token, $userId, null);
            $this->issueChallenge($email, $userId);

            new ProfilePage($agent)->onAction(
                'add-pw-ok-ak',
                ChatSignalConstants::ADD_PASSWORD_CONFIRM,
                new ConfirmAddPasswordActionDTO($email, self::CODE, self::NEW_PASSWORD),
            );

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $this->assertSame($userId, $identity->userId);
            $this->assertTrue($identity->verified);
            $this->assertTrue($identity->verifyPassword(self::NEW_PASSWORD));

            $signal = $this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED);
            $this->assertNotNull($signal);
            $this->assertSame('add-pw-ok-ak', $signal->targetAcceptKey);
            $this->assertInstanceOf(PasswordUpdatedSignalData::class, $signal->data);
            $this->assertSame(PasswordUpdatedSignalData::MODE_ADDED, $signal->data->mode);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A confirm with a weak password is refused before the code is verified/burned.
     *
     * @throws HilosException When setup fails
     */
    public function testConfirmWithAWeakPasswordIsRejectedBeforeVerifyingTheCode(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'add-pw-weak-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $agent->authenticateSession($token, $userId, null);
            $this->issueChallenge($email, $userId);

            $rejected = false;
            try {
                new ProfilePage($agent)->onAction(
                    'add-pw-weak-ak',
                    ChatSignalConstants::ADD_PASSWORD_CONFIRM,
                    new ConfirmAddPasswordActionDTO($email, self::CODE, 'short'),
                );
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertStringContainsString('at least', $exception->getMessage());
            }
            $this->assertTrue($rejected, 'A weak password must be rejected');

            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));
            $this->assertNotNull(
                $this->verifications()->findActive(VerificationType::EMAIL_ADD, $email, self::MAX_ATTEMPTS),
                'A weak password must not burn the code',
            );
            $this->assertNull($this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A confirm with the wrong code is refused and writes no identity.
     *
     * @throws HilosException When setup fails
     */
    public function testConfirmWithAWrongCodeIsRejected(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'add-pw-badcode-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $agent->authenticateSession($token, $userId, null);
            $this->issueChallenge($email, $userId);

            $rejected = false;
            try {
                new ProfilePage($agent)->onAction(
                    'add-pw-badcode-ak',
                    ChatSignalConstants::ADD_PASSWORD_CONFIRM,
                    new ConfirmAddPasswordActionDTO($email, '000000', self::NEW_PASSWORD),
                );
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertSame('Invalid or expired code', $exception->getMessage());
            }
            $this->assertTrue($rejected, 'A wrong code must be rejected');

            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));
            $this->assertNull($this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Registers the truth sources and signal router the add-password path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();

        return new ChatAgent();
    }

    /**
     * Opens an anonymous session and connection for an accept key and marks it current.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the session under
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey): string
    {
        $token = RandomHelper::hex(16);
        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
            ),
            '',
            '',
        );
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Seeds an active `email_add` challenge with a known code for the confirm path.
     *
     * @param string $email Target email identifier
     * @param int $userId Owning user id carried on the challenge
     * @throws HilosException When the challenge insert fails
     */
    private function issueChallenge(string $email, int $userId): void
    {
        $this->verifications()->createChallenge(
            VerificationType::EMAIL_ADD,
            $email,
            $userId,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Inserts a verified email identity so a second account owns the target email.
     *
     * @param int $userId Owning user id
     * @param string $email Verified email identifier
     * @param string $type Identity type (an email-bearing verified type)
     * @throws HilosException When the insert query fails
     */
    private function insertVerifiedIdentity(int $userId, string $email, string $type): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string($type));
        $params->add(SqlParam::string($email));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, `'
            . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`) VALUES (?, ?, ?, 1)',
            $params,
        );
    }

    /**
     * Resolves the framework verifications object collection for seeding challenges.
     *
     * @return ObjectUserVerifications Verifications persistence primitives
     * @throws HilosException When the collection is not configured
     */
    private function verifications(): ObjectUserVerifications
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::verifications);
        $this->assertInstanceOf(ObjectUserVerifications::class, $collection);

        return $collection;
    }

    /**
     * Drains the queued signals and returns the first WebSocket payload for a name.
     *
     * @param string $signalName Signal name to match
     * @return ?WebSocketSignalData First matching WebSocket signal payload, or null
     * @throws HilosException When the queue cannot be read
     */
    private function takeQueuedWebSocketSignal(string $signalName): ?WebSocketSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== $signalName) {
                continue;
            }

            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

            return $signal->data;
        }

        return null;
    }

    /**
     * Builds a unique lowercase email for one test.
     *
     * @return string Unique email identifier
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }
}
