<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Constants\PasswordPolicy;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\ConfirmRegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestRegisterConfirmActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for reserve-on-submit registration (HIL-415): the submit holds
 * the email and sends one code, a second submit of the same address converges on
 * that one code instead of mailing another, a taken address turns into sign-in, and
 * the account appears only when the code comes back - verified, credentialed from
 * the reservation, and with every session parked on the address signed in.
 *
 * Confirmation codes are only mailed, never surfaced to a caller (the dev-stub
 * deliverer merely logs them), so the cases that need one seed a known-code
 * challenge through the verifications object collection - the same level HIL-402
 * and HIL-406 test their code flows at. Seeding AFTER the submit is deliberate:
 * findActive() answers the newest challenge, so the seeded code is the live one.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageRegisterTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PASSWORD = 'correct horse battery';
    private const string OTHER_PASSWORD = 'incorrect zebra staple';
    private const string CODE = '424242';
    private const string WRONG_CODE = '000000';
    private const int TTL_SECONDS = 900;

    /**
     * A submit holds the address and issues a code, and creates no account at all.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterReservesTheAddressAndIssuesOneCode(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'res-ak');

        try {
            $outcome = $this->register($agent, 'res-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $this->assertNotNull($this->reservations()->findActive($email), 'The address must be held');
            $this->assertNotNull($this->activeChallenge($email), 'One code must be issued');

            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'No account may exist before the code comes back',
            );
            $this->assertNull(Hilos::$rt->connections['res-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A second submit of a held address converges on the live code, mailing none.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testSecondRegisterConvergesWithoutASecondCode(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'conv-first-ak');
        $this->register($agent, 'conv-first-ak', $email);
        $firstChallengeId = $this->activeChallenge($email)?->id;

        $this->openSession($agent, 'conv-second-ak');

        try {
            $outcome = $this->register($agent, 'conv-second-ak', $email, self::OTHER_PASSWORD);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertSame(
                $firstChallengeId,
                $this->activeChallenge($email)?->id,
                'The live code must survive a second submit of the same address',
            );
            $this->assertSame(1, $this->reservationRowCount($email), 'One address is one hold');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A submit on an address that has an account is answered with sign-in, not an error.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterOnALiveIdentityAnswersIdentifierTaken(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $user = Hilos::$db->users->actions->createWithName('taken');
        Hilos::$db->identities->createPasswordIdentity((int)$user->id, $email, self::PASSWORD);

        $this->openSession($agent, 'taken-ak');

        try {
            $outcome = $this->register($agent, 'taken-ak', $email);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertNull($this->reservations()->findActive($email), 'A taken address is never held');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A malformed address is refused before anything is held or mailed.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterRefusesAMalformedAddress(): void
    {
        $agent = $this->bootAgent();
        $this->openSession($agent, 'bad-email-ak');

        try {
            $this->expectException(InvalidFormatException::class);
            $this->register($agent, 'bad-email-ak', 'not-an-address');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A password under the policy length is refused before anything is held or mailed.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testRegisterRefusesAShortPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'short-pw-ak');

        try {
            $refused = false;
            try {
                $this->register($agent, 'short-pw-ak', $email, str_repeat('a', PasswordPolicy::MIN_LENGTH - 1));
            } catch (ValidationException $exception) {
                $refused = true;
                $this->assertStringContainsString((string)PasswordPolicy::MIN_LENGTH, $exception->getMessage());
            }

            $this->assertTrue($refused, 'A short password must be refused');
            $this->assertNull($this->reservations()->findActive($email), 'A refused submit holds nothing');
            $this->assertNull($this->activeChallenge($email), 'A refused submit mails nothing');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A wrong code is an inline error that leaves the hold and the step alone.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testWrongCodeIsRejectedWithoutTouchingTheReservation(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'wrong-code-ak');
        $this->register($agent, 'wrong-code-ak', $email);
        $this->seedKnownCode($email);

        try {
            $rejected = false;
            try {
                $this->confirm($agent, 'wrong-code-ak', $email, self::WRONG_CODE);
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertSame('Invalid or expired code', $exception->getMessage());
            }

            $this->assertTrue($rejected, 'A wrong code must be rejected');
            $this->assertNotNull($this->reservations()->findActive($email), 'The hold survives a wrong code');
            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Spending the attempt ceiling on wrong codes burns the challenge.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testAttemptCeilingBurnsTheChallenge(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'burn-ak');
        $this->register($agent, 'burn-ak', $email);
        $this->seedKnownCode($email);

        try {
            for ($attempt = 0; $attempt < $this->maxAttempts(); $attempt++) {
                try {
                    $this->confirm($agent, 'burn-ak', $email, self::WRONG_CODE);
                } catch (ValidationException) {
                    // Every wrong code is rejected; the ceiling is what this case is about.
                }
            }

            $this->assertNull($this->activeChallenge($email), 'The exhausted challenge must be gone');

            // The right code cannot save a burned challenge - the hold outlives it, and
            // getting back in means asking for a new code.
            $this->expectException(ValidationException::class);
            $this->confirm($agent, 'burn-ak', $email, self::CODE);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The right code creates the verified account, announces it, and signs the session in.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmCreatesTheVerifiedAccountAndSignsTheSessionIn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'confirm-ak');
        $this->register($agent, 'confirm-ak', $email);
        $this->seedKnownCode($email);

        try {
            $outcome = $this->confirm($agent, 'confirm-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity, 'The confirmed registration must create the identity');
            $this->assertTrue($identity->verified, 'The code is the proof of ownership');

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($this->localPart($email), Hilos::$db->users[$userId]?->name);

            // The credential travelled from the reservation, so the password typed at the
            // submit is the one the account has.
            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));

            $this->assertSame($userId, $this->sessionOf('confirm-ak')?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['confirm-ak']->userId);
            $this->assertNull($this->reservations()->findActive($email), 'The hold is released on success');
            $this->assertSame(0, $this->reservationRowCount($email));
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['confirm-ak'], 'The confirming waiter is released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The first confirmation signs in every session that was waiting on the address.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmConvergesEveryWaitingSession(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'both-first-ak');
        $this->register($agent, 'both-first-ak', $email);
        $this->openSession($agent, 'both-second-ak');
        $this->register($agent, 'both-second-ak', $email);
        $this->seedKnownCode($email);

        try {
            ExecutionContext::setCurrentAcceptKey('both-first-ak');
            $this->confirm($agent, 'both-first-ak', $email, self::CODE);

            $userId = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)?->userId;
            $this->assertNotNull($userId);

            $this->assertSame($userId, Hilos::$rt->connections['both-first-ak']->userId);
            $this->assertSame(
                $userId,
                Hilos::$rt->connections['both-second-ak']->userId,
                'The session that did not confirm is signed in by the converge',
            );
            $this->assertSame($userId, $this->sessionOf('both-second-ak')?->userId);
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['both-second-ak'], 'Converged waiters are released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A waiter already signed in is moved along but never rebound to the new account.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testSignedInWaiterKeepsItsOwnAccount(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();

        $this->openSession($agent, 'signed-in-ak');
        $this->register($agent, 'signed-in-ak', $email);
        // The waiter's own account, signed in after it parked - the case the mockup
        // calls "somebody else registered this address on another device".
        $ownEmail = $this->uniqueEmail();
        $own = Hilos::$db->users->actions->createWithName('own');
        $ownUserId = (int)$own->id;
        Hilos::$db->identities->createPasswordIdentity($ownUserId, $ownEmail, self::PASSWORD);
        $agent->authenticateSession(
            Hilos::$rt->connections['signed-in-ak']->sessionToken,
            $ownUserId,
            'signed-in-ak',
        );

        $this->openSession($agent, 'confirmer-ak');
        $this->register($agent, 'confirmer-ak', $email);
        $this->seedKnownCode($email);

        try {
            ExecutionContext::setCurrentAcceptKey('confirmer-ak');
            $this->confirm($agent, 'confirmer-ak', $email, self::CODE);

            $newUserId = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)?->userId;
            $this->assertNotNull($newUserId);
            $this->assertNotSame($ownUserId, $newUserId);
            $this->assertSame(
                $ownUserId,
                Hilos::$rt->connections['signed-in-ak']->userId,
                'A stranger registration must not move somebody onto another account',
            );
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['signed-in-ak'], 'The waiter is still released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The sweep frees an expired hold, rolls its waiters back, and reopens the address.
     *
     * @throws HilosException When setup or sweep handling fails
     */
    public function testExpiredReservationIsSweptAndTheAddressReopens(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'expired-ak');
        $this->register($agent, 'expired-ak', $email);

        try {
            $this->ageReservationOut($email);

            $agent->onSignalCron(
                new CronSignalDTO(ChatCronConstants::SWEEP_REGISTRATION_RESERVATIONS),
                '',
                ChatCronConstants::SWEEP_REGISTRATION_RESERVATIONS,
            );

            $this->assertSame(0, $this->reservationRowCount($email), 'The expired hold is deleted');
            $this->assertNull(
                Hilos::$rt->hilosRegistrationWaiters['expired-ak'],
                'A rolled-back waiter is released, not left parked on a hold that is gone',
            );

            // The address is free again: a fresh submit reserves it rather than converging.
            $this->openSession($agent, 'reopened-ak');
            $outcome = $this->register($agent, 'reopened-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertNotNull($this->reservations()->findActive($email));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A confirm against an expired hold rolls the surface back instead of blaming the code.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmOnAnExpiredReservationRollsBack(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'rollback-ak');
        $this->register($agent, 'rollback-ak', $email);
        $this->seedKnownCode($email);
        $this->ageReservationOut($email);

        try {
            $outcome = $this->confirm($agent, 'rollback-ak', $email, self::CODE);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);
            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address that becomes somebody's while held is answered with sign-in, not a
     * second account.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmOnAnAddressTakenMeanwhileAnswersIdentifierTaken(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'raced-ak');
        $this->register($agent, 'raced-ak', $email);
        $this->seedKnownCode($email);

        // The account arrives by the other road the hold cannot block: a sign-in that
        // proves the same address records it as a verified identity of another type,
        // which would not collide with the password identity a confirmation writes.
        $elsewhere = Hilos::$db->users->actions->createWithName('elsewhere');
        Hilos::$db->identities->createMagicLinkIdentity((int)$elsewhere->id, $email);

        try {
            $outcome = $this->confirm($agent, 'raced-ak', $email, self::CODE);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'No second account is built for an address that already has one',
            );
            $this->assertNull($this->sessionOf('raced-ak')?->userId, 'Nobody is signed in');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A connection that changes the address it is registering waits under the new one.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testResubmitRepointsTheWaitingConnection(): void
    {
        $agent = $this->bootAgent();
        $abandoned = $this->uniqueEmail();
        $chosen = $this->uniqueEmail();
        $this->openSession($agent, 'repoint-ak');
        $this->register($agent, 'repoint-ak', $abandoned);

        try {
            $this->register($agent, 'repoint-ak', $chosen);

            $waiter = Hilos::$rt->hilosRegistrationWaiters['repoint-ak'];
            $this->assertNotNull($waiter, 'The re-park keeps one row for the connection');
            $this->assertSame(
                $chosen,
                $waiter->identifier,
                'A connection that moved on must not be converged into the address it left',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A resend inside the cooldown sends nothing, extends nothing, and says how long to wait.
     *
     * @throws HilosException When setup or resend handling fails
     */
    public function testResendInsideTheCooldownIsSilent(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'resend-ak');
        $this->register($agent, 'resend-ak', $email);
        $challengeId = $this->activeChallenge($email)?->id;
        $expiresAt = $this->reservations()->findActive($email)?->expiresAt;

        try {
            $outcome = $this->resend($agent, 'resend-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertGreaterThan(0, (int)$outcome->resendInSeconds, 'The countdown must be reported');
            $this->assertSame($challengeId, $this->activeChallenge($email)?->id, 'No second code inside the cooldown');
            $this->assertSame(
                $expiresAt,
                $this->reservations()->findActive($email)?->expiresAt,
                'A suppressed resend must not push the hold out',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A resend for an address nobody holds rolls back instead of issuing a code.
     *
     * @throws HilosException When setup or resend handling fails
     */
    public function testResendWithoutAReservationRollsBack(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'resend-gone-ak');

        try {
            $outcome = $this->resend($agent, 'resend-gone-ak', $email);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertNull($this->activeChallenge($email), 'No code is issued for an address nobody holds');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Two submits racing on a free address end with one hold, settled by the UNIQUE index.
     *
     * The two page calls of {@see testSecondRegisterConvergesWithoutASecondCode} cannot
     * reach the insert together in one process, so the race is exercised where it is
     * actually decided: the second reservation write for an address that gained one
     * after the caller looked.
     *
     * @throws HilosException When setup or the reservation write fails
     */
    public function testConcurrentReserveKeepsOneHold(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();

        try {
            $this->reservations()->createReservation(IdentityType::PASSWORD, $email, self::PASSWORD, self::TTL_SECONDS);

            $this->expectException(DuplicateValueException::class);
            $this->reservations()->createReservation(
                IdentityType::PASSWORD,
                $email,
                self::OTHER_PASSWORD,
                self::TTL_SECONDS,
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Registers the truth sources and signal router the registration path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::registrationWaiters, true, self::TEST_AGENT_ID);
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
     * Dispatches a register action through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @param string $password Submitted password
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the register handler rejects the action
     */
    private function register(
        ChatAgent $agent,
        string $acceptKey,
        string $email,
        string $password = self::PASSWORD,
    ): AuthFlowOutcome {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REGISTER,
            new RegisterActionDTO($email, $password),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
    }

    /**
     * Dispatches a resend action through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address whose code is re-sent
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the resend handler rejects the action
     */
    private function resend(ChatAgent $agent, string $acceptKey, string $email): AuthFlowOutcome
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REQUEST_REGISTER_CONFIRM,
            new RequestRegisterConfirmActionDTO($email),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
    }

    /**
     * Dispatches a confirm action through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address being confirmed
     * @param string $code Submitted confirmation code
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the confirm handler rejects the action
     */
    private function confirm(ChatAgent $agent, string $acceptKey, string $email, string $code): AuthFlowOutcome
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::CONFIRM_REGISTER,
            new ConfirmRegisterActionDTO($email, $code),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
    }

    /**
     * Seeds a challenge with a code this test knows, newer than the mailed one.
     *
     * @param string $email Address the registration holds
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownCode(string $email): void
    {
        // Void the mailed challenge first: leaving it behind would let a burned seeded
        // code fall back on a code this test cannot know.
        $this->verifications()->voidActive(VerificationType::REGISTER_CONFIRM, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::REGISTER_CONFIRM,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Ages a hold into the past so the sweep and the confirm both read it as expired.
     *
     * @param string $email Held address
     * @throws HilosException When the update query fails
     */
    private function ageReservationOut(string $email): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(date('Y-m-d H:i:s', time() - 1)));
        $params->add(SqlParam::string($email));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `'
            . EntityRegistrationReservation::expires_at . '` = ? WHERE `'
            . EntityRegistrationReservation::identifier . '` = ?',
            $params,
        );
        $this->reservations()->clearInMemory();
    }

    /**
     * Counts the reservation rows held for an address, expired ones included.
     *
     * @param string $email Address to count holds for
     * @return int Number of rows
     * @throws HilosException When the count query fails
     */
    private function reservationRowCount(string $email): int
    {
        return EntityRegistrationReservation::count([EntityRegistrationReservation::identifier => $email]);
    }

    /**
     * Reads the stored password hash of a `password` identity by email.
     *
     * @param string $email Identity identifier
     * @return ?string Stored secret hash or null when absent
     * @throws HilosException When the lookup query fails
     */
    private function readIdentitySecret(string $email): ?string
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
     * @return ObjectRegistrationReservations Reservation persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function reservations(): ObjectRegistrationReservations
    {
        /** @var ObjectRegistrationReservations $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::registrationReservations);

        return $collection;
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
     * Resolves the live registration challenge for an address.
     *
     * @param string $email Address being registered
     * @return ?object Active challenge, or null when none is live
     * @throws HilosException When the lookup fails
     */
    private function activeChallenge(string $email): ?object
    {
        return $this->verifications()->findActive(VerificationType::REGISTER_CONFIRM, $email, $this->maxAttempts());
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
        foreach (Hilos::$rt->hilosRegistrationWaiters as $waiter) {
            $parked[] = $waiter->acceptKey;
        }
        foreach ($parked as $acceptKey) {
            Hilos::$rt->hilosRegistrationWaiters->actions->release($acceptKey);
        }
        Hilos::$rt->connections->actions->clear();
    }

    /**
     * Returns the local part of an email (the display name a registration derives).
     *
     * @param string $email Account email
     * @return string Substring before the first `@`
     */
    private function localPart(string $email): string
    {
        $atPosition = strpos($email, '@');

        return $atPosition === false ? $email : substr($email, 0, $atPosition);
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
