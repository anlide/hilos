<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Main\ConfirmMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Main\RegisterActionDTO;
use Demo\Chat\Pages\DTO\Main\RequestMagicLinkActionDTO;
use Demo\Chat\Pages\DTO\Profile\ConfirmAddPasswordActionDTO;
use Demo\Chat\Pages\DTO\Profile\RequestAddPasswordActionDTO;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Constants\EnvConstants;
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
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\Verification\VerificationType;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the magic link that both signs in and registers (HIL-417):
 * the send holds a free address without mailing a registration code, and the click
 * decides who is signed in from the state the address is in at that moment - an
 * existing account, a live hold finishing its registration, or neither.
 *
 * The token is only mailed (the dev-stub deliverer logs it), so every clicking case
 * seeds a challenge with a token it knows through the verifications object
 * collection - the same level HIL-415 and HIL-406 test their code flows at. Seeding
 * AFTER the send is deliberate: findActive() answers the newest challenge, so the
 * seeded token is the live one.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class MainPageMagicLinkTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string WRONG_TOKEN = 'ffffffffffffffffffffffffffffffff';
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

            $reservation = $this->reservations()->findActive($email);
            $this->assertNotNull($reservation, 'A free address is held for the life of the link');
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
        $this->assertNotNull($this->reservations()->findActive($email));
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
     * A link that outlived its hold rolls the surface back instead of registering.
     *
     * @throws HilosException When setup or the confirm handling fails
     */
    public function testExpiredHoldRollsBackToTheIdentifierStep(): void
    {
        $agent = $this->bootAgent();
        $email = $this->uniqueEmail();
        $this->openSession($agent, 'expired-ak');
        $this->requestLink($agent, 'expired-ak', $email);
        $this->seedKnownToken($email);
        $this->ageReservationOut($email);

        try {
            $outcome = $this->clickLink($agent, 'expired-ak', $email, self::TOKEN);

            $this->assertFalse($outcome->ok);
            $this->assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
            $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            $this->assertSame(AuthFlowIntent::REGISTER, $outcome->intent);

            $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email));
            $this->assertNull(Hilos::$rt->connections['expired-ak']->userId);
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
            $this->assertSame($cooldown, $this->requestLink($agent, 'gate-ak', $email)->resendInSeconds);

            $held = $this->requestLink($agent, 'gate-ak', $email);
            $this->assertTrue($held->ok, 'A repeat inside the cooldown is not an error');
            $this->assertGreaterThan(0, $held->resendInSeconds, 'It answers the seconds still to wait');
            $this->assertSame(1, $this->sendRowCount($email), 'And nothing is mailed for it');

            for ($sent = 1; $sent < $cap; $sent++) {
                $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
                $this->assertTrue($this->requestLink($agent, 'gate-ak', $email)->ok, "Send {$sent} is under the cap");
            }
            $this->assertSame($cap, $this->sendRowCount($email), 'The window is full');

            $this->ageSendsOutOfTheCooldown($email, $cooldown + 1);
            $refused = $this->requestLink($agent, 'gate-ak', $email);

            $this->assertFalse($refused->ok);
            $this->assertSame(AuthFlowOutcome::CODE_SEND_CAP_REACHED, $refused->code);
            $this->assertNull($refused->step, 'A cap refusal leaves the surface where it is');
            $this->assertSame($cap, $this->sendRowCount($email), 'Nothing is minted past the cap');
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
            new ProfilePage($agent)->onAction(
                'profile-ak',
                ChatSignalConstants::ADD_PASSWORD_REQUEST,
                new RequestAddPasswordActionDTO($email),
            );
            $this->seedKnownEmailAddCode($email, $userId);
            new ProfilePage($agent)->onAction(
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
     * Registers the truth sources and signal router the magic-link path needs.
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
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REQUEST_MAGIC_LINK,
            new RequestMagicLinkActionDTO($email),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
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
        $reply = new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::CONFIRM_MAGIC_LINK,
            new ConfirmMagicLinkActionDTO($email, $token),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);

        return $reply;
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
        new MainPage($agent)->onAction(
            $acceptKey,
            ChatSignalConstants::REGISTER,
            new RegisterActionDTO($email, self::PASSWORD),
        );
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
     * Counts the links ever sent to an address, dead ones included.
     *
     * @param string $email Address links were sent to
     * @return int Number of challenge rows
     * @throws HilosException When the count query fails
     */
    private function sendRowCount(string $email): int
    {
        return EntityUserVerification::count([
            EntityUserVerification::type => VerificationType::MAGIC_LINK,
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
