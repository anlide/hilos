<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\PasswordUpdatedSignalData;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Demo\Chat\Pages\DTO\Profile\SetPasswordActionDTO;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

/**
 * Integration tests for the profile set-password handler (HIL-402): a signed-in
 * user adds or changes their own password from the profile. A change re-auths the
 * current password before rewriting the secret; an add attaches a verified
 * `password` identity to the user's already-proven email (no email code); a user
 * with no verified email is refused (that path is HIL-406). Success fans a
 * password_updated signal to the user's connections. Requires the test DB reset
 * before run (composer run test:db-reset).
 */
final class ProfileSetPasswordTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'a-brand-new-secret';

    /** Confirmation code this fixture seeds, since the mailed one is unknowable here. */
    private const string CODE = '424242';

    /** Lifetime of the seeded challenge; long enough that no case can outlive it. */
    private const int CODE_TTL_SECONDS = 900;

    /**
     * A change re-hashes the secret and fans the password_updated success signal.
     *
     * @throws HilosException When setup or the set-password handler fails
     */
    public function testChangePasswordRewritesSecretAndSignalsSuccess(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'set-change-ak');

        try {
            $this->register($agent, 'set-change-ak', $email);

            new ProfilePage($agent)->onAction(
                'set-change-ak',
                ChatSignalConstants::SET_PASSWORD,
                new SetPasswordActionDTO(self::PASSWORD, self::NEW_PASSWORD),
            );

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $this->assertTrue($identity->verifyPassword(self::NEW_PASSWORD));
            $this->assertFalse($identity->verifyPassword(self::PASSWORD));

            $signal = $this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED);
            $this->assertNotNull($signal);
            $this->assertSame('set-change-ak', $signal->targetAcceptKey);
            $this->assertInstanceOf(PasswordUpdatedSignalData::class, $signal->data);
            $this->assertSame(PasswordUpdatedSignalData::MODE_CHANGED, $signal->data->mode);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A change with the wrong current password is refused and rewrites nothing.
     *
     * @throws HilosException When setup fails
     */
    public function testChangePasswordWithWrongCurrentIsRejected(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'set-wrong-ak');

        try {
            $this->register($agent, 'set-wrong-ak', $email);

            $rejected = false;
            try {
                new ProfilePage($agent)->onAction(
                    'set-wrong-ak',
                    ChatSignalConstants::SET_PASSWORD,
                    new SetPasswordActionDTO('not the password', self::NEW_PASSWORD),
                );
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertSame('Current password is incorrect', $exception->getMessage());
            }
            $this->assertTrue($rejected, 'A wrong current password must be rejected');

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $this->assertTrue($identity->verifyPassword(self::PASSWORD));
            $this->assertFalse($identity->verifyPassword(self::NEW_PASSWORD));

            $this->assertNull($this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * An add on a proven email creates a verified password identity and signals success.
     *
     * @throws HilosException When setup or the set-password handler fails
     */
    public function testAddPasswordOnVerifiedEmailCreatesVerifiedIdentity(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'set-add-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Magic User')->id;
            $this->insertVerifiedMagicLink($userId, $email);
            $this->authenticateSession($agent, $token, $userId, null);

            new ProfilePage($agent)->onAction(
                'set-add-ak',
                ChatSignalConstants::SET_PASSWORD,
                new SetPasswordActionDTO('', self::NEW_PASSWORD),
            );

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $this->assertSame($userId, $identity->userId);
            $this->assertTrue($identity->verified);
            $this->assertTrue($identity->verifyPassword(self::NEW_PASSWORD));

            $signal = $this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED);
            $this->assertNotNull($signal);
            $this->assertSame('set-add-ak', $signal->targetAcceptKey);
            $this->assertInstanceOf(PasswordUpdatedSignalData::class, $signal->data);
            $this->assertSame(PasswordUpdatedSignalData::MODE_ADDED, $signal->data->mode);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * An add for a user with no verified email is refused (SMS-only / legacy OAuth → HIL-406).
     *
     * @throws HilosException When setup fails
     */
    public function testAddPasswordWithoutVerifiedEmailIsRejected(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'set-none-ak');

        try {
            $userId = Hilos::$db->users->actions->createWithName('Phone User')->id;
            $this->authenticateSession($agent, $token, $userId, null);

            $rejected = false;
            try {
                new ProfilePage($agent)->onAction(
                    'set-none-ak',
                    ChatSignalConstants::SET_PASSWORD,
                    new SetPasswordActionDTO('', self::NEW_PASSWORD),
                );
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertSame('Confirm an email address first', $exception->getMessage());
            }
            $this->assertTrue($rejected, 'A user with no verified email must be refused');

            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));
            $this->assertNull($this->takeQueuedWebSocketSignal(ChatSignalConstants::PASSWORD_UPDATED));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Registers the truth sources and signal router the set-password path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        // Registering parks the submitting connection as a waiter (HIL-415), so this
        // fixture owns that collection too even though the suite is about passwords.
        RtTruthSourceRegistry::register(StateRegistrationWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
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
        $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        ));
        ExecutionContext::setCurrentAcceptKey($acceptKey);

        return $token;
    }

    /**
     * Registers an account through the main page to seed a password identity.
     *
     * Two actions, because a submit reserves and only the code registers (HIL-415).
     * The mailed code is unknowable here, so the challenge is re-seeded with a known
     * one — the same way MainPageRegisterTest does it — and confirmed.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email (used verbatim as the confirmation matches)
     * @throws HilosException When the register or confirm handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): void
    {
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email, self::PASSWORD),
        );
        $this->deliverLibraryFrames($agent);

        /** @var ObjectUserVerifications $verifications */
        $verifications = Hilos::$db->getObjectCollection(HilosDbContext::verifications);
        $verifications->voidActive(
            VerificationType::REGISTER_CONFIRM,
            $email,
            max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS)),
        );
        $verifications->createChallenge(
            VerificationType::REGISTER_CONFIRM,
            $email,
            null,
            self::CODE,
            self::CODE_TTL_SECONDS,
        );

        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER,
            new ConfirmRegisterActionDTO($email, self::CODE),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Inserts a verified `magic_link` email identity so the add flow has a proven
     * email to attach a password to, without a password identity present.
     *
     * @param int $userId Owning user id
     * @param string $email Verified email identifier
     * @throws HilosException When the insert query fails
     */
    private function insertVerifiedMagicLink(int $userId, string $email): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string(IdentityType::MAGIC_LINK));
        $params->add(SqlParam::string($email));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, `'
            . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`) VALUES (?, ?, ?, 1)',
            $params,
        );
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
