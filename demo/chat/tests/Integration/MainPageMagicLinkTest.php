<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\ConfirmMagicLinkCodeActionDTO;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Profile\ConfirmAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestAddPasswordActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
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
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

/**
 * Integration tests for the magic link that both signs in and registers (HIL-417):
 * the send holds a free address without mailing a registration code, and the click
 * decides who is signed in from the state the address is in at that moment - an
 * existing account, a live hold finishing its registration, or neither.
 *
 * The same letter also carries a typed code (HIL-606), which is a challenge of its own
 * with its own attempt ceiling: the cases below hold both halves to one rule - either
 * one signs the same person in, either one kills the other on success, and neither is
 * spent by the other's failures.
 *
 * The secrets are only mailed (the dev-stub deliverer logs them), so every answering
 * case seeds a challenge with a secret it knows through the verifications object
 * collection - the same level HIL-415 and HIL-406 test their code flows at. Seeding
 * AFTER the send is deliberate: findActive() answers the newest challenge, so the
 * seeded secret is the live one.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageMagicLinkTest extends IntegrationTestCase
{
    /**
     * How far a moment answered by the backend may sit from the one this case computes, in ms.
     *
     * Generous on purpose. A case reads the clock on ONE side of the request and compares the
     * moment the backend answered on the other, so the tolerance has to cover a whole send -
     * two bcrypt hashes since the letter grew a companion code (HIL-606), plus the queries
     * around them - on a box that may be running another suite in the next lane. At one second
     * this went red on a parallel run while passing alone, which is a flake and not a finding.
     *
     * What the assertions are for survives the width: they read a moment one cooldown (60s) or
     * one lifetime (900s) away, so a wrong rule misses by tens of seconds, not by five.
     */
    private const int MOMENT_DELTA_MS = 5000;

    private const string TEST_AGENT_ID = 'test-agent';
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string WRONG_TOKEN = 'ffffffffffffffffffffffffffffffff';
    private const string CODE = '135790';
    private const string WRONG_CODE = '000001';
    private const string PASSWORD = 'correct horse battery';
    private const string PROFILE_PASSWORD = 'a-brand-new-secret';
    private const string EMAIL_ADD_CODE = '424242';
    private const int TTL_SECONDS = 900;

    /**
     * A link asked for a free address holds it, and mails no registration code.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testSendHoldsAFreeAddressWithoutARegistrationCode(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'hold-ak');

        try {
            $outcome = $this->requestLink($agent, 'hold-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertNull($outcome->step, 'A send moves the surface nowhere by itself');

            $reservation = $this->holdOf('hold-ak');
            $this->assertNotNull($reservation, 'A free address is held for the life of the link');
            $this->assertSame($email, $reservation->identifier);
            $this->assertSame(IdentityType::MAGIC_LINK, $reservation->type);

            $this->assertNotNull($this->activeChallenge(VerificationType::MAGIC_LINK, $email));
            $this->assertNull(
                $this->activeChallenge(VerificationType::REGISTER_CONFIRM, $email),
                'The link is the letter - no registration code goes with it',
            );
            $this->assertNull(Hilos::$rt->connections['hold-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address that already has an account is not held: there is nothing to reserve.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testSendToATakenAddressHoldsNothing(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'taken-send-ak');

        try {
            $outcome = $this->requestLink($agent, 'taken-send-ak', $email);

            $this->assertTrue($outcome->ok);
            $this->assertSame(0, $this->reservationRowCount($email));
            $this->assertNotNull($this->activeChallenge(VerificationType::MAGIC_LINK, $email));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The countdown a link's screen draws says the same thing about both addresses.
     *
     * The one property that matters about this moment (HIL-486): the send screen is
     * worded to reveal nothing about whether an account exists, and a countdown that
     * appeared for a stranger and not for a member - or ran differently for them -
     * would undo that wording with a number. The challenge is issued on both sides of
     * the question, so the answer is the challenge's own life either way.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testTheLinkLifetimeIsAnsweredAlikeToAStrangerAndAMember(): void
    {
        $agent = $this->bootAgent();
        $stranger = $this->uniqueEmail();
        $member = $this->uniqueEmail();
        $this->seedPasswordlessAccount($member);
        $this->openSession($agent, 'lifetime-ak');

        try {
            $ttl = Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_TTL_SEC);
            $expected = TimeHelper::nowMs() + $ttl * TimeConstants::MS_PER_SECOND;

            $toStranger = $this->requestLink($agent, 'lifetime-ak', $stranger);
            $toMember = $this->requestLink($agent, 'lifetime-ak', $member);

            $this->assertEqualsWithDelta(
                $expected,
                (int)$toStranger->expiresAt,
                self::MOMENT_DELTA_MS,
                'A stranger is told how long the letter is good for',
            );
            $this->assertEqualsWithDelta(
                $expected,
                (int)$toMember->expiresAt,
                self::MOMENT_DELTA_MS,
                'And a member is told exactly the same',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A clicked link on a free address builds the account and signs the session in.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testClickOnAFreeAddressRegistersAndSignsIn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'register-ak');
        $this->requestLink($agent, 'register-ak', $email);
        $this->seedKnownToken($email);

        try {
            $outcome = $this->clickLink($agent, 'register-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            $this->assertNotNull($identity, 'The landed hold writes the magic-link identity');
            $this->assertTrue($identity->verified, 'Clicking the link is the proof of ownership');

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($this->localPart($email), Hilos::$db->users[$userId]?->name);
            $this->assertSame(1, $this->registrationEventCount($userId), 'The new member is announced');

            $this->assertSame($userId, $this->sessionOf('register-ak')?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['register-ak']->userId);
            $this->assertSame(0, $this->reservationRowCount($email), 'The hold is released on success');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A clicked link on an address that has an account signs it in and creates nothing.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testClickOnATakenAddressSignsInWithoutCreatingAnAccount(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'login-ak');
        $this->requestLink($agent, 'login-ak', $email);
        $this->seedKnownToken($email);

        try {
            $outcome = $this->clickLink($agent, 'login-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);

            $this->assertSame($userId, $this->sessionOf('login-ak')?->userId);
            $this->assertSame(1, $this->identityRowCount($email), 'No second identity is written for a sign-in');
            $this->assertSame(0, $this->registrationEventCount($userId), 'Nobody registered here');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An account that appeared while the letter travelled is signed in, and the hold drops.
     *
     * The gap the ownerless token exists for: the address was free when the link was
     * sent, and an OAuth sign-up landed a verified identity on it before the click.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testAccountAppearingOverALiveHoldSignsInAndDropsTheHold(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'raced-ak');
        $this->requestLink($agent, 'raced-ak', $email);
        $this->assertNotNull($this->holdOf('raced-ak'));
        $userId = $this->seedPasswordlessAccount($email);
        $this->seedKnownToken($email);

        try {
            $outcome = $this->clickLink($agent, 'raced-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertSame($userId, $this->sessionOf('raced-ak')?->userId);
            $this->assertSame(0, $this->reservationRowCount($email), 'A resolved address is held by nobody');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A link that outlived its hold still registers: the letter is the proof, not the hold.
     *
     * The answer HIL-608 removed, and the reason it removed it. "Your registration
     * expired" was untrue about a letter that still opens: the person proved the inbox,
     * and the only thing the missing hold takes from them is a credential they never
     * chose. So they get the account with the mailed sign-in, exactly as a reader who
     * opened the link on a browser that reserved nothing does.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testLinkThatOutlivedItsHoldStillRegisters(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'expired-ak');
        $this->requestLink($agent, 'expired-ak', $email);
        $this->seedKnownToken($email);
        $this->ageReservationOut($email);

        try {
            $outcome = $this->clickLink($agent, 'expired-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            $this->assertNotNull($identity, 'The proven address earns the identity its letter names');
            $this->assertTrue($identity->verified);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'A reader with no hold of their own inherits nobody password',
            );
            $this->assertNotNull(Hilos::$rt->connections['expired-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A token that opens nothing - wrong, or already spent - answers with its own code.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testUnusableTokenAnswersMagicLinkInvalid(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'invalid-ak');
        $this->requestLink($agent, 'invalid-ak', $email);
        $this->seedKnownToken($email);

        try {
            $wrong = $this->clickLink($agent, 'invalid-ak', $email, self::WRONG_TOKEN);

            $this->assertFalse($wrong->ok);
            $this->assertSame(AuthFlowOutcome::CODE_MAGIC_LINK_INVALID, $wrong->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $wrong->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $wrong->intent);
            $this->assertNull(Hilos::$rt->connections['invalid-ak']->userId);

            $this->assertTrue($this->clickLink($agent, 'invalid-ak', $email, self::TOKEN)->ok);
            $reused = $this->clickLink($agent, 'invalid-ak', $email, self::TOKEN);

            $this->assertFalse($reused->ok, 'A link is single-use');
            $this->assertSame(AuthFlowOutcome::CODE_MAGIC_LINK_INVALID, $reused->code);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The answer to a send is the send gate's own: the real cooldown, then a refusal.
     *
     * The blindness this replaces (HIL-421) had the resend button answer "sent" over a
     * send the cap had refused; the number it hid leaks nothing now that the letter
     * goes to free addresses too.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testSendAnswersTheCooldownHonestlyAndRefusesAtTheCap(): void
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
        $this->openSession($agent, 'gate-ak');

        try {
            $this->assertEqualsWithDelta(
                TimeHelper::nowMs() + $cooldown * TimeConstants::MS_PER_SECOND,
                (int)$this->requestLink($agent, 'gate-ak', $email)->resendAt,
                self::MOMENT_DELTA_MS,
                'A fresh send answers the moment the whole cooldown runs out',
            );

            $held = $this->requestLink($agent, 'gate-ak', $email);
            $this->assertTrue($held->ok, 'A repeat inside the cooldown is not an error');
            $this->assertGreaterThan(TimeHelper::nowMs(), (int)$held->resendAt, 'It answers the moment still to wait for');
            $this->assertSame(1, $this->challengeRowCount(VerificationType::MAGIC_LINK, $email), 'And nothing is mailed for it');

            for ($sent = 1; $sent < $cap; $sent++) {
                $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
                $this->assertTrue($this->requestLink($agent, 'gate-ak', $email)->ok, "Send {$sent} is under the cap");
            }
            $this->assertSame($cap, $this->challengeRowCount(VerificationType::MAGIC_LINK, $email), 'The window is full');

            $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
            $refused = $this->requestLink($agent, 'gate-ak', $email);

            $this->assertFalse($refused->ok);
            $this->assertSame(AuthFlowOutcome::CODE_SEND_CAP_REACHED, $refused->code);
            $this->assertNull($refused->step, 'A cap refusal leaves the surface where it is');
            $this->assertSame($cap, $this->challengeRowCount(VerificationType::MAGIC_LINK, $email), 'Nothing is minted past the cap');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A password hold landed by a link keeps the password of whoever reserved first.
     *
     * The hold is landed BY ITS TYPE, so the road the proof came back on does not
     * change what the account gets: a registration that started with a password and
     * finished with a link ends with that password.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testPasswordHoldConfirmedByALinkKeepsTheFirstPassword(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'password-hold-ak');
        $this->register($agent, 'password-hold-ak', $email);
        $this->requestLink($agent, 'password-hold-ak', $email);
        $this->seedKnownToken($email);

        try {
            $outcome = $this->clickLink($agent, 'password-hold-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity, 'A password hold lands as a password identity');
            $this->assertTrue($identity->verified);
            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email),
                'The type of the hold decides, not the road the proof took',
            );

            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PASSWORD, $storedHash));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A letter answered in another browser lands nothing of the browser that reserved.
     *
     * The capture HIL-608 closes, end to end (Design p.2). Session A submits an address it
     * does not own with a password of its own choosing; the letter goes to the inbox, so it
     * reaches the OWNER of the address, who answers it in their own browser. What they must
     * get is an account of their own with no password at all - A's credential belongs to A's
     * attempt and cannot ride somebody else's proof into somebody else's account. A is told
     * the address is taken, which by then it truthfully is.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testAStrangersHoldIsNotLandedByTheOwnersLetter(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'attacker-ak');
        $this->register($agent, 'attacker-ak', $email);

        $this->openSession($agent, 'owner-ak');
        $this->requestLink($agent, 'owner-ak', $email);
        $this->seedKnownToken($email);

        try {
            $this->drainConvergeSignals();

            $outcome = $this->clickLink($agent, 'owner-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email),
                'The stranger password must not become a way into the account its owner just made',
            );
            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            $this->assertNotNull($identity, 'The address is proven, so it earns the identity of the letter');
            $this->assertSame(Hilos::$rt->connections['owner-ak']->userId, $identity->userId);

            $this->assertNull(
                Hilos::$rt->connections['attacker-ak']->userId,
                'The browser that reserved the address is signed into nothing',
            );
            $this->assertNull($this->holdOf('attacker-ak'), 'And keeps no hold on an address that now has an account');

            $converge = $this->drainConvergeSignals()['attacker-ak'] ?? null;
            $this->assertNotNull($converge, 'It is told the address is taken');
            $this->assertSame(AuthFlowStep::IDENTIFIER, $converge->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $converge->intent);
            $this->assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $converge->code);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An address held by an unverified password identity is handed to whoever proves it.
     *
     * The third consequence of P-099, closed honestly (Flow p.11). Such an address used to
     * fall between the two definitions of "taken": the magic-link send saw it free and the
     * click built a SECOND account for the same person. Refusing would be a dead end -
     * whoever proved the inbox does not know the password - so the account they cannot sign
     * into by password becomes theirs by letter, and the identity that was waiting for a
     * confirmation gets one.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testAnAddressHeldByAnUnverifiedPasswordIsHandedToTheProver(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = (int)Hilos::$db->users->actions->createWithName($this->localPart($email))->id;
        Hilos::$db->identities->createPasswordIdentity($userId, $email, self::PASSWORD);
        $this->openSession($agent, 'unverified-ak');
        $this->requestLink($agent, 'unverified-ak', $email);
        $this->seedKnownToken($email);

        try {
            $outcome = $this->clickLink($agent, 'unverified-ak', $email, self::TOKEN);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertSame($userId, $this->sessionOf('unverified-ak')?->userId, 'No second account for one person');

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity);
            $this->assertTrue($identity->verified, 'Answering the mail is the proof the identity was waiting for');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An account built by a link can add a password later, through the profile flow.
     *
     * No new behavior is claimed here - the add-password flow (HIL-406) is unchanged.
     * What is worth a case is that a passwordless account is a first-class one and
     * not a half-registration the profile refuses to complete.
     *
     * @throws HilosException When setup, the confirm, or the profile handling fails
     */
    public function testPasswordlessAccountAddsAPasswordFromTheProfile(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'profile-ak');
        $this->requestLink($agent, 'profile-ak', $email);
        $this->seedKnownToken($email);

        try {
            $this->assertTrue($this->clickLink($agent, 'profile-ak', $email, self::TOKEN)->ok);
            $userId = Hilos::$rt->connections['profile-ak']->userId;
            $this->assertNotNull($userId);
            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email));

            ExecutionContext::setCurrentAcceptKey('profile-ak');
            $this->usersLibrary()->onAgentAction(
                'profile-ak',
                ChatSignalConstants::ADD_PASSWORD_REQUEST,
                new RequestAddPasswordActionDTO($email),
            );
            $this->seedKnownEmailAddCode($email, $userId);
            $this->usersLibrary()->onAgentAction(
                'profile-ak',
                ChatSignalConstants::ADD_PASSWORD_CONFIRM,
                new ConfirmAddPasswordActionDTO($email, self::EMAIL_ADD_CODE, self::PROFILE_PASSWORD),
            );

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity, 'The profile writes the password onto the same account');
            $this->assertSame($userId, $identity->userId);

            $storedHash = $this->readIdentitySecret($email);
            $this->assertIsString($storedHash);
            $this->assertTrue(password_verify(self::PROFILE_PASSWORD, $storedHash));
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * One send mints BOTH halves of the letter, and one gate governs the pair.
     *
     * The property the whole design rests on (Design p.2): the code is a companion minted
     * inside the link's own issue, so a person gets one letter with two ways back. A
     * companion with a gate of its own would have let the second half be refused while the
     * first went out - a letter promising a code it never carried.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testOneSendMintsBothHalvesUnderOneGate(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'pair-ak');

        try {
            $this->requestLink($agent, 'pair-ak', $email);

            $this->assertNotNull($this->activeChallenge(VerificationType::MAGIC_LINK, $email));
            $this->assertNotNull(
                $this->activeChallenge(VerificationType::MAGIC_LINK_CODE, $email),
                'The letter carries a code as well as a link',
            );

            $held = $this->requestLink($agent, 'pair-ak', $email);

            $this->assertTrue($held->ok, 'A repeat inside the cooldown is not an error');
            $this->assertSame(
                1,
                $this->challengeRowCount(VerificationType::MAGIC_LINK, $email),
                'The cooldown of the link mints no second link',
            );
            $this->assertSame(
                1,
                $this->challengeRowCount(VerificationType::MAGIC_LINK_CODE, $email),
                'And no second code either: one gate, one letter',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A resend re-issues both halves at once, and their lifetimes stay together.
     *
     * Flow p.8: the resend button is the same send action, so it cannot renew one half
     * and leave the other - a letter whose code died before its link would look like a
     * code that never worked.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testAResendReissuesBothHalvesTogether(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $cooldown = Hilos::$env->int(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC);
        $this->openSession($agent, 'resend-ak');

        try {
            $this->requestLink($agent, 'resend-ak', $email);
            $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);

            $resent = $this->requestLink($agent, 'resend-ak', $email);

            $this->assertTrue($resent->ok);
            $this->assertSame(2, $this->challengeRowCount(VerificationType::MAGIC_LINK, $email));
            $this->assertSame(
                2,
                $this->challengeRowCount(VerificationType::MAGIC_LINK_CODE, $email),
                'The companion is re-issued by the same press',
            );
            $this->assertEqualsWithDelta(
                TimeHelper::nowMs() + $cooldown * TimeConstants::MS_PER_SECOND,
                (int)$resent->resendAt,
                self::MOMENT_DELTA_MS,
                'And one cooldown covers the pair',
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The typed code on a free address builds the account, exactly as the click does.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testCodeOnAFreeAddressRegistersAndSignsIn(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'code-register-ak');
        $this->requestLink($agent, 'code-register-ak', $email);
        $this->seedKnownCode($email);

        try {
            $outcome = $this->submitCode($agent, 'code-register-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            $this->assertNotNull($identity, 'The landed hold writes the magic-link identity');
            $this->assertTrue($identity->verified, 'Typing the code proves the address just as clicking does');

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($this->localPart($email), Hilos::$db->users[$userId]?->name);
            $this->assertSame(1, $this->registrationEventCount($userId), 'The new member is announced');

            $this->assertSame($userId, $this->sessionOf('code-register-ak')?->userId);
            $this->assertSame(0, $this->reservationRowCount($email), 'The hold is released on success');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The typed code on a taken address signs it in and creates nothing.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testCodeOnATakenAddressSignsInWithoutCreatingAnAccount(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $userId = $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'code-login-ak');
        $this->requestLink($agent, 'code-login-ak', $email);
        $this->seedKnownCode($email);

        try {
            $outcome = $this->submitCode($agent, 'code-login-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);

            $this->assertSame($userId, $this->sessionOf('code-login-ak')?->userId);
            $this->assertSame(1, $this->identityRowCount($email), 'No second identity is written for a sign-in');
            $this->assertSame(0, $this->registrationEventCount($userId), 'Nobody registered here');
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Answering either half kills the other: the letter is single-use as it says.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testAnsweringOneHalfVoidsTheOther(): void
    {
        $agent = $this->bootAgent();
        $byCode = $this->uniqueEmail();
        $byLink = $this->uniqueEmail();
        $this->seedPasswordlessAccount($byCode);
        $this->seedPasswordlessAccount($byLink);
        $this->openSession($agent, 'mutual-ak');

        try {
            $this->requestLink($agent, 'mutual-ak', $byCode);
            $this->seedKnownToken($byCode);
            $this->seedKnownCode($byCode);

            $this->assertTrue($this->submitCode($agent, 'mutual-ak', $byCode, self::CODE)->ok);
            $this->assertNull(
                $this->activeChallenge(VerificationType::MAGIC_LINK, $byCode),
                'A code that signed someone in leaves no link to follow',
            );
            $this->assertFalse($this->clickLink($agent, 'mutual-ak', $byCode, self::TOKEN)->ok);

            $this->requestLink($agent, 'mutual-ak', $byLink);
            $this->seedKnownToken($byLink);
            $this->seedKnownCode($byLink);

            $this->assertTrue($this->clickLink($agent, 'mutual-ak', $byLink, self::TOKEN)->ok);
            $this->assertNull(
                $this->activeChallenge(VerificationType::MAGIC_LINK_CODE, $byLink),
                'And a followed link leaves no code to type',
            );
            $this->assertFalse($this->submitCode($agent, 'mutual-ak', $byLink, self::CODE)->ok);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code guessed to exhaustion does NOT take the link down with it.
     *
     * The reason the halves carry separate ceilings (Flow p.7): a stranger who types six
     * digits at somebody else's address must not be able to spend a letter they never
     * received. Only success crosses between the halves.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testExhaustingTheCodeLeavesTheLinkAlive(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'exhaust-ak');
        $this->requestLink($agent, 'exhaust-ak', $email);
        $this->seedKnownToken($email);
        $this->seedKnownCode($email);

        try {
            for ($attempt = 0; $attempt < $this->maxAttempts(); $attempt++) {
                $this->assertFalse($this->submitCode($agent, 'exhaust-ak', $email, self::WRONG_CODE)->ok);
            }

            $this->assertNull(
                $this->activeChallenge(VerificationType::MAGIC_LINK_CODE, $email),
                'The guessed-at code is spent',
            );
            $this->assertNotNull(
                $this->activeChallenge(VerificationType::MAGIC_LINK, $email),
                'The link the owner is holding is untouched',
            );
            $this->assertTrue($this->clickLink($agent, 'exhaust-ak', $email, self::TOKEN)->ok);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code that opens nothing refuses in place, leaving the waiting screen up.
     *
     * The one branch where the halves answer differently (Flow p.5): a clicked link rolls
     * back to the address field, while a mistyped code keeps the person on the screen that
     * asked for it - the field, the countdown and the resend button are all still there.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testUnusableCodeRefusesWithoutMovingTheSurface(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->seedPasswordlessAccount($email);
        $this->openSession($agent, 'code-invalid-ak');
        $this->requestLink($agent, 'code-invalid-ak', $email);
        $this->seedKnownCode($email);

        try {
            $wrong = $this->submitCode($agent, 'code-invalid-ak', $email, self::WRONG_CODE);

            $this->assertFalse($wrong->ok);
            $this->assertSame(AuthFlowOutcome::CODE_MAGIC_LINK_INVALID, $wrong->code);
            $this->assertNull($wrong->step, 'A mistyped code does not move the surface');
            $this->assertNull($wrong->intent);
            $this->assertNull(Hilos::$rt->connections['code-invalid-ak']->userId);

            $this->assertTrue($this->submitCode($agent, 'code-invalid-ak', $email, self::CODE)->ok);
            $reused = $this->submitCode($agent, 'code-invalid-ak', $email, self::CODE);

            $this->assertFalse($reused->ok, 'A code is single-use');
            $this->assertSame(AuthFlowOutcome::CODE_MAGIC_LINK_INVALID, $reused->code);
            $this->assertNull($reused->step);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * A code that outlived its hold rolls back, exactly as the click does.
     *
     * The exception to "a code refuses in place" (Flow p.6): the address is not held any
     * more, so there is nothing on this screen left to finish.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testCodeThatOutlivedItsHoldStillRegisters(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'code-expired-ak');
        $this->requestLink($agent, 'code-expired-ak', $email);
        $this->seedKnownCode($email);
        $this->ageReservationOut($email);

        try {
            $outcome = $this->submitCode($agent, 'code-expired-ak', $email, self::CODE);

            $this->assertTrue($outcome->ok);
            $this->assertSame(AuthFlowStep::DONE, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            $this->assertNotNull($identity, 'Both halves of one letter end the same way');
            $this->assertTrue($identity->verified);
            $this->assertNotNull(Hilos::$rt->connections['code-expired-ak']->userId);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Registers the truth sources and signal router the magic-link path needs.
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
     * @param ?string $sessionToken Token to reuse, opening a second socket of one browser, or null for a new browser
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
     * Asks for a sign-in link through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address the link is asked for
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the request handler rejects the action
     */
    private function requestLink(ChatAgent $agent, string $acceptKey, string $email): AuthFlowOutcome
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK,
            new RequestMagicLinkActionDTO($email),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Opens a link through the main page for one connection.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address the link was issued for
     * @param string $token Token the link carries back
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the confirm handler rejects the action
     */
    private function clickLink(
        ChatAgent $agent,
        string $acceptKey,
        string $email,
        string $token,
    ): AuthFlowOutcome {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK,
            new ConfirmMagicLinkActionDTO($email, $token),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Submits a password registration, so the address is held by a password hold.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Submitted email
     * @throws HilosException When the register handler rejects the action
     */
    private function register(ChatAgent $agent, string $acceptKey, string $email): void
    {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_REGISTER,
            new RegisterActionDTO($email, self::PASSWORD),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Seeds a challenge with a token this test knows, newer than the mailed one.
     *
     * @param string $email Address the link was sent to
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownToken(string $email): void
    {
        // Void the mailed challenge first: leaving it behind would let a burned seeded
        // token fall back on a token this test cannot know.
        $this->verifications()->voidActive(VerificationType::MAGIC_LINK, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::MAGIC_LINK,
            $email,
            null,
            self::TOKEN,
            self::TTL_SECONDS,
        );
    }

    /**
     * Seeds the companion challenge with a code this test knows, newer than the mailed one.
     *
     * @param string $email Address the letter was sent to
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownCode(string $email): void
    {
        // Void the mailed companion first, for the same reason seedKnownToken does:
        // a burned seeded code must not fall back on one this test cannot know.
        $this->verifications()->voidActive(VerificationType::MAGIC_LINK_CODE, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::MAGIC_LINK_CODE,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Types the letter's code into the waiting screen, through the main page.
     *
     * @param ChatAgent $agent Agent owning the page
     * @param string $acceptKey Acting connection accept key
     * @param string $email Address the letter was issued for
     * @param string $code Code typed on the screen
     * @return AuthFlowOutcome The outcome the surface is answered with
     * @throws HilosException When the confirm handler rejects the action
     */
    private function submitCode(
        ChatAgent $agent,
        string $acceptKey,
        string $email,
        string $code,
    ): AuthFlowOutcome {
        ExecutionContext::setCurrentAcceptKey($acceptKey);
        $reply = $this->usersLibrary()->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE,
            new ConfirmMagicLinkCodeActionDTO($email, $code),
        );
        $handedOver = $this->deliverLibraryFrames($agent);
        $outcome = $reply ?? $handedOver;
        $this->assertInstanceOf(AuthFlowOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * Seeds the add-password challenge the profile flow verifies, for one user.
     *
     * @param string $email Address being proven
     * @param int $userId Session user the challenge is minted for
     * @throws HilosException When the challenge insert fails
     */
    private function seedKnownEmailAddCode(string $email, int $userId): void
    {
        $this->verifications()->voidActive(VerificationType::EMAIL_ADD, $email, $this->maxAttempts());
        $this->verifications()->createChallenge(
            VerificationType::EMAIL_ADD,
            $email,
            $userId,
            self::EMAIL_ADD_CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * Creates an account owning a verified email and no password, as OAuth sign-up does.
     *
     * @param string $email Address the account owns
     * @return int Id of the created user
     * @throws HilosException When the account or identity write fails
     */
    private function seedPasswordlessAccount(string $email): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($this->localPart($email))->id;
        Hilos::$db->identities->createMagicLinkIdentity($userId, $email);

        return $userId;
    }

    /**
     * Ages every link sent to an address back, so the cooldown reads as elapsed.
     *
     * The counting window is left intact on purpose: the shift is small enough that
     * the aged sends still fall inside it, which is what lets a case reach the cap
     * without waiting out a real cooldown.
     *
     * @param string $email Address the links were sent to
     * @param int $seconds How far back to move each send
     * @throws HilosException When the update query fails
     */
    private function ageSendsOutOfTheCooldown(string $email, int $seconds): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($seconds));
        $params->add(SqlParam::string(VerificationType::MAGIC_LINK));
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
     * Ages a hold into the past so the click reads it as expired.
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
     * Counts the challenges of a type ever minted for an address, dead ones included.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $email Address the challenges were minted for
     * @return int Number of challenge rows
     * @throws HilosException When the count query fails
     */
    private function challengeRowCount(string $type, string $email): int
    {
        return EntityUserVerification::count([
            EntityUserVerification::type => $type,
            EntityUserVerification::identifier => $email,
        ]);
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
     * Counts the identities written on an address, whatever their type.
     *
     * @param string $email Identity identifier
     * @return int Number of rows
     * @throws HilosException When the count query fails
     */
    private function identityRowCount(string $email): int
    {
        return EntityIdentity::count([EntityIdentity::identifier => $email]);
    }

    /**
     * Counts the "registered in chat" announcements made for a user.
     *
     * @param int $userId Registered user
     * @return int Number of registration events
     * @throws HilosException When the count query fails
     */
    private function registrationEventCount(int $userId): int
    {
        return EntityEventUserRegistration::count([EntityEventUserRegistration::target_user_id => $userId]);
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
     * A browser waiting on a LINK is not resumed onto a registration code screen.
     *
     * The other half of what the wait row guards (HIL-608). Asking for a sign-in link
     * holds the address, but writes no wait: there is no code field to come back to, and
     * the letter's own companion code is a different challenge entirely. Answering the
     * handshake with a register step would put the person in front of a field asking for
     * a code nobody ever issued.
     *
     * @throws HilosException When setup or the request handling fails
     */
    public function testABrowserWaitingOnALinkIsNotResumedOntoACodeScreen(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $token = $this->openSession($agent, 'link-wait-ak');

        try {
            $this->requestLink($agent, 'link-wait-ak', $email);
            $this->assertNotNull($this->holdOf('link-wait-ak'), 'A free address is held while the letter travels');

            $this->drainHandshakeResponses();
            $this->openSession($agent, 'link-wait-new', $token);

            $this->assertNull(
                $this->drainHandshakeResponses()['link-wait-new']?->pendingAuthStep,
                'A hold with no code screen behind it resumes nothing',
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
     * Empties the signal queue and returns the converge signals it held, by target.
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
     * Resolves the live challenge of a type for an address.
     *
     * @param string $type Verification type (see VerificationType)
     * @param string $email Address the challenge was issued for
     * @return ?object Active challenge, or null when none is live
     * @throws HilosException When the lookup fails
     */
    private function activeChallenge(string $type, string $email): ?object
    {
        return $this->verifications()->findActive($type, $email, $this->maxAttempts());
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
