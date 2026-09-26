<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\CompleteRegistrationPasskeyActionDTO;
use Hilos\Auth\Library\DTO\RegistrationPasskeyOptionsActionDTO;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\DTO\PasskeyOptionsSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestSettings;
use Hilos\Tests\Unit\Auth\WebAuthn\WebAuthnTestVectors;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * An account started on a passkey, against the real tables (HIL-1104).
 *
 * The guest's passkey door is two submits, and the second one writes a person into four tables
 * at once - the user, a confirmed address on one road, the key's identity anchor and its crypto
 * half - so what it promises is a statement about rows and only a database can answer it. The
 * users library and the session holder are driven the way a node drives them: a command in, and
 * every frame the library queues for the holder handed across. The key is a real one, minted by
 * the WebAuthn test vectors, so the attestation check the door runs is the one production runs.
 *
 * Pinned: the road with a code lands a confirmed address beside the key and ends through the
 * registration landing; the road without one lands the key and no address at all, and only
 * while the installation allows it; a taken address or number turns the door into sign-in; a
 * road chosen by the first submit is not chosen again by the second; and a failure at the key
 * leaves nothing behind - before the landing, and inside it.
 */
final class PasskeyRegistrationIntegrationTest extends HilosSessionIntegrationTestCase
{
    /**
     * Table the fixture library writes the user row into; created and dropped around every case,
     * outside the landing, because DDL commits implicitly in MySQL. Public for the library at the
     * tail of this file.
     */
    public const string FIXTURE_USER_TABLE = 'passkey_registration_fixture_user';

    private const string CREATED_AT = '2026-09-26 09:00:00';

    private const string SESSION_TOKEN = 'cc00000000000000000000000000cc04';

    private const string ACCEPT_KEY = 'accept-passkey-registration';

    /** Challenge secret the options are signed with here; the stand's env leaves it empty. */
    private const string CHALLENGE_SECRET = 'passkey-registration-test-secret';

    /** Origin the ceremony runs on - the env catalog's default, the one the verifier allows. */
    private const string ORIGIN = 'http://localhost';

    /** A browser of the kind the profile names a key after. */
    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** Seconds a seeded live hold has left. */
    private const int LIVE_FOR_SECONDS = 900;

    /** Owner of the identities already standing on an identifier, which no case signs into. */
    private const int RIVAL_USER_ID = 9104;

    private const string RIVAL_SECRET = 'the-other-account-secret';

    /** A number in the form the detector normalizes to. */
    private const string PHONE = '+14155552671';

    /** Connection index of the outside observer; the case itself holds the primary one. */
    private const int OBSERVER_INDEX = 1;

    /** Display name of the marker row written after a refused landing to show its transaction is over. */
    private const string PROBE_NAME = 'transaction-probe';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private PasskeyRegistrationTestHolder $holder;

    private PasskeyRegistrationTestLibrary $library;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runPasskeyStub(down: true);
        self::runPasskeyStub(down: false);
        self::createFixtureUserTable();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        Hilos::$sr = new SignalRouter();
        AuthMethodTestSettings::$passkeyAllowsUnproven = null;
        Hilos::$setting = new AuthMethodTestSettings();
        putenv(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name . '=' . self::CHALLENGE_SECRET);

        $rt = new PasskeyRegistrationTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateRecoveryWaiter::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateRegistrationWaiter::RT_COLLECTION);

        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $this->holder = new PasskeyRegistrationTestHolder();
        $this->library = new PasskeyRegistrationTestLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateRecoveryWaiter::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateRegistrationWaiter::RT_COLLECTION);
        SourceChangeBus::reset();
        putenv(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name);
        AuthMethodTestSettings::$passkeyAllowsUnproven = null;
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        self::dropFixtureUserTable();
        self::runPasskeyStub(down: true);

        parent::tearDown();
    }

    /**
     * The road with a code lands a confirmed address beside the key, and ends as a landed registration.
     *
     * The account holds the address as a verified mailed-link identity - the only secret-less
     * address identity there is, the way a provider registration lands it - and the key as its
     * passkey identity with the crypto half beside it, under the handle the device prompt was
     * given. The hold has served its purpose, and the holder signs the browser in with the
     * "registered" mark, as it does for a password.
     *
     * @throws HilosException When a command, a frame or a lookup fails
     */
    public function testTheRoadWithACodeLandsAConfirmedAddressAndTheKey(): void
    {
        $email = $this->uniqueEmail();
        $this->seedProvenHold($email);

        $options = $this->askOptions($email);
        $this->assertSame(PasskeyOptionsSignalData::CEREMONY_NEW_ACCOUNT, $options->ceremony);
        $this->assertSame($email, $options->publicKeyOptions['user']['name'] ?? null, 'The key is labeled with the address');

        $reply = $this->complete($email, $options, new WebAuthnTestVectors());
        $this->assertNull($reply, 'A landing is answered by the session holder, not by the submit');

        $userId = $this->theOnlyUser();
        $this->assertSame(strstr($email, '@', true), $this->displayNameOf($userId));
        $address = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
        $this->assertSame($userId, $address?->userId, 'The proven address lands as the account\'s own');
        $this->assertTrue((bool)$address?->verified, 'The code proved the address before the key was made');
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email), 'No password was chosen');
        $credentials = Hilos::$db->passkeyCredentials->listByUser($userId);
        $this->assertCount(1, $credentials, 'The key is the account\'s way in');
        $handle = $options->publicKeyOptions['user']['id'] ?? null;
        $this->assertIsString($handle, 'The options name the account\'s handle');
        $this->assertSame(
            Base64Url::decode($handle),
            $credentials[0]->userHandle,
            'The key is stored under the handle the device prompt was given',
        );
        $this->assertNotNull(Hilos::$db->identities[$credentials[0]->identityId], 'The key has its identity anchor');
        $this->assertNull(
            $this->reservations()->findActiveForSession(self::SESSION_TOKEN),
            'The hold has served its purpose',
        );

        $this->forwardToHolder();
        $frame = $this->lastStateFrame();
        $this->assertSame($userId, $frame?->userId, 'The browser is signed into the account it just made');
        $this->assertSame(SessionAck::REGISTERED, $frame?->pendingAck);
    }

    /**
     * The road without a code lands the key and no address, while the installation allows it.
     *
     * The owner's decision (26.09.2026): the typed address is not stored. Nothing on the address -
     * so its real owner can still register it - and this browser's own unproven hold on it is
     * dropped, because it names a registration the browser is no longer running. The sign-in is
     * a grant carrying the same "registered" mark.
     *
     * @throws HilosException When a command, a frame or a lookup fails
     */
    public function testTheRoadWithoutACodeLandsTheKeyAndNoAddress(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        $email = $this->uniqueEmail();
        $this->reservations()->createReservation(IdentityType::PASSWORD, self::SESSION_TOKEN, $email, self::LIVE_FOR_SECONDS);

        $options = $this->askOptions($email);
        $reply = $this->complete($email, $options, new WebAuthnTestVectors());
        $this->assertNull($reply, 'A sign-in is answered by the session holder, not by the submit');

        $userId = $this->theOnlyUser();
        $this->assertSame(strstr($email, '@', true), $this->displayNameOf($userId));
        $this->assertCount(1, Hilos::$db->passkeyCredentials->listByUser($userId));
        $this->assertNull(Hilos::$db->identities->findAccountIdByEmail($email), 'The typed address belongs to nobody');
        $this->assertNull(Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email));
        $this->assertNull(
            $this->reservations()->findActiveForSession(self::SESSION_TOKEN),
            'This browser\'s own hold names a registration it is no longer running',
        );

        $this->forwardToHolder();
        $frame = $this->lastStateFrame();
        $this->assertSame($userId, $frame?->userId);
        $this->assertSame(SessionAck::REGISTERED, $frame?->pendingAck);
    }

    /**
     * A challenge that already started an account is spent: a second key sent on it starts nothing.
     *
     * The signed token outlives its first use, and the new account's handle is derived from it;
     * a second account on the same challenge would carry the first one's handle, and its key would
     * never open it.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testAChallengeThatStartedAnAccountDoesNotStartASecond(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        $email = $this->uniqueEmail();
        $options = $this->askOptions($email);
        $this->assertNull($this->complete($email, $options, new WebAuthnTestVectors()));
        $this->drainQueue();

        $refused = null;
        try {
            $this->complete($email, $options, new WebAuthnTestVectors());
        } catch (ValidationException $failure) {
            $refused = $failure;
        }

        $this->assertNotNull($refused, 'A spent challenge is refused, not landed');
        $this->assertSame(AuthMessages::INVALID_PASSKEY, $refused->getMessage());
        $this->assertSame(1, PasskeyRegistrationTestLibrary::usersVisible(), 'One challenge, one account');
        Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_passkey_credential`');
        $this->assertSame(1, (int)(Database::row()['total'] ?? -1), 'The second key was not stored');
    }

    /**
     * Where the installation does not allow the road without a code, both submits refuse it and nothing is written.
     *
     * The first refuses before the device prompt opens; the second refuses too, because the setting
     * may be switched off while the prompt is open.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testTheRoadWithoutACodeIsRefusedOnBothSubmitsWhereItIsNotAllowed(): void
    {
        $email = $this->uniqueEmail();

        $this->assertRefusal(
            $this->library->onAgentAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
                new RegistrationPasskeyOptionsActionDTO($email),
            ),
            AuthFlowOutcome::CODE_PASSKEY_ADDRESS_UNPROVEN,
            AuthFlowIntent::REGISTER,
            AuthMessages::PASSKEY_ADDRESS_UNPROVEN,
        );
        $this->assertNull($this->queuedOptions(), 'No device prompt opens on a refusal');

        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        $options = $this->askOptions($email);
        AuthMethodTestSettings::$passkeyAllowsUnproven = false;

        $this->assertRefusal(
            $this->complete($email, $options, new WebAuthnTestVectors()),
            AuthFlowOutcome::CODE_PASSKEY_ADDRESS_UNPROVEN,
            AuthFlowIntent::REGISTER,
            AuthMessages::PASSKEY_ADDRESS_UNPROVEN,
        );
        $this->assertNothingWritten();
    }

    /**
     * An address somebody already has turns the door into sign-in, on both submits.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testATakenAddressIsSentToSignIn(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        $email = $this->uniqueEmail();
        $options = $this->askOptions($email);
        Hilos::$db->identities->createPasswordIdentity(self::RIVAL_USER_ID, $email, self::RIVAL_SECRET);

        $this->assertRefusal(
            $this->library->onAgentAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
                new RegistrationPasskeyOptionsActionDTO($email),
            ),
            AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            AuthFlowIntent::LOGIN,
            AuthMessages::IDENTIFIER_TAKEN,
        );
        $this->assertRefusal(
            $this->complete($email, $options, new WebAuthnTestVectors()),
            AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            AuthFlowIntent::LOGIN,
            AuthMessages::IDENTIFIER_TAKEN,
        );
        $this->assertNothingWritten();
    }

    /**
     * A number somebody already has is sent to sign-in in words about a number.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testATakenNumberIsSentToSignIn(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        self::seedIdentity(self::RIVAL_USER_ID, IdentityType::SMS, self::PHONE);

        $this->assertRefusal(
            $this->library->onAgentAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
                new RegistrationPasskeyOptionsActionDTO(self::PHONE),
            ),
            AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            AuthFlowIntent::LOGIN,
            AuthMessages::PHONE_TAKEN,
        );
        $this->assertNothingWritten();
    }

    /**
     * The road with a code whose hold ran out while the device prompt was open answers "expired".
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testAHoldThatRanOutBeforeTheKeyAnswersExpired(): void
    {
        $email = $this->uniqueEmail();
        $this->seedProvenHold($email);
        $options = $this->askOptions($email);
        new RegistrationReservationService()->release(self::SESSION_TOKEN);

        $this->assertRefusal(
            $this->complete($email, $options, new WebAuthnTestVectors()),
            AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
            AuthFlowIntent::REGISTER,
            AuthMessages::RESERVATION_EXPIRED,
        );
        $this->assertNothingWritten();
    }

    /**
     * A challenge minted for the road with a code does not become the road without one.
     *
     * The installation allows the road without a code, and the hold ran out: the second submit
     * could land an account with no address, and must not - the person chose the road where the
     * address is theirs, and quietly handing them the other one would leave them without it.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testAChallengeForTheRoadWithACodeIsNotCarriedToTheRoadWithout(): void
    {
        AuthMethodTestSettings::$passkeyAllowsUnproven = true;
        $email = $this->uniqueEmail();
        $this->seedProvenHold($email);
        $options = $this->askOptions($email);
        new RegistrationReservationService()->release(self::SESSION_TOKEN);

        $this->assertRefusal(
            $this->complete($email, $options, new WebAuthnTestVectors()),
            AuthFlowOutcome::CODE_RESERVATION_EXPIRED,
            AuthFlowIntent::REGISTER,
            AuthMessages::RESERVATION_EXPIRED,
        );
        $this->assertNothingWritten();
    }

    /**
     * A key the verifier refuses creates nothing, and the hold stays for another ending.
     *
     * @throws HilosException When a command or a lookup fails
     */
    public function testAKeyThatFailsItsCheckCreatesNothing(): void
    {
        $email = $this->uniqueEmail();
        $this->seedProvenHold($email);
        $options = $this->askOptions($email);

        $refused = null;
        try {
            $this->complete($email, $options, new WebAuthnTestVectors(), 'a-different-challenge');
        } catch (ValidationException $failure) {
            $refused = $failure;
        }

        $this->assertNotNull($refused, 'A key that fails its check is refused with the reason');
        $this->assertStringStartsWith(AuthMessages::PASSKEY_REGISTRATION_FAILED, $refused->getMessage());
        $this->assertNothingWritten();
        $this->assertNotNull(
            $this->reservations()->findActiveForSession(self::SESSION_TOKEN),
            'The hold is alive: the person can still choose a password',
        );
    }

    /**
     * A key refused inside the landing rolls back the account and the address, and closes the transaction.
     *
     * The key is stored inside the landing, after the user row and the address are written, so a
     * key somebody already registered is refused there - and is answered as that, not as the
     * address being taken, which is what a raw duplicate would read as to the landing.
     *
     * @throws HilosException When a command, a lookup or the probe fails
     */
    public function testAKeyRefusedInsideTheLandingRollsTheAccountBackAndCloses(): void
    {
        $email = $this->uniqueEmail();
        $this->seedProvenHold($email);
        $options = $this->askOptions($email);
        $vectors = new WebAuthnTestVectors();
        $credentialId = random_bytes(20);
        Hilos::$db->identities->createPasskeyIdentity(self::RIVAL_USER_ID, Base64Url::encode($credentialId));

        $refused = null;
        try {
            $this->complete($email, $options, $vectors, credentialId: $credentialId);
        } catch (ValidationException $failure) {
            $refused = $failure;
        }

        $this->assertNotNull($refused, 'A key registered already is refused, not landed');
        $this->assertSame(AuthMessages::PASSKEY_ALREADY_REGISTERED, $refused->getMessage());
        $this->assertSame(1, $this->library->rowsSeenInsideTransaction, 'The user row was written before the key');
        $this->assertNothingWritten();
        $this->assertNotNull(
            $this->reservations()->findActiveForSession(self::SESSION_TOKEN),
            'The landing\'s release of the hold went with it',
        );

        Database::sql('INSERT INTO `' . self::FIXTURE_USER_TABLE . '` (`display_name`) VALUES (?)', [self::PROBE_NAME]);
        $this->assertSame(
            1,
            $this->usersSeenByAnotherConnection(),
            'A marker written after the landing must be visible from outside: a transaction left open would have taken it in',
        );
    }

    /**
     * Asks the options of a key for a new account and returns what arrived on the signal.
     *
     * @param string $identifier Identifier as typed
     * @return PasskeyOptionsSignalData The options the browser was sent
     * @throws HilosException When the command fails
     */
    private function askOptions(string $identifier): PasskeyOptionsSignalData
    {
        $reply = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
            new RegistrationPasskeyOptionsActionDTO($identifier),
        );
        $this->assertNull($reply, 'The options travel on the signal, not in the answer');
        $options = $this->queuedOptions();
        $this->assertNotNull($options, 'The options were sent to the browser that asked');

        return $options;
    }

    /**
     * Runs the device half with a real key and submits it.
     *
     * @param string $identifier Identifier as typed
     * @param PasskeyOptionsSignalData $options Options the first submit sent
     * @param WebAuthnTestVectors $vectors The authenticator
     * @param ?string $echoedChallenge Challenge the client data echoes, or null for the one the options named
     * @param ?string $credentialId Raw id of the key made, or null for a fresh one
     * @return mixed What the submit answered
     * @throws HilosException When the command fails
     */
    private function complete(
        string $identifier,
        PasskeyOptionsSignalData $options,
        WebAuthnTestVectors $vectors,
        ?string $echoedChallenge = null,
        ?string $credentialId = null,
    ): mixed {
        $authData = $vectors->authenticatorData(
            WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED
                | WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
            0,
            $vectors->attestedCredentialData($credentialId ?? random_bytes(20), str_repeat("\0", 16)),
        );
        $challenge = $echoedChallenge ?? $options->publicKeyOptions['challenge'] ?? null;
        $this->assertIsString($challenge, 'The options carry the challenge the device signs');
        $clientDataJson = $vectors->clientDataJson($challenge, self::ORIGIN);

        return $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY,
            new CompleteRegistrationPasskeyActionDTO(
                $identifier,
                $options->signedChallenge,
                Base64Url::encode($vectors->attestationObject($authData)),
                Base64Url::encode($clientDataJson),
                ['internal'],
                self::USER_AGENT,
            ),
        );
    }

    /**
     * @param mixed $reply What a submit answered
     * @param string $code Refusal code expected
     * @param string $intent Intent the surface is expected to move under
     * @param string $message Sentence the surface is expected to show
     */
    private function assertRefusal(mixed $reply, string $code, string $intent, string $message): void
    {
        $this->assertInstanceOf(AuthFlowOutcome::class, $reply);
        $this->assertFalse($reply->ok);
        $this->assertSame($code, $reply->code);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $reply->step);
        $this->assertSame($intent, $reply->intent);
        $this->assertSame($message, $reply->message);
    }

    /**
     * No user, no key, and nothing on the address but what the case seeded for somebody else.
     *
     * @throws HilosException When a lookup fails
     */
    private function assertNothingWritten(): void
    {
        $this->assertSame(0, PasskeyRegistrationTestLibrary::usersVisible(), 'No account was created');
        Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_passkey_credential`');
        $this->assertSame(0, (int)(Database::row()['total'] ?? -1), 'No key was stored');
        Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_identity` WHERE `user_id` <> ?', [self::RIVAL_USER_ID]);
        $this->assertSame(0, (int)(Database::row()['total'] ?? -1), 'No identity was written for a new account');
    }

    /**
     * @param string $email Address this browser proves
     * @throws HilosException When the hold cannot be written or marked
     */
    private function seedProvenHold(string $email): void
    {
        $this->reservations()->createReservation(IdentityType::PASSWORD, self::SESSION_TOKEN, $email, self::LIVE_FOR_SECONDS);
        $this->assertTrue(new RegistrationReservationService()->markProven(self::SESSION_TOKEN, $email));
    }

    /**
     * @return int Id of the one account the fixture library created
     * @throws DatabaseException When the query fails
     */
    private function theOnlyUser(): int
    {
        $this->assertSame(1, PasskeyRegistrationTestLibrary::usersVisible(), 'Exactly one account was created');
        Database::sql('SELECT `id` FROM `' . self::FIXTURE_USER_TABLE . '`');

        return (int)(Database::row()['id'] ?? 0);
    }

    /**
     * @param int $userId Account to name
     * @return ?string Name the account was created with
     * @throws DatabaseException When the query fails
     */
    private function displayNameOf(int $userId): ?string
    {
        Database::sql('SELECT `display_name` FROM `' . self::FIXTURE_USER_TABLE . '` WHERE `id` = ?', [$userId]);
        $name = Database::row()['display_name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @return ObjectRegistrationReservations Framework-owned reservation primitives
     * @throws HilosException When the collection is unavailable
     */
    private function reservations(): ObjectRegistrationReservations
    {
        /** @var ObjectRegistrationReservations $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::registrationReservations);

        return $collection;
    }

    /**
     * @return string Unique lowercase address for one case
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }

    /**
     * Drains the queue down to the passkey options sent to this browser, if any.
     *
     * @return ?PasskeyOptionsSignalData The options, or null when none were sent
     */
    private function queuedOptions(): ?PasskeyOptionsSignalData
    {
        $options = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_PASSKEY_OPTIONS
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof PasskeyOptionsSignalData) {
                $options = $signal->data->data;
            }
        }

        return $options;
    }

    /**
     * Drops every queued signal.
     */
    private function drainQueue(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // dropped
        }
    }

    /**
     * Hands every frame the library queued for the holder across, dropping the rest.
     *
     * @throws HilosException When the holder fails on a frame
     */
    private function forwardToHolder(): void
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($signal->data instanceof AgentSignalData && isset(AbstractSessionsLibraryAgent::AGENT_SIGNALS[$name])) {
                $frames[] = [$name, $signal->data];
            }
        }
        foreach ($frames as [$name, $data]) {
            $this->holder->onSignalAgent($data, 'test', $name);
        }
    }

    /**
     * @return ?SessionStateSignalData The last session state frame queued since the last drain
     */
    private function lastStateFrame(): ?SessionStateSignalData
    {
        $last = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof SessionStateSignalData) {
                $last = $payload->data;
            }
        }

        return $last;
    }

    /**
     * Counts the fixture users as a second connection sees them: committed ones, and only those.
     *
     * @return int Fixture rows visible from outside the case's own connection
     * @throws DatabaseException When the count fails
     * @throws DatabaseConnectionException When the second connection cannot be opened
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws EnvException When env variables are missing or invalid
     */
    private function usersSeenByAnotherConnection(): int
    {
        Database::configure(
            index: self::OBSERVER_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::OBSERVER_INDEX);
        Database::useConnection(self::OBSERVER_INDEX);

        try {
            return PasskeyRegistrationTestLibrary::usersVisible();
        } finally {
            Database::close(self::OBSERVER_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * Raises or drops the key table the session integration base does not otherwise need.
     *
     * @param bool $down Drop the table when true, create it when false
     * @throws DatabaseException When the stub statement fails
     */
    private static function runPasskeyStub(bool $down): void
    {
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_passkey_credential{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }

    /**
     * Creates the fixture user table, dropping a leftover of an interrupted run first.
     *
     * @throws DatabaseException When a statement fails
     */
    private static function createFixtureUserTable(): void
    {
        self::dropFixtureUserTable();
        Database::sqlRun(
            'CREATE TABLE `' . self::FIXTURE_USER_TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`display_name` VARCHAR(255) NOT NULL'
            . ') ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX,
        );
    }

    /**
     * @throws DatabaseException When the statement fails
     */
    private static function dropFixtureUserTable(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `' . self::FIXTURE_USER_TABLE . '`');
    }
}

/**
 * Runtime with the sign-in feature and the one tab of the browser under test.
 */
final class PasskeyRegistrationTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = PasskeyRegistrationTestConnections::init();
        $connections->add(PasskeyRegistrationTestConnection::create(
            'accept-passkey-registration',
            null,
            'cc00000000000000000000000000cc04',
        ));
        $this->_stateCollections[PasskeyRegistrationTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Sessions library of the fixture project.
 */
final class PasskeyRegistrationTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Users library of a fixture project that writes its users into the case's fixture table.
 *
 * {@see createUser()} counts the rows on the SAME connection right after its insert - inside the
 * landing's transaction - so a case can tell a rollback from a failure that never reached it.
 */
final class PasskeyRegistrationTestLibrary extends AbstractUsersLibraryAgent
{
    /** Rows of the fixture table createUser() saw right after its insert, or null while it has not run */
    public ?int $rowsSeenInsideTransaction = null;

    /**
     * @return int Rows of the fixture user table the current connection can see
     * @throws DatabaseException When the count fails or answers no row
     */
    public static function usersVisible(): int
    {
        Database::sql('SELECT COUNT(*) AS `total` FROM `' . PasskeyRegistrationIntegrationTest::FIXTURE_USER_TABLE . '`');
        $row = Database::row();
        if ($row === null) {
            throw new DatabaseException('COUNT(*) answered no row');
        }

        return (int)$row['total'];
    }

    /**
     * @param string $displayName Name the new account is created with
     * @return int Id of the inserted row
     * @throws DatabaseException When the fixture insert or the count after it fails
     */
    public function createUser(string $displayName): int
    {
        Database::sql(
            'INSERT INTO `' . PasskeyRegistrationIntegrationTest::FIXTURE_USER_TABLE . '` (`display_name`) VALUES (?)',
            [$displayName],
        );
        $userId = Database::lastInsertId();
        $this->rowsSeenInsideTransaction = self::usersVisible();

        return $userId;
    }

    /**
     * @param int $userId Account to name
     * @return ?string Always null: nothing here reads a name back
     */
    public function displayNameOf(int $userId): ?string
    {
        return null;
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class PasskeyRegistrationTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'passkeyRegistrationTestConnections';

    public const string STATE_CLASS = PasskeyRegistrationTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class PasskeyRegistrationTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Own fields, of which this fixture has none
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
