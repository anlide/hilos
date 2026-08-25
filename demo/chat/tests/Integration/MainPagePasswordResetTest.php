<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\CompletePasswordResetActionDTO;
use Hilos\Auth\Library\DTO\ConfirmPasswordResetActionDTO;
use Hilos\Auth\Library\DTO\RequestPasswordResetActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
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
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;

/**
 * Integration tests for recovery by code (HIL-416): the code is accepted on one
 * screen and the password saved on the next, the grant between them belongs to the
 * session rather than to the address, two devices may both reach the password
 * screen and only the first save counts, and finishing takes the account back -
 * this session signed in, every other session of the user signed out.
 *
 * Codes are only mailed, never surfaced to a caller (the dev-stub deliverer merely
 * logs them), so every case seeds a known-code challenge through the verifications
 * object collection after the request - findActive() answers the newest challenge,
 * so the seeded code is the live one.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPagePasswordResetTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string OLD_PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'a whole new passphrase';
    private const string OTHER_PASSWORD = 'the losing passphrase';
    private const string CODE = '424242';
    private const string WRONG_CODE = '000000';
    private const int TTL_SECONDS = 900;

    /**
     * An accepted code moves the surface to the password step and grants the session.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAnAcceptedCodeOpensThePasswordStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'accept-ak');

        try {
            $this->requestReset($agent, 'accept-ak', $email);
            $this->seedKnownCode($email);

            $outcome = $this->confirm($agent, 'accept-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
            $this->assertTrue(
                Hilos::$rt->hilosRecoveryWaiters['accept-ak']?->codeAccepted,
                'The session that proved the code holds the grant',
            );
            $this->assertNotNull(
                $this->activeChallenge($email),
                'Accepting a code must not spend it - the password step still has to',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A wrong code is an inline error, and the person stays on the code screen.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAWrongCodeIsRefusedWithoutMovingOrGranting(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'wrong-ak');
        $this->requestReset($agent, 'wrong-ak', $email);
        $this->seedKnownCode($email);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid or expired code');

            $this->confirm($agent, 'wrong-ak', $email, self::WRONG_CODE);
        } finally {
            $this->assertFalse(
                Hilos::$rt->hilosRecoveryWaiters['wrong-ak']?->codeAccepted,
                'A wrong code grants nothing',
            );
            $this->cleanUp();
        }
    }

    /**
     * A code that is no longer live rolls the surface back instead of accusing a typo.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAnExpiredChallengeRollsTheSurfaceBackToTheAddress(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);
        $this->openSession($agent, 'expired-ak');

        try {
            $this->requestReset($agent, 'expired-ak', $email);
            $this->verifications()->voidActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());

            $outcome = $this->confirm($agent, 'expired-ak', $email, self::CODE);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESET_CODE_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code proven for one address grants that address and no other of the session.
     *
     * Two tabs of one browser share a session token and may be parked on two different
     * addresses. Granting by session alone would let somebody prove the code of an
     * address they own and then save a password for the one they do not - with no code
     * for it ever asked for, and the owner logged out of the account afterwards.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testAProvenCodeGrantsOnlyTheAddressItWasProvenFor(): void
    {
        $agent = $this->bootAgent();
        $mine = $this->uniqueEmail();
        $theirs = $this->uniqueEmail();
        $this->seedUserWithPassword($mine);
        $this->seedUserWithPassword($theirs);

        $token = $this->openSession($agent, 'two-tabs-mine-ak');
        $this->openSession($agent, 'two-tabs-theirs-ak', $token);

        try {
            ExecutionContext::setCurrentAcceptKey('two-tabs-theirs-ak');
            $this->requestReset($agent, 'two-tabs-theirs-ak', $theirs);
            ExecutionContext::setCurrentAcceptKey('two-tabs-mine-ak');
            $this->requestReset($agent, 'two-tabs-mine-ak', $mine);
            $this->seedKnownCode($mine);

            $this->confirm($agent, 'two-tabs-mine-ak', $mine, self::CODE);

            $this->assertTrue(Hilos::$rt->hilosRecoveryWaiters['two-tabs-mine-ak']?->codeAccepted);
            $this->assertFalse(
                Hilos::$rt->hilosRecoveryWaiters['two-tabs-theirs-ak']?->codeAccepted,
                'The address nobody proved a code for stays shut',
            );

            $outcome = $this->complete($agent, 'two-tabs-mine-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($mine)),
                'The save lands on the address the code was proven for',
            );
            $this->assertTrue(
                password_verify(self::OLD_PASSWORD, (string)$this->readSecret($theirs)),
                'The other address keeps its password',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A recovery started from the account's other address changes its one password (HIL-692).
     *
     * The other half of the same dead end: an address a person can sign in with had to be
     * an address they can recover through, or the fix would have been half a fix. The code
     * goes to the address that was typed - it is already proven to be theirs - and lands
     * on the password of the account behind it.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testRecoveryThroughASecondAddressChangesTheAccountsPassword(): void
    {
        $agent = $this->bootAgent();
        $passwordEmail = $this->uniqueEmail();
        $secondEmail = $this->uniqueEmail();
        $userId = $this->seedUserWithPassword($passwordEmail);
        Hilos::$db->identities->createMagicLinkIdentity($userId, $secondEmail)->markVerified();

        $this->openSession($agent, 'second-address-reset-ak');

        try {
            ExecutionContext::setCurrentAcceptKey('second-address-reset-ak');
            $this->requestReset($agent, 'second-address-reset-ak', $secondEmail);
            $this->seedKnownCode($secondEmail);

            $this->confirm($agent, 'second-address-reset-ak', $secondEmail, self::CODE);
            $outcome = $this->complete($agent, 'second-address-reset-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($passwordEmail)),
                'The save lands on the account\'s one password, wherever it is written',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Both devices reach the password screen; the second one to save is told why not.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testTheSecondDeviceToSaveIsToldThePasswordAlreadyChanged(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedUserWithPassword($email);

        $this->openSession($agent, 'race-first-ak');
        $this->requestReset($agent, 'race-first-ak', $email);
        $this->openSession($agent, 'race-second-ak');
        $this->requestReset($agent, 'race-second-ak', $email);
        $this->seedKnownCode($email);

        try {
            ExecutionContext::setCurrentAcceptKey('race-first-ak');
            $this->confirm($agent, 'race-first-ak', $email, self::CODE);
            ExecutionContext::setCurrentAcceptKey('race-second-ak');
            $this->confirm($agent, 'race-second-ak', $email, self::CODE);

            ExecutionContext::setCurrentAcceptKey('race-first-ak');
            $this->drainConvergeSignals();
            $won = $this->complete($agent, 'race-first-ak', self::NEW_PASSWORD);
            $this->assertTrue($won->ok);

            $converged = $this->drainConvergeSignals();
            $this->assertArrayHasKey('race-second-ak', $converged, 'The device that lost is told, not left waiting');
            $this->assertSame(AuthFlowOutcome::CODE_PASSWORD_ALREADY_CHANGED, $converged['race-second-ak']->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converged['race-second-ak']->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converged['race-second-ak']->intent);
            $this->assertNull(Hilos::$rt->hilosRecoveryWaiters['race-second-ak'], 'Its grant goes with the news');

            // And if its save was already in flight when the news went out, it still cannot
            // land: the grant is gone with the code, so the surface is sent back to the
            // address field instead of writing the password nobody proved a code for.
            ExecutionContext::setCurrentAcceptKey('race-second-ak');
            $lost = $this->complete($agent, 'race-second-ak', self::OTHER_PASSWORD);

            $this->assertFalse($lost->ok);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $lost->step);
            $this->assertTrue(
                password_verify(self::NEW_PASSWORD, (string)$this->readSecret($email)),
                'The account keeps the password of whoever saved first',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Saving signs this session in and logs every other session of the user out.
     *
     * @throws HilosException When setup or reset handling fails
     */
    public function testCompletingSignsThisSessionInAndDropsTheOthers(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = $this->seedUserWithPassword($email);

        $strangerToken = $this->openSession($agent, 'stranger-ak');
        $agent->authenticateSession($strangerToken, $userId, null);
        $this->assertSame($userId, Hilos::$rt->connections['stranger-ak']?->userId);

        $this->openSession($agent, 'owner-ak');

        try {
            $this->requestReset($agent, 'owner-ak', $email);
            $this->seedKnownCode($email);
            $this->confirm($agent, 'owner-ak', $email, self::CODE);

            $outcome = $this->complete($agent, 'owner-ak', self::NEW_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::RECOVERY, $outcome->intent);
            $this->assertTrue(password_verify(self::NEW_PASSWORD, (string)$this->readSecret($email)));

            $this->assertSame($userId, Hilos::$rt->connections['owner-ak']?->userId);
            $this->assertSame(
                $userId,
                $this->sessionOf('owner-ak')?->userId,
                'The session that reset the password stays signed in',
            );
            $this->assertNull(
                Hilos::$db->sessions->findByToken($strangerToken)?->userId,
                'Every other session of the account is reverted to anonymous',
            );
            $this->assertNull(Hilos::$rt->connections['stranger-ak']?->userId);
            $this->assertNull(Hilos::$rt->hilosRecoveryWaiters['owner-ak'], 'A finished recovery releases its waiters');
            $this->assertNull($this->activeChallenge($email), 'The code is spent by the save');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Empties the signal queue and returns the converge signals it held, by target.
     *
     * The push half of recovery leaves no trace in the database or the runtime - being
     * told where your step goes IS the whole effect - so the queue is the only place the
     * news can be read. Called once before the act to clear what the setup queued, and
     * again after it to see what the act sent.
     *
     * @return array<string, AuthConvergeSignalData> Converge payload by target accept key
     */
    private function drainConvergeSignals(): array
    {
        $converged = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $payload = $signal->data instanceof WebSocketSignalData ? $signal->data->data : null;
            if ($payload instanceof AuthConvergeSignalData) {
                $converged[$payload->acceptKey] = $payload;
            }
        }

        return $converged;
    }

    /**
     * Registers the truth sources and signal router the recovery path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateRecoveryWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return new ChatAgent();
    }

    /**
     * Opens an anonymous session and connection for an accept key and marks it current.
     *
     * A token may be passed in to open a SECOND connection of a session that is already
     * open - two tabs of one browser, which is what session-binding is about.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key to open the session under
     * @param ?string $sessionToken Token of a session to join, or null to open a new one
     * @return string The session cookie token registered for the connection
     * @throws HilosException When the handshake fails
     */
    private function openSession(ChatAgent $agent, string $acceptKey, ?string $sessionToken = null): string
    {
        $token = $sessionToken ?? RandomHelper::hex(16);
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
     * Dispatches a reset request through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @throws HilosException When the request handler rejects the action
     */
    private function requestReset(ChatAgent $agent, string $acceptKey, string $email): void
    {
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET,
            new RequestPasswordResetActionDTO($email),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Dispatches a code submission through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $code Submitted verification code
     * @return AuthFlowOutcome Where the surface goes next
     * @throws HilosException When the confirm handler rejects the action
     */
    private function confirm(ChatAgent $agent, string $acceptKey, string $email, string $code): AuthFlowOutcome
    {
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET,
            new ConfirmPasswordResetActionDTO($email, $code),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Dispatches a password save through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $password Submitted new password
     * @return AuthFlowOutcome Where the surface goes next
     * @throws HilosException When the complete handler rejects the action
     */
    private function complete(ChatAgent $agent, string $acceptKey, string $password): AuthFlowOutcome
    {
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            new CompletePasswordResetActionDTO($password),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Seeds a reset challenge with a code this test knows, newer than the mailed one.
     *
     * @param string $email Address being recovered
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownCode(string $email): void
    {
        // Void the mailed challenge first: leaving it behind would let a burned seeded
        // code fall back on a code this test cannot know.
        $this->verifications()->voidActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::PASSWORD_RESET,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Creates an account whose address has a password to reset.
     *
     * @param string $email Account email (also the identity identifier)
     * @return int New user id
     * @throws HilosException When user creation or the identity insert fails
     */
    private function seedUserWithPassword(string $email): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('User')->id;

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $params->add(SqlParam::string(password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT)));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '` '
            . '(`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`, '
            . '`' . EntityIdentity::identifier . '`, `' . EntityIdentity::secret . '`, '
            . '`' . EntityIdentity::verified . '`) VALUES (?, ?, ?, ?, 1)',
            $params,
        );

        return $userId;
    }

    /**
     * Reads the stored password hash for a `password` identity by email.
     *
     * @param string $email Identity identifier
     * @return ?string Stored secret hash or null when absent
     * @throws HilosException When the lookup query fails
     */
    private function readSecret(string $email): ?string
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` '
            . 'WHERE `' . EntityIdentity::type . '` = ? AND `' . EntityIdentity::identifier . '` = ?',
            $params,
        )->first();
        $row = $resultSet?->first();
        $secret = is_array($row) ? ($row[EntityIdentity::secret] ?? null) : null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * @return ObjectUserVerifications Verification persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * Resolves the live reset challenge for an address.
     *
     * @param string $email Address being recovered
     * @return ?object Active challenge, or null when none is live
     * @throws HilosException When the lookup fails
     */
    private function activeChallenge(string $email): ?object
    {
        return $this->verifications()->findActive(VerificationType::PASSWORD_RESET, $email, $this->maxAttempts());
    }

    /**
     * @return int Configured maximum verify attempts per code
     */
    private function maxAttempts(): int
    {
        return max(1, Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS));
    }

    /**
     * Clears the runtime a case filled, so the next one starts on an empty stand.
     *
     * @throws HilosException When the runtime write fails
     */
    private function cleanUp(): void
    {
        $parked = [];
        foreach (Hilos::$rt->hilosRecoveryWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRecoveryWaiters->actions->release($acceptKey);
        }
        Hilos::$rt->connections->actions->clear();
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
