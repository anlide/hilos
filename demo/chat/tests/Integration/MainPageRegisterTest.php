<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\AbandonRegistrationActionDTO;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestRegisterConfirmActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\PasswordPolicy;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

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

            $this->assertSame($email, $this->holdOf('res-ak')?->identifier, 'The browser must be holding it');
            $challenge = $this->activeChallenge($email);
            $this->assertNotNull($challenge, 'One code must be issued');

            // The moment the code screen counts down (HIL-486). Read off the challenge
            // and not off "now plus the setting": the two agree here, and the day a
            // resend reuses a live code they will not - the screen owes the life of the
            // code it is asking for.
            $this->assertSame(
                TimeHelper::sqlToMs((string)$challenge->expiresAt),
                $outcome->expiresAt,
                'The submit answers when the code it issued stops working',
            );

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
     * A second browser on the same address gets its own hold and an honest countdown.
     *
     * The shape HIL-608 gave the race. The address is not taken by the first submit -
     * both browsers are registering and the first to prove it wins - so the second gets
     * a hold of its own, keyed to its own session. What it shares with the first is the
     * CODE: the send gate belongs to the address, so no second letter goes out, and the
     * cooldown is answered out loud instead of leaving this person on a code screen with
     * nothing coming (the silence half of the capture this leaf closes).
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testASecondBrowserGetsItsOwnHoldAndTheLiveCode(): void
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
            $this->assertGreaterThan(
                TimeHelper::nowMs(),
                (int)$outcome->resendAt,
                'The second browser is told when it may ask again, not left in silence',
            );
            $this->assertSame(
                $firstChallengeId,
                $this->activeChallenge($email)?->id,
                'The live code must survive a second submit of the same address',
            );
            $this->assertSame(2, $this->reservationRowCount($email), 'One browser is one hold');
            $this->assertSame($email, $this->holdOf('conv-first-ak')?->identifier);
            $this->assertSame($email, $this->holdOf('conv-second-ak')?->identifier);
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
            $this->assertNull($this->holdOf('taken-ak'), 'A taken address is never held');
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
            $this->assertNull($this->holdOf('short-pw-ak'), 'A refused submit holds nothing');
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
            $this->assertSame($email, $this->holdOf('wrong-code-ak')?->identifier, 'The hold survives a wrong code');
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
            $this->assertNull($this->holdOf('confirm-ak'), 'The hold is released on success');
            $this->assertSame(0, $this->reservationRowCount($email));
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['confirm-ak'], 'The confirming waiter is released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A confirmation moves every OTHER TAB of the browser that made it to the done step.
     *
     * One browser, one registration: the tabs of the session that confirmed were all
     * waiting on the same attempt, so they are moved forward with it rather than told the
     * address is taken. The tab that typed the code is answered by its own action reply
     * and skipped here.
     *
     * What they are NOT is signed in inline: the sign-in rotates the session token
     * (HIL-582), so the other tabs are dropped and come back into the rotated session with
     * the new cookie. The step change is the whole of what this seam owes them.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmConvergesEveryTabOfTheConfirmingBrowser(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'both-first-ak');
        $this->register($agent, 'both-first-ak', $email);
        $this->openSession($agent, 'both-second-ak', $token);
        $this->seedKnownCode($email);

        try {
            $this->drainConvergeSignals();

            ExecutionContext::setCurrentAcceptKey('both-first-ak');
            $this->confirm($agent, 'both-first-ak', $email, self::CODE);

            $userId = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)?->userId;
            $this->assertNotNull($userId);
            $this->assertSame($userId, Hilos::$rt->connections['both-first-ak']->userId);

            $converge = $this->drainConvergeSignals()['both-second-ak'] ?? null;
            $this->assertNotNull($converge, 'The other tab of the confirming browser is told where it goes');
            $this->assertSame(AuthFlowStep::DONE, $converge->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $converge->intent);
            $this->assertNull($converge->code, 'A tab of the winning browser is not told the address is taken');
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['both-second-ak'], 'Converged waiters are released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The browser that lost the race is told the address is taken, and signed into nothing.
     *
     * The capture HIL-608 closes, seen from the losing side. Two browsers were registering
     * one address; the first to prove it gets the account, and the second must be sent back
     * to the identifier field under the sign-in intent - never subscribed into an account
     * it never proved anything about, which is what the address-keyed converge did to
     * whoever happened to be parked.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmTellsTheLosingBrowserTheAddressIsTaken(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'race-winner-ak');
        $this->register($agent, 'race-winner-ak', $email);
        $this->openSession($agent, 'race-loser-ak');
        $this->register($agent, 'race-loser-ak', $email, self::OTHER_PASSWORD);
        $this->seedKnownCode($email);

        try {
            $this->drainConvergeSignals();

            ExecutionContext::setCurrentAcceptKey('race-winner-ak');
            $this->confirm($agent, 'race-winner-ak', $email, self::CODE);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $userId = $identity?->userId;
            $this->assertNotNull($userId);

            // The password that landed is the winner's: the loser's hold carried its own,
            // and nothing of it reaches the account somebody else proved.
            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));

            $this->assertNull(
                Hilos::$rt->connections['race-loser-ak']->userId,
                'The browser that lost the address must not be signed into the winner account',
            );
            $this->assertNull($this->holdOf('race-loser-ak'), 'The losing hold is dropped, not left to expire');

            $converge = $this->drainConvergeSignals()['race-loser-ak'] ?? null;
            $this->assertNotNull($converge, 'The loser is told out loud, not left on a code screen');
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converge->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converge->intent);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $converge->code);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A browser already signed in keeps its own account when it loses the address.
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
            $this->assertSame($email, $this->holdOf('reopened-ak')?->identifier);
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
     * A landing that fails for any other reason leaves no half-made account either.
     *
     * The mint and the identity are one transaction because an account nobody can sign
     * into is worse than no account (Flow p.12), and the lost race is only the failure
     * that was foreseen. The hold seeded below is a `password` one carrying no credential
     * - a shape only a broken row can have - so the landing raises AFTER the user row is
     * inserted, which is exactly the moment the transaction has to end. It is asserted
     * through the row rather than through the connection state on purpose: an unrolled
     * transaction is invisible from the outside but its own uncommitted row is not, and
     * that row is what a later BEGIN would eventually commit.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testALandingThatFailsLeavesNoAccountBehind(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'broken-ak');

        try {
            $this->reservations()->createReservation(IdentityType::PASSWORD, $token, $email, null, self::TTL_SECONDS);
            $this->seedKnownCode($email);

            try {
                $this->confirm($agent, 'broken-ak', $email, self::CODE);
                $this->fail('A hold with no credential to land cannot end in an account');
            } catch (LogicException) {
                // The refusal is the point; what it leaves behind is what is asserted.
            }

            $this->assertSame(
                0,
                EntityUser::count([EntityUser::name => $this->localPart($email)]),
                'The user minted inside the failed landing must be rolled back, not left pending',
            );
            $this->assertNull($this->sessionOf('broken-ak')?->userId, 'And nobody is signed into it');
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
        $expiresAt = $this->holdOf('resend-ak')?->expiresAt;

        try {
            $outcome = $this->resend($agent, 'resend-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertGreaterThan(TimeHelper::nowMs(), (int)$outcome->resendAt, 'The countdown must be reported');
            $this->assertSame($challengeId, $this->activeChallenge($email)?->id, 'No second code inside the cooldown');
            $this->assertSame(
                $expiresAt,
                $this->holdOf('resend-ak')?->expiresAt,
                'A suppressed resend must not push the hold out',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A resend right after the challenge died is still held: the cooldown runs from the send.
     *
     * The rule this case exists for changed with HIL-421. The old throttle asked
     * whether a young challenge was still ALIVE, so voiding it - which anyone can do
     * by burning the attempts - reopened the send immediately. What is rationed is
     * the message that reaches the mailbox, and that one was already delivered.
     *
     * @throws HilosException When setup or resend handling fails
     */
    public function testResendAfterTheChallengeDiedIsStillHeld(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'resend-dead-ak');
        $this->register($agent, 'resend-dead-ak', $email);
        $this->verifications()->voidActive(VerificationType::REGISTER_CONFIRM, $email, $this->maxAttempts());

        try {
            $outcome = $this->resend($agent, 'resend-dead-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertGreaterThan(TimeHelper::nowMs(), (int)$outcome->resendAt, 'The countdown must be reported');
            $this->assertNull($this->activeChallenge($email), 'A dead challenge must not buy a fresh send');
            $this->assertSame(1, $this->sendRowCount($email), 'No second code was minted');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Past the window cap the resend is refused out loud, with no countdown to wait out.
     *
     * The patient caller of the design: it presses once per cooldown forever, which
     * the cooldown alone never stopped. The case walks that caller by ageing the sends
     * out of the cooldown but leaving them inside the window, so the cap is the only
     * rule that can refuse.
     *
     * @throws HilosException When setup or resend handling fails
     */
    public function testTheWindowCapRefusesFurtherSendsOutLoud(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $cap = Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_SEND_CAP);
        $cooldown = Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC);
        $this->assertGreaterThan(
            $cap * ($cooldown + 1),
            Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC),
            'The window must outlast the ageing this case does, or the cap could never be reached',
        );
        $this->openSession($agent, 'resend-cap-ak');
        $this->register($agent, 'resend-cap-ak', $email);

        try {
            for ($sent = 1; $sent < $cap; $sent++) {
                $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
                $this->assertTrue($this->resend($agent, 'resend-cap-ak', $email)->ok, "Send {$sent} is under the cap");
            }
            $this->assertSame($cap, $this->sendRowCount($email), 'The window is full');

            $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
            $outcome = $this->resend($agent, 'resend-cap-ak', $email);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_SEND_CAP_REACHED, $outcome->code);
            $this->assertNull($outcome->step, 'A cap refusal leaves the surface on the code screen');
            $this->assertNull($outcome->resendAt, 'A cap refusal promises no countdown');
            $this->assertSame($cap, $this->sendRowCount($email), 'Nothing is minted past the cap');
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
     * One browser keeps ONE hold: its next address replaces the one before it.
     *
     * The invariant the UNIQUE index carries since HIL-608, exercised at the collection
     * where it lives. Two browsers on one address is no longer a race to settle - both
     * hold it and the first to prove it wins - so what the key protects is the other
     * direction: a browser cannot accumulate registrations, and the surface never has to
     * choose which of its holds a code belongs to.
     *
     * @throws HilosException When setup or the reservation write fails
     */
    public function testOneBrowserKeepsOneHold(): void
    {
        $this->bootAgent();
        $first = $this->uniqueEmail();
        $second = $this->uniqueEmail();
        $token = RandomHelper::hex(16);

        try {
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $token, $first, self::PASSWORD, self::TTL_SECONDS);
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $token, $second, self::OTHER_PASSWORD, self::TTL_SECONDS);

            $this->assertSame(0, $this->reservationRowCount($first), 'The replaced address is no longer held');
            $this->assertSame(
                $second,
                $this->reservations()->findActiveForSession($token)?->identifier,
                'The browser holds its newest address and only that one',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Two browsers may hold one address at once, each with a hold of its own.
     *
     * The other half of the same key, and the reason the capture is closed: the address
     * no longer belongs to whoever submitted first, so a second person may start their own
     * registration on it - and land it only with their own proof.
     *
     * @throws HilosException When setup or the reservation write fails
     */
    public function testTwoBrowsersMayHoldOneAddress(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $mine = RandomHelper::hex(16);
        $theirs = RandomHelper::hex(16);

        try {
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $mine, $email, self::PASSWORD, self::TTL_SECONDS);
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $theirs, $email, self::OTHER_PASSWORD, self::TTL_SECONDS);

            $this->assertSame(2, $this->reservationRowCount($email), 'One browser is one hold, not one address');
            $this->assertSame($email, $this->reservations()->findActiveForSession($mine)?->identifier);
            $this->assertSame($email, $this->reservations()->findActiveForSession($theirs)?->identifier);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Re-holding the address a browser already holds keeps the credential it carries.
     *
     * What "the password does not vanish" is built on (HIL-608, Design p.6): a person who
     * submitted an address with a password and then asked for a sign-in link is re-holding
     * their OWN attempt, and the link's hold carries no credential of its own - so the one
     * already stored has to survive, or proving the address would quietly build an account
     * they cannot sign into with the password they chose.
     *
     * @throws HilosException When setup or the reservation write fails
     */
    public function testReHoldingTheSameAddressKeepsTheCredential(): void
    {
        $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = RandomHelper::hex(16);

        try {
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $token, $email, self::PASSWORD, self::TTL_SECONDS);
            $reHeld = $this->reservations()
                ->createReservation(IdentityType::MAGIC_LINK, $token, $email, null, self::TTL_SECONDS);

            $carried = $reHeld->readSecretHash();
            $this->assertIsString($carried, 'The credential follows the address inside one browser');
            $this->assertTrue(password_verify(self::PASSWORD, $carried));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A session that starts a second registration drops the first, wait and hold (HIL-486).
     *
     * One session runs one flow at a time, so the durable memory holds one row per
     * session and the newer address re-points it. Two rows would leave the handshake
     * choosing which step to hand back, which is a choice nobody could make correctly.
     * Since HIL-608 the HOLD obeys the same key and is evicted with it: it named this
     * browser's attempt, and this browser has started another one.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testASecondRegistrationRepointsTheSessionsWait(): void
    {
        $agent = $this->bootAgent();
        $first = $this->uniqueEmail();
        $second = $this->uniqueEmail();
        $token = $this->openSession($agent, 'repoint-wait-ak');

        try {
            $this->register($agent, 'repoint-wait-ak', $first);
            $this->assertSame($first, $this->waitOf($token));

            $this->register($agent, 'repoint-wait-ak', $second);

            $this->assertSame($second, $this->waitOf($token), 'The session waits on its newest address only');
            $this->assertSame(
                $second,
                $this->holdOf('repoint-wait-ak')?->identifier,
                'One browser holds one registration: the newer address evicts the older',
            );
            $this->assertSame(0, $this->reservationRowCount($first), 'The abandoned attempt leaves no hold behind');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Signing in forgets the registration the browser left unfinished.
     *
     * A person who starts a registration, then remembers an old account and signs into it
     * instead, must land in that account - not back on the code screen of the address they
     * walked away from. Until HIL-612 this held by accident: the wait was keyed by the
     * cookie token, and the sign-in's rotation (HIL-582) orphaned it on a name nothing
     * presented again. The memory travels with the row now, so the release is said out
     * loud, and this case is what says it stayed said.
     *
     * @throws HilosException When setup or the sign-in fails
     */
    public function testSigningInForgetsTheRegistrationTheSessionLeftUnfinished(): void
    {
        $agent = $this->bootAgent();
        $abandoned = $this->uniqueEmail();
        $token = $this->openSession($agent, 'sign-in-wait-ak');

        try {
            $this->register($agent, 'sign-in-wait-ak', $abandoned);
            $this->assertSame($abandoned, $this->waitOf($token), 'The registration opened a code screen');

            // The account the person actually has, signed into instead of finishing.
            $own = Hilos::$db->users->actions->createWithName('own');
            $ownUserId = (int)$own->id;
            Hilos::$db->identities->createPasswordIdentity($ownUserId, $this->uniqueEmail(), self::PASSWORD);

            $agent->authenticateSession($token, $ownUserId, 'sign-in-wait-ak');

            // The sign-in rotated the token (HIL-582): the row is the same one, and it
            // answers to the name the connection was re-pointed onto.
            $liveToken = Hilos::$rt->connections['sign-in-wait-ak']->sessionToken;
            $this->assertNotSame($token, $liveToken, 'The sign-in rotates the token');
            $this->assertSame($ownUserId, Hilos::$db->sessions->findByToken($liveToken)?->userId);
            $this->assertNull(
                $this->waitOf($liveToken),
                'A signed-in browser is not handed back the code screen it walked away from',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * "Not that address?" forgets the wait and leaves this browser's hold standing.
     *
     * The asymmetry is the rule (HIL-415, Flow p.7), and HIL-608 kept it while replacing
     * its reason: the hold is this browser's own now, and it survives because coming back
     * to the same address must land on the same code screen without spending a second
     * letter. It runs out on its own instead.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testAbandonForgetsTheWaitAndKeepsTheHold(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'abandon-ak');

        try {
            $this->register($agent, 'abandon-ak', $email);
            $this->assertSame($email, $this->waitOf($token));

            ExecutionContext::setCurrentAcceptKey('abandon-ak');
            $reply = $this->usersLibrary()->onAgentAction(
                'abandon-ak',
                HilosSignalConstants::HILOS_ABANDON_REGISTRATION,
                new AbandonRegistrationActionDTO(),
            );
            $outcome = $reply ?? $this->deliverLibraryFrames($agent);

            $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertNull($this->waitOf($token), 'The session stops waiting on the address it walked away from');
            $this->assertSame(
                $email,
                $this->holdOf('abandon-ak')?->identifier,
                'The hold stays: the way back to this code screen is built on it',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Empties the signal queue and returns the converge signals it held, by target.
     *
     * Being told where your step goes IS the whole effect of a converge, so the queue is
     * the only place the news can be read. Called once before the act to clear what the
     * setup queued, and again after it to see what the act sent.
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
     * A reconnect after "not that address?" is answered with no step at all.
     *
     * The promise {@see MainPage::handleAbandonRegistration()} makes, and the reason the
     * handshake reads the WAIT and not only the hold (HIL-608). Walking away drops the
     * wait and deliberately keeps the hold - the hold is what puts this browser back on
     * its own code screen when it types the address again - so a hold on its own must
     * not resume a code screen the person just left.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testAReconnectAfterAbandonIsAnsweredWithNoStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'reconnect-abandon-ak');

        try {
            $this->register($agent, 'reconnect-abandon-ak', $email);

            ExecutionContext::setCurrentAcceptKey('reconnect-abandon-ak');
            $this->usersLibrary()->onAgentAction(
                'reconnect-abandon-ak',
                HilosSignalConstants::HILOS_ABANDON_REGISTRATION,
                new AbandonRegistrationActionDTO(),
            );
            $this->deliverLibraryFrames($agent);
            $this->assertNotNull($this->holdOf('reconnect-abandon-ak'), 'The hold is what the lookup answers with');

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'reconnect-abandon-new', $token);

            $this->assertNull(
                $this->drainHandshakeResponses()['reconnect-abandon-new']?->pendingRegistration,
                'A browser that walked away is not put back on the code screen by its own hold',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Empties the signal queue and returns the handshake responses it held, by target.
     *
     * @return array<string, HandshakeResponseSignalData> Handshake payload by target accept key
     */
    private function drainHandshakeResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $envelope = $signal->data instanceof WebSocketSignalData ? $signal->data : null;
            $payload = $envelope?->data;
            if ($payload instanceof HandshakeResponseSignalData) {
                $responses[(string)$envelope?->targetAcceptKey] = $payload;
            }
        }

        return $responses;
    }

    /**
     * Reads the registration hold the browser behind a connection is leading.
     *
     * @param string $acceptKey Accept key whose session is asked about
     * @return ?ObjectRegistrationReservation Live hold, or null when that browser holds none
     * @throws HilosException When the reservation lookup fails
     */
    private function holdOf(string $acceptKey): ?ObjectRegistrationReservation
    {
        return $this->reservations()->findActiveForSession(Hilos::$rt->connections[$acceptKey]->sessionToken);
    }

    /**
     * Reads what one session is waiting on, straight from the durable memory.
     *
     * @param string $sessionToken Session token to ask about
     * @return ?string Identifier the session is waiting on, or null when it waits on nothing
     * @throws HilosException When the session lookup fails
     */
    private function waitOf(string $sessionToken): ?string
    {
        return Hilos::$db->sessions->findByToken($sessionToken)?->pendingRegistrationIdentifier;
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
        RtTruthSourceRegistry::register(StateRegistrationWaiter::RT_COLLECTION, true, self::TEST_AGENT_ID);
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
     * @param ?string $sessionToken Token to reuse, opening a second tab of one browser, or null for a new browser
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
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email, $password),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
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
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM,
            new RequestRegisterConfirmActionDTO($email),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
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
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER,
            new ConfirmRegisterActionDTO($email, $code),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
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
     * Ages every code sent to an address back, so the cooldown reads as elapsed.
     *
     * The counting window is left intact on purpose: the shift is small enough that
     * the aged sends still fall inside it, which is what lets a case reach the cap
     * without waiting out a real cooldown. The in-memory objects are dropped after
     * the write, or the collection would answer the send gate off the rows it
     * hydrated before it.
     *
     * @param string $email Address the registration holds
     * @param int $seconds How far back to move each send
     * @throws HilosException When the update query fails
     */
    private function ageSendsOutOfTheCooldown(string $email, int $seconds): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($seconds));
        $params->add(SqlParam::string(VerificationType::REGISTER_CONFIRM));
        $params->add(SqlParam::string($email));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `'
            . EntityUserVerification::created_at . '` = DATE_SUB(`'
            . EntityUserVerification::created_at . '`, INTERVAL ? SECOND) WHERE `'
            . EntityUserVerification::type . '` = ? AND `'
            . EntityUserVerification::identifier . '` = ?',
            $params,
        );
        $this->verifications()->clearInMemory();
    }

    /**
     * Counts the codes ever sent to an address, dead ones included.
     *
     * @param string $email Address codes were sent to
     * @return int Number of challenge rows
     * @throws HilosException When the count query fails
     */
    private function sendRowCount(string $email): int
    {
        return EntityUserVerification::count([
            EntityUserVerification::type => VerificationType::REGISTER_CONFIRM,
            EntityUserVerification::identifier => $email,
        ]);
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
