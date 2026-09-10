<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\AbandonRegistrationActionDTO;
use Hilos\Auth\Library\DTO\CompleteRegistrationActionDTO;
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
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\TruthSource\TruthSourceKeys;
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
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
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
            $outcome = $this->register($agent, 'conv-second-ak', $email);

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
     * A password under the policy length is refused at the save, which is where it is asked.
     *
     * The check moved here with the field (HIL-825): the submit that holds the address
     * carries no password at all, so there was nothing to measure a step earlier. What a
     * refusal must not do is undo the proof - the person is standing on the password
     * screen and gets to try again, so the hold and its mark stay exactly as they were.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testSavingRefusesAShortPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'short-pw-ak');
        $this->register($agent, 'short-pw-ak', $email);
        $this->seedKnownCode($email);
        $this->confirm($agent, 'short-pw-ak', $email, self::CODE);

        try {
            $refused = false;
            try {
                $this->complete($agent, 'short-pw-ak', str_repeat('a', PasswordPolicy::MIN_LENGTH - 1));
            } catch (ValidationException $exception) {
                $refused = true;
                $this->assertStringContainsString((string)PasswordPolicy::MIN_LENGTH, $exception->getMessage());
            }

            $this->assertTrue($refused, 'A short password must be refused');
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'A refused save creates no account',
            );
            $this->assertTrue(
                $this->holdOf('short-pw-ak')?->isProven() ?? false,
                'And costs nothing already proved: the person tries again on the same screen',
            );
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
     * The right code proves the address and opens the password step, creating nothing.
     *
     * The decision this leaf is (HIL-825): an account made here would stand between the
     * code and a password with a proven address and no way to sign in, and in an
     * installation whose only registration method is a password the surface would offer
     * it no control at all. So the code buys a mark on the hold and a screen, and the
     * account is born on the next submit.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmProvesTheAddressAndOpensThePasswordStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'confirm-ak');
        $this->register($agent, 'confirm-ak', $email);
        $this->seedKnownCode($email);
        $heldUntil = $this->holdOf('confirm-ak')?->expiresAt;

        try {
            $outcome = $this->confirm($agent, 'confirm-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'The code creates no account: there would be no way into it',
            );
            $this->assertNull($this->sessionOf('confirm-ak')?->userId, 'And signs nobody in');

            $hold = $this->holdOf('confirm-ak');
            $this->assertNotNull($hold, 'The hold is what the proof is written on');
            $this->assertTrue($hold->isProven(), 'The proof outlives the code, which is now spent');
            $this->assertGreaterThan(
                (string)$heldUntil,
                $hold->expiresAt,
                'From here the hold keeps the address while a password is chosen',
            );
            $this->assertNull($this->activeChallenge($email), 'The code is single-use, proof or no proof');
            $this->assertNotNull(
                Hilos::$rt->hilosRegistrationWaiters['confirm-ak'],
                'The wait is not over: the converge still has to reach this browser',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The saved password creates the verified account, announces it, and signs the session in.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testSavingThePasswordCreatesTheVerifiedAccountAndSignsTheSessionIn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'complete-ak');
        $this->register($agent, 'complete-ak', $email);
        $this->seedKnownCode($email);
        $this->confirm($agent, 'complete-ak', $email, self::CODE);

        try {
            $outcome = $this->complete($agent, 'complete-ak');

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity, 'The saved password must create the identity');
            $this->assertTrue($identity->verified, 'The code that came before it is the proof of ownership');

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($this->localPart($email), Hilos::$db->users[$userId]?->name);

            // The plaintext came in with this very submit and was hashed into the identity
            // here, so no hash for an account that did not exist was ever stored anywhere.
            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));

            $this->assertSame($userId, $this->sessionOf('complete-ak')?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['complete-ak']->userId);
            $this->assertNull($this->holdOf('complete-ak'), 'The hold is released on success');
            $this->assertSame(0, $this->reservationRowCount($email));
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['complete-ak'], 'The saving waiter is released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A save with no proved hold behind it rolls the surface back to the address field.
     *
     * Not the person's mistake, so not an error to retype: the hold ran out while they
     * were thinking of a password, or this browser never had one. The address field under
     * the register intent is the only honest place left (HIL-825).
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testSavingWithoutAProvedHoldRollsBack(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'unproved-ak');
        $this->register($agent, 'unproved-ak', $email);

        try {
            $outcome = $this->complete($agent, 'unproved-ak');

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'A hold that was never proved buys no account',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address that becomes somebody's while a password is being chosen sends to sign-in.
     *
     * The same question the submit and the code both asked, asked once more at the last
     * moment it can matter: the hold keeps a second REGISTRATION off the address, not an
     * account arriving by another road while the person was typing.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testSavingOnAnAddressTakenMeanwhileAnswersIdentifierTaken(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'taken-late-ak');
        $this->register($agent, 'taken-late-ak', $email);
        $this->seedKnownCode($email);
        $this->confirm($agent, 'taken-late-ak', $email, self::CODE);

        $elsewhere = Hilos::$db->users->actions->createWithName('elsewhere');
        Hilos::$db->identities->createMagicLinkIdentity((int)$elsewhere->id, $email);

        try {
            $outcome = $this->complete($agent, 'taken-late-ak');

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'No second account is built for an address that already has one',
            );
            $this->assertNull($this->sessionOf('taken-late-ak')?->userId, 'Nobody is signed in');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Every OTHER TAB of the browser follows it twice: onto the password step, then to done.
     *
     * One browser, one registration: the tabs of the session that answered the code were
     * all waiting on the same attempt, so they move forward with it rather than being told
     * the address is taken. The tab that acted is answered by its own action reply and
     * skipped here. Two moves since HIL-825, because the flow has two endings to share -
     * without the first one, a second window would still be asking for a code that has
     * already been spent.
     *
     * What they are NOT is signed in inline: the sign-in rotates the session token
     * (HIL-582), so the other tabs are dropped and come back into the rotated session with
     * the new cookie. The step change is the whole of what this seam owes them.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testEveryTabOfTheRegisteringBrowserFollowsItToBothSteps(): void
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

            $proved = $this->drainConvergeSignals()['both-second-ak'] ?? null;
            $this->assertNotNull($proved, 'The other tab is moved off the code screen with its sibling');
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $proved->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $proved->intent);
            $this->assertNull($proved->code, 'A tab of this browser is not told the address is taken');
            $this->assertNotNull(
                Hilos::$rt->hilosRegistrationWaiters['both-second-ak'],
                'It stays parked: the wait is over only when the account exists',
            );

            $this->complete($agent, 'both-first-ak');

            $userId = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email)?->userId;
            $this->assertNotNull($userId);
            $this->assertSame($userId, Hilos::$rt->connections['both-first-ak']->userId);

            $landed = $this->drainConvergeSignals()['both-second-ak'] ?? null;
            $this->assertNotNull($landed, 'And is told where it goes when the account is made');
            $this->assertSame(AuthFlowStep::DONE, $landed->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $landed->intent);
            $this->assertNull($landed->code);
            $this->assertNull(Hilos::$rt->hilosRegistrationWaiters['both-second-ak'], 'Converged waiters are released');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The race is settled by the SAVE, and the loser is told the address is taken.
     *
     * The capture HIL-608 closes, seen from the losing side, and the moment it is settled
     * moved with the account (HIL-825): proving an address takes nothing from anybody,
     * because the address is still nobody's until a password is saved. So both browsers
     * may reach the password screen, the first to save wins, and the second is sent back to
     * the identifier field under the sign-in intent - never subscribed into an account it
     * proved nothing about, which is what the address-keyed converge did to whoever
     * happened to be parked.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testTheSaveSettlesTheRaceAndTellsTheLoserTheAddressIsTaken(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'race-winner-ak');
        $this->register($agent, 'race-winner-ak', $email);
        $this->openSession($agent, 'race-loser-ak');
        $this->register($agent, 'race-loser-ak', $email);
        $this->seedKnownCode($email);

        try {
            $this->drainConvergeSignals();

            // Both browsers answer the same letter, one code each: the challenge is per
            // address, so the loser re-seeds its own to reach the password screen too.
            $this->confirm($agent, 'race-winner-ak', $email, self::CODE);
            $this->seedKnownCode($email);
            $this->confirm($agent, 'race-loser-ak', $email, self::CODE);

            $this->assertTrue($this->holdOf('race-loser-ak')?->isProven() ?? false, 'Proving takes nothing away');
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'Two proved holds and still no account: the address is nobody\'s until it is saved',
            );

            $this->drainConvergeSignals();
            $this->complete($agent, 'race-winner-ak');

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $userId = $identity?->userId;
            $this->assertNotNull($userId);

            // The password that landed is the winner's: it came in with the winner's save,
            // and the loser's own is still unsent.
            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));

            $this->assertNull(
                Hilos::$rt->connections['race-loser-ak']->userId,
                'The browser that lost the address must not be signed into the winner account',
            );
            $this->assertNull($this->holdOf('race-loser-ak'), 'The losing hold is dropped, not left to expire');

            $converge = $this->drainConvergeSignals()['race-loser-ak'] ?? null;
            $this->assertNotNull($converge, 'The loser is told out loud, not left on a password screen');
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converge->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converge->intent);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $converge->code);

            $late = $this->complete($agent, 'race-loser-ak', self::OTHER_PASSWORD);
            $this->assertFalse($late->ok, 'And saving anyway buys nothing');
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $late->code);
            $this->assertTrue(
                password_verify(self::PASSWORD, (string)$this->readIdentitySecret($email)),
                'Nothing of the loser reaches the account somebody else made',
            );
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
        $this->authenticateSession($agent, 
            Hilos::$rt->connections['signed-in-ak']->sessionToken,
            $ownUserId,
            'signed-in-ak',
        );

        $this->openSession($agent, 'confirmer-ak');
        $this->register($agent, 'confirmer-ak', $email);
        $this->seedKnownCode($email);

        try {
            $this->confirm($agent, 'confirmer-ak', $email, self::CODE);
            $this->complete($agent, 'confirmer-ak');

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

            // The sweep is the sessions library's own tick rule since HIL-710, not a cron
            // name this demo schedules; the rollback it produces reaches the tabs on the
            // frames that follow it.
            $this->sessionsLibrary()->onTick();
            $this->deliverLibraryFrames($agent);

            $this->assertSame(0, $this->reservationRowCount($email), 'The expired hold is deleted');
            $this->assertNull(
                Hilos::$rt->hilosRegistrationWaiters['expired-ak'],
                'A rolled-back waiter is released, not left parked on a hold that is gone',
            );

            // The frame names the expired-code screen and not the address field (HIL-828):
            // a browser that flipped there on its own countdown is told what it already
            // shows, instead of being swept off it a minute later, button and all.
            $converge = $this->drainConvergeSignals()['expired-ak'] ?? null;
            $this->assertNotNull($converge, 'The browser waiting on the hold is told');
            $this->assertSame(AuthFlowStep::CODE_EXPIRED, $converge->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $converge->intent);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $converge->code);

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
     * The button on the expired screen takes the same address again, in the same session.
     *
     * What the new-code button dispatches is the flow's FIRST send (HIL-828), because the
     * hold died with the code and a re-send has nothing to top up. So the proof owed here
     * is that a register submit from the session that just lost its hold mints a new one
     * and a new code - and that the row it replaces cannot come back to haunt it, the
     * insert releasing this session's standing row before writing its own.
     *
     * @throws HilosException When setup or register handling fails
     */
    public function testTakingTheAddressAgainAfterTheHoldDiedMintsANewCode(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'renew-ak');
        $this->register($agent, 'renew-ak', $email);

        $cooldown = Hilos::$env[EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC]->int();

        try {
            $this->ageReservationOut($email);
            $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);

            $outcome = $this->register($agent, 'renew-ak', $email);

            $this->assertTrue($outcome->ok, 'The address is taken again rather than refused');
            $this->assertSame(AuthFlowStep::CODE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);
            $this->assertNotNull($outcome->expiresAt, 'The code screen comes back with a life on it');
            $this->assertSame($email, $this->holdOf('renew-ak')?->identifier);
            $this->assertSame(1, $this->reservationRowCount($email), 'The dead row is replaced, not added to');
            $this->assertSame(2, $this->sendRowCount($email), 'A second letter really goes out');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A confirm against an expired hold says the code is dead instead of blaming it.
     *
     * The screen the person is standing on becomes the one that offers a new code
     * (HIL-828), rather than the address field they never asked to go back to.
     *
     * @throws HilosException When setup or confirm handling fails
     */
    public function testConfirmOnAnExpiredReservationMovesToTheExpiredCodeScreen(): void
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
            $this->assertSame(AuthFlowStep::CODE_EXPIRED, $outcome->step);
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
     * A refused save leaves no half-made account behind.
     *
     * The mint and the identity are one transaction because an account nobody can sign
     * into is worse than no account (Flow p.12). Until HIL-825 this case forced the
     * transaction open by seeding a `password` hold that carried no credential - a shape
     * only a broken row could have - and letting the landing raise after the user row was
     * inserted. That shape stopped existing with the credential itself: the password now
     * arrives WITH the landing, so every failure the surface can still reach is answered
     * by a guard in front of the transaction rather than inside it. What is left to pin is
     * the guarantee itself, asserted through the row rather than the connection state: an
     * unrolled transaction is invisible from the outside, but the user row it would have
     * left is not.
     *
     * @throws HilosException When setup or the handling fails
     */
    public function testARefusedSaveLeavesNoAccountBehind(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'broken-ak');
        $this->register($agent, 'broken-ak', $email);
        $this->seedKnownCode($email);
        $this->confirm($agent, 'broken-ak', $email, self::CODE);

        $elsewhere = Hilos::$db->users->actions->createWithName('elsewhere');
        Hilos::$db->identities->createMagicLinkIdentity((int)$elsewhere->id, $email);

        try {
            $this->assertFalse($this->complete($agent, 'broken-ak')->ok);

            $this->assertSame(
                0,
                EntityUser::count([EntityUser::name => $this->localPart($email)]),
                'A refused save must leave no user row at all, not even an uncommitted one',
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
        $cap = Hilos::$env[EnvConstants::HILOS_VERIFICATION_SEND_CAP]->int();
        $cooldown = Hilos::$env[EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC]->int();
        $this->assertGreaterThan(
            $cap * ($cooldown + 1),
            Hilos::$env[EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC]->int(),
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
     * A resend for an address nobody holds says the code is dead instead of issuing one.
     *
     * There is nothing to re-send into: the hold died with the code, so the honest screen
     * is the one that offers to take the address again (HIL-828).
     *
     * @throws HilosException When setup or resend handling fails
     */
    public function testResendWithoutAReservationMovesToTheExpiredCodeScreen(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'resend-gone-ak');

        try {
            $outcome = $this->resend($agent, 'resend-gone-ak', $email);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::CODE_EXPIRED, $outcome->step);
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
                ->createReservation(IdentityType::PASSWORD, $token, $first, self::TTL_SECONDS);
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $token, $second, self::TTL_SECONDS);

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
                ->createReservation(IdentityType::PASSWORD, $mine, $email, self::TTL_SECONDS);
            $this->reservations()
                ->createReservation(IdentityType::PASSWORD, $theirs, $email, self::TTL_SECONDS);

            $this->assertSame(2, $this->reservationRowCount($email), 'One browser is one hold, not one address');
            $this->assertSame($email, $this->reservations()->findActiveForSession($mine)?->identifier);
            $this->assertSame($email, $this->reservations()->findActiveForSession($theirs)?->identifier);
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

            $this->authenticateSession($agent, $token, $ownUserId, 'sign-in-wait-ak');

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
                $this->drainHandshakeResponses()['reconnect-abandon-new']?->pendingAuthStep,
                'A browser that walked away is not put back on the code screen by its own hold',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A tab opened on an unfinished registration is handed the step that hold stands on.
     *
     * The rails are HIL-648's and the choice is this leaf's (HIL-825): what a returning
     * browser is owed is decided by the hold, not by where any tab happens to stand, and
     * the hold knows both - it names the address and says whether its code has come back.
     * An unproved one owes the code screen, a proved one owes the password screen, and
     * sending a proved registration back to the code would ask for something already spent.
     *
     * @throws HilosException When setup or the handshake fails
     */
    public function testAReconnectIsHandedTheStepItsHoldStandsOn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'resume-code-ak');

        try {
            $this->register($agent, 'resume-code-ak', $email);

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'resume-code-new', $token);
            $waiting = $this->drainHandshakeResponses()['resume-code-new']?->pendingAuthStep;
            $this->assertNotNull($waiting, 'A live registration is owed its step');
            $this->assertSame(AuthFlowStep::CODE, $waiting[HandshakeResponseSignalData::step]);
            $this->assertSame(AuthFlowIntent::REGISTER, $waiting[HandshakeResponseSignalData::intent]);
            $this->assertSame($email, $waiting[HandshakeResponseSignalData::identifier]);

            $this->seedKnownCode($email);
            $this->confirm($agent, 'resume-code-ak', $email, self::CODE);

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'resume-password-new', $token);
            $proved = $this->drainHandshakeResponses()['resume-password-new']?->pendingAuthStep;
            $this->assertNotNull($proved, 'And a proved one still is');
            $this->assertSame(AuthFlowStep::SET_PASSWORD, $proved[HandshakeResponseSignalData::step]);
            $this->assertSame(AuthFlowIntent::REGISTER, $proved[HandshakeResponseSignalData::intent]);
            $this->assertSame($email, $proved[HandshakeResponseSignalData::identifier]);
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
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateRegistrationWaiter::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        // The send-progress line is the sessions library's too (HIL-826), and this fixture
        // stands in for it: the wait being let go takes the line with it, and a writer with
        // no claim is refused whether or not there is a row to take.
        RtTruthSourceRegistry::register(StateHilosCodeSendAttempt::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
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
     * Dispatches a register action through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the register handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): AuthFlowOutcome
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email),
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
     * Dispatches the password save that creates the account, for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $password Password the account is created with
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the complete handler rejects the action
     */
    private function complete(
        ChatAgent $agent,
        string $acceptKey,
        string $password = self::PASSWORD,
    ): AuthFlowOutcome {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION,
            new CompleteRegistrationActionDTO($password),
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
        return max(1, Hilos::$env[EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS]->int());
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
