<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogPolicy;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\SecurityNotice\SecurityNotificationType;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use ReflectionProperty;

/**
 * The notice a person gets when their account is signed in (HIL-294), end to end on the
 * sessions library: which ways in tell the person, and which do not.
 *
 * The cases drive the same library the daemon runs - a grant frame for a sign-in the users
 * library proved, a rebind frame for an operator's bind and a takeover, the admin:create command,
 * the block-changed frame for a browser let back in - over the real tables, with the framework's
 * own notifier queueing what it is asked to send. The harness is the one of
 * {@see AccessLogIntegrationTest}: the notice sits beside the access log and must not depend on it.
 */
final class SignInNoticeIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string CREATED_AT = '2026-10-15 10:00:00';

    private const string SESSION_TOKEN = 'a2940000000000000000000000000294';

    private const string ACCEPT_KEY = 'accept-sign-in-notice';

    private const int USER_ID = 294;

    private const int ADMIN_ID = 295;

    private const string DEVICE = 'Firefox on Linux';

    private const string ADDRESS = '203.0.113.29';

    private string $boundAppClass;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var ?HilosNotifier Notifier the suite had, put back so this file does not decide the next one */
    private ?HilosNotifier $previousNotify = null;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->boundAppClass = Hilos::appClass();
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousNotify = Hilos::$notify;
        self::bindAppClass(SignInNoticeTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$notify = new HilosNotifier();
        $rt = new SignInNoticeTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        foreach (self::daemonCollections() as $collection) {
            RtTruthSourceRegistry::registerDaemon($collection);
        }
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES (?, 'Person', 0), (?, 'Administrator', 1), (?, 'Minted', 1)",
            [self::USER_ID, self::ADMIN_ID, SignInNoticeTestHolder::MINTED_ID],
        );
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        foreach (self::daemonCollections() as $collection) {
            RtTruthSourceRegistry::unregisterDaemon($collection);
        }
        SourceChangeBus::reset();
        Hilos::$notify = $this->previousNotify;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    /**
     * A sign-in the users library proved - a password - tells the person once, naming the device
     * and the address the session has.
     *
     * @throws HilosException When the grant fails
     */
    public function testAPasswordSignInIsAnnouncedOnceWithTheDeviceAndTheAddress(): void
    {
        $this->seedGuestSession();

        $notices = $this->grant(new SignInNoticeTestHolder(), null);

        self::assertSame([[self::USER_ID, SecurityNotificationType::SIGNED_IN]], self::typesOf($notices));
        self::assertStringStartsWith(
            'Your account was signed in on ' . self::DEVICE . ' from ' . self::ADDRESS . '.',
            (string)$notices[0]->body,
        );
    }

    /**
     * A sign-in that created the account lands with auth_registered, and is not a sign-in to tell of.
     *
     * @throws HilosException When the grant fails
     */
    public function testARegistrationIsNotAnnounced(): void
    {
        $this->seedGuestSession();

        self::assertSame([], self::typesOf($this->grant(new SignInNoticeTestHolder(), SessionAck::REGISTERED)));
        self::assertSame(self::USER_ID, self::boundUserId());
    }

    /**
     * The sign-in a recovery ends in lands with auth_password_changed - the recovery is announced
     * on its own - also when it waited on the second factor and the ack was held on the session.
     *
     * @throws HilosException When a grant fails
     */
    public function testARecoverySignInIsNotAnnouncedAlsoAfterTheSecondFactor(): void
    {
        $this->seedGuestSession();

        self::assertSame([], self::typesOf($this->grant(new SignInNoticeTestHolder(), SessionAck::PASSWORD_CHANGED)));
        self::assertSame(self::USER_ID, self::boundUserId());

        Database::sqlRun('DELETE FROM `hilos_session`');
        $this->seedGuestSession();
        Database::sqlRun(
            'UPDATE `hilos_session` SET `pending_second_factor_user_id` = ?, `pending_second_factor_ack` = ? WHERE `token` = ?',
            [self::USER_ID, SessionAck::PASSWORD_CHANGED, self::SESSION_TOKEN],
        );

        self::assertSame([], self::typesOf($this->grant(new SignInNoticeTestHolder(), null, secondFactorProven: true)));
        self::assertSame(self::USER_ID, self::boundUserId());
    }

    /**
     * Binding a session that already held the person again - by a grant or by an operator's
     * rebind - is not a sign-in.
     *
     * @throws HilosException When a grant or a rebind fails
     */
    public function testASessionThatAlreadyHeldThePersonIsNotAnnounced(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new SignInNoticeTestHolder();

        self::assertSame([], self::typesOf($this->rebind($holder, self::USER_ID, null)));
        self::assertSame([], self::typesOf($this->grant($holder, null)));
    }

    /**
     * admin:create that mints a new account for an anonymous session creates an account rather
     * than signing a person in.
     *
     * @throws HilosException When the command fails
     */
    public function testAdminCreateMintingANewAccountIsNotAnnounced(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $holder = new SignInNoticeTestHolder();

        $holder->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-294',
                command: CliCommands::ADMIN_CREATE,
                payload: [AdminCommandConstants::FIELD_SESSION_TOKEN => self::SESSION_TOKEN],
            ),
            '',
            '',
        );

        self::assertSame([], self::typesOf($this->drain()));
        self::assertTrue($holder->minted);
        self::assertSame(SignInNoticeTestHolder::MINTED_ID, self::boundUserId());
    }

    /**
     * A browser that was inside when the block hit is let back in once the block is lifted; it
     * comes back on its own, and the person is not told of a sign-in. The block was lifted by
     * the person's agent before the frame, and that agent's own notice is not this holder's.
     *
     * @throws HilosException When the block frame fails
     */
    public function testTheReturnAfterTheBlockIsLiftedIsNotAnnounced(): void
    {
        $this->seedGuestSession();
        Database::sqlRun(
            'UPDATE `hilos_session` SET `blocked_user_id` = ?, `blocked_signed_in` = 1 WHERE `token` = ?',
            [self::USER_ID, self::SESSION_TOKEN],
        );
        // The tab that shows the Access closed card, open on this node.
        Hilos::$rt->sessionConnectionsSource()?->add(SignInNoticeTestConnection::create(self::ACCEPT_KEY, null, self::SESSION_TOKEN));
        $holder = new SignInNoticeTestHolder();

        $holder->onSignalAgent(
            new AgentSignalData(data: new AccountBlockChangedSignalData(self::USER_ID)),
            'test',
            HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED,
        );

        self::assertSame([], self::typesOf($this->drain()));
        self::assertSame(self::USER_ID, self::boundUserIdOf(self::USER_ID), 'The browser is back inside');
    }

    /**
     * An administrator taking a person over is not the person signing in, and neither is the
     * administrator's return to themselves.
     *
     * @throws HilosException When a rebind fails
     */
    public function testATakeoverAndItsEndAreNotAnnounced(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::ADMIN_ID, self::CREATED_AT, null);
        $holder = new SignInNoticeTestHolder();

        self::assertSame([], self::typesOf($this->rebind($holder, self::USER_ID, self::ADMIN_ID)));
        self::assertSame([], self::typesOf($this->rebind($holder, self::ADMIN_ID, null)));
    }

    /**
     * A privacy text that keeps no access log switches the log off and leaves the notice on.
     *
     * @throws HilosException When the grant fails
     */
    public function testTheNoticeIsSentWithTheAccessLogSwitchedOff(): void
    {
        self::bindAppClass(SignInNoticeWithoutLogTestHilos::class);
        self::assertFalse(AccessLogPolicy::keepsLog());
        $this->seedGuestSession();

        self::assertSame(
            [[self::USER_ID, SecurityNotificationType::SIGNED_IN]],
            self::typesOf($this->grant(new SignInNoticeTestHolder(), null)),
        );
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_access_log`');
        self::assertSame(0, (int)(Database::row()['count'] ?? -1));
    }

    /**
     * Seeds an anonymous session with the device and the address a handshake would have left on it.
     *
     * @throws DatabaseException When the insert fails
     */
    private function seedGuestSession(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `device_name` = ?, `ip_address` = ? WHERE `token` = ?',
            [self::DEVICE, self::ADDRESS, self::SESSION_TOKEN],
        );
    }

    /**
     * Signs the session in on the users library's word, the way a proven password does.
     *
     * @param SignInNoticeTestHolder $holder Sessions library of the case
     * @param ?string $ack Ack the sign-in earned ({@see SessionAck} value), or null for a plain sign-in
     * @param bool $secondFactorProven Whether the grant says the second factor was just shown
     * @return list<NotificationEmitSignalData> Notifications the grant queued
     * @throws HilosException When the grant fails
     */
    private function grant(SignInNoticeTestHolder $holder, ?string $ack, bool $secondFactorProven = false): array
    {
        $holder->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::ACCEPT_KEY,
                ack: $ack,
                secondFactorProven: $secondFactorProven,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );

        return $this->drain();
    }

    /**
     * Binds the session through the rebind frame, the way an operator does. No connection asks,
     * so nothing is rotated and the session keeps its token.
     *
     * @param SignInNoticeTestHolder $holder Sessions library of the case
     * @param int $userId Person the session acts as afterwards
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null when there is none
     * @return list<NotificationEmitSignalData> Notifications the rebind queued
     * @throws HilosException When the rebind fails
     */
    private function rebind(SignInNoticeTestHolder $holder, int $userId, ?int $impersonatorUserId): array
    {
        $holder->onSignalAgent(
            new AgentSignalData(data: new SessionRebindSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: $userId,
                impersonatorUserId: $impersonatorUserId,
            )),
            'test',
            HilosSignalConstants::HILOS_SESSION_REBIND,
        );

        return $this->drain();
    }

    /**
     * Empties the signal queue.
     *
     * @return list<NotificationEmitSignalData> Notifications found in it, in order
     */
    private function drain(): array
    {
        $notices = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof NotificationEmitSignalData) {
                $notices[] = $payload->data;
            }
        }

        return $notices;
    }

    /**
     * @param list<NotificationEmitSignalData> $notices Notifications queued
     * @return list<array{int, string}> Recipient and type of each
     */
    private static function typesOf(array $notices): array
    {
        return array_map(static fn (NotificationEmitSignalData $notice): array => [$notice->userId, $notice->type], $notices);
    }

    /**
     * @return ?int Person the case's session holds, read past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function boundUserId(): ?int
    {
        Database::sql('SELECT `user_id` FROM `hilos_session`');
        $rows = Database::rows();
        self::assertCount(1, $rows, 'The case has one session, rotated or not');

        return $rows[0]['user_id'] === null ? null : (int)$rows[0]['user_id'];
    }

    /**
     * @param int $userId Person looked for
     * @return ?int The person when a session holds them now, or null
     * @throws DatabaseException When the query fails
     */
    private static function boundUserIdOf(int $userId): ?int
    {
        Database::sql('SELECT `user_id` FROM `hilos_session` WHERE `user_id` = ?', [$userId]);
        $row = Database::row();

        return $row === null ? null : (int)$row['user_id'];
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade whose features and texts are read
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * @return list<string> Runtime collections the holder writes, claimed for the case
     */
    private static function daemonCollections(): array
    {
        return [
            StateHilosSessionRotation::RT_COLLECTION,
            StateHilosSessionToastStack::RT_COLLECTION,
            StateHilosOAuthTrip::RT_COLLECTION,
            StateRecoveryWaiter::RT_COLLECTION,
            StateRegistrationWaiter::RT_COLLECTION,
            StateHilosCodeSendAttempt::RT_COLLECTION,
            StateHilosProfileFlow::RT_COLLECTION,
        ];
    }
}

/**
 * Fixture project that draws a sign-in surface, keeps its people, and declares no texts - the
 * standard access log.
 */
abstract class SignInNoticeTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::HILOS_USERS];
}

/**
 * Fixture project whose privacy text deviates from standard.access_log.
 */
abstract class SignInNoticeWithoutLogTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::HILOS_USERS];
    protected const ?string LEGAL_CATALOG = SignInNoticeWithoutLogTestCatalog::class;
}

/**
 * A privacy text that keeps no access log, as the chat demo's does.
 */
final class SignInNoticeWithoutLogTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        $on = '2026-09-20';

        return [
            LegalDocument::PRIVACY->value => [
                new LegalRevision(LegalDocument::PRIVACY, $on, $on, 1, LegalSignificance::SUBSTANTIAL, $on, [
                    new Deviation(
                        StandardSetCatalog::CLAUSE_ACCESS_LOG,
                        DeviationDirection::STRICTER,
                        'No access logs are kept',
                        dirname(__DIR__) . '/Unit/Auth/AccessLog/Fixtures/privacy/standard.access_log.' . $on . '.txt',
                    ),
                ]),
            ],
        ];
    }
}

/**
 * Sessions library of the fixture project; its admin:create mint names the person the case seeded.
 */
final class SignInNoticeTestHolder extends AbstractSessionsLibraryAgent
{
    /** Person the mint reports; the case seeds the row. */
    public const int MINTED_ID = 296;

    /** @var bool Whether admin:create reached the mint */
    public bool $minted = false;

    public function onStop(): void
    {
    }

    /**
     * Records the call and names the person the case seeded for it.
     *
     * @return int Id of the administrator just minted
     */
    protected function mintAdminUser(): int
    {
        $this->minted = true;

        return self::MINTED_ID;
    }
}

/**
 * Runtime holding the fixture's session-stage connection roster, empty.
 */
final class SignInNoticeTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[SignInNoticeTestConnections::RT_COLLECTION] = SignInNoticeTestConnections::init();
    }
}

/**
 * Session-stage connection collection of the fixture.
 */
final class SignInNoticeTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'signInNoticeTestConnections';
    public const string STATE_CLASS = SignInNoticeTestConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class SignInNoticeTestConnection extends HilosSessionConnection
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
     * @return array<string, mixed> No project fields
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
