<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccessLog\AccessLogPolicy;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
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
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * The account access log end to end (HIL-1174): what the sessions library writes on a sign-in and
 * on a handshake, what it keeps on the session, and what its hourly sweep removes.
 *
 * The cases drive the same library the daemon runs - a rebind frame for a sign-in, a handshake
 * for a connection, a tick for the sweep - over the real tables. The erasure and the merge are
 * pinned where their own machinery is: {@see AccountErasureIntegrationTest} and
 * {@see AccountMergeCommandRouteIntegrationTest}.
 */
final class AccessLogIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string CREATED_AT = '2026-09-26 10:00:00';

    private const string SESSION_TOKEN = 'a1000000000000000000000000001174';

    private const string ACCEPT_KEY = 'accept-access-log';

    private const int USER_ID = 1174;

    private const int ADMIN_ID = 1175;

    private const string FIRST_ADDRESS = '203.0.113.10';

    private const string SECOND_ADDRESS = '2001:db8::1174';

    /** One row beyond the sweep's batch proves the tail goes on the next tick. */
    private const int BATCH_PLUS_ONE = 501;

    private string $boundAppClass;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

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
        self::bindAppClass(AccessLogTestHilos::class);
        Hilos::$sr = new SignalRouter();
        $rt = new AccessLogTestRtContext();
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
            "INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES (?, 'Person', 0), (?, 'Administrator', 1)",
            [self::USER_ID, self::ADMIN_ID],
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
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    /**
     * A sign-in writes one row, with the address the handshake of the signing-in tab left on the session.
     *
     * @throws HilosException When the handshake or the sign-in fails
     */
    public function testASignInWritesOneRowWithTheSessionsAddress(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $holder = new AccessLogTestHolder();
        $this->handshake($holder, self::FIRST_ADDRESS);
        self::assertSame([], self::rows(), 'A guest is nobody to log');

        $this->rebind($holder, self::USER_ID, null);

        self::assertSame([[self::USER_ID, AccessLogEvent::SIGN_IN->value, self::FIRST_ADDRESS]], self::rows());
    }

    /**
     * A signed-in session that comes back from the address it has gives no row; from a new one it
     * gives a row and keeps the new address.
     *
     * @throws HilosException When a handshake fails
     */
    public function testOnlyANewAddressOfASignedInSessionIsLogged(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new AccessLogTestHolder();
        $this->handshake($holder, self::FIRST_ADDRESS);
        $this->handshake($holder, self::FIRST_ADDRESS);

        self::assertSame(
            [[self::USER_ID, AccessLogEvent::NEW_ADDRESS->value, self::FIRST_ADDRESS]],
            self::rows(),
            'A session signed in before addresses were kept is logged on its first handshake, once',
        );

        $this->handshake($holder, self::SECOND_ADDRESS);

        self::assertSame(
            [
                [self::USER_ID, AccessLogEvent::NEW_ADDRESS->value, self::FIRST_ADDRESS],
                [self::USER_ID, AccessLogEvent::NEW_ADDRESS->value, self::SECOND_ADDRESS],
            ],
            self::rows(),
        );
        self::assertSame(self::SECOND_ADDRESS, self::sessionAddress());
    }

    /**
     * A guest keeps the address it came from, and nothing is logged; a transport that gave no
     * address changes nothing.
     *
     * @throws HilosException When a handshake fails
     */
    public function testAGuestKeepsItsAddressAndIsNotLogged(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $holder = new AccessLogTestHolder();
        $this->handshake($holder, self::FIRST_ADDRESS);
        $this->handshake($holder, self::SECOND_ADDRESS);
        $this->handshake($holder, null);

        self::assertSame(self::SECOND_ADDRESS, self::sessionAddress());
        self::assertSame([], self::rows());
    }

    /**
     * An administrator taking over a person's session is not the person using the account; the
     * administrator returning to themselves is a sign-in of theirs.
     *
     * @throws HilosException When a handshake or a rebind fails
     */
    public function testATakeoverIsNotLoggedAndTheReturnIsLoggedForTheAdministrator(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::ADMIN_ID, self::CREATED_AT, null);
        Database::sqlRun('UPDATE `hilos_session` SET `ip_address` = ? WHERE `token` = ?', [self::FIRST_ADDRESS, self::SESSION_TOKEN]);
        $holder = new AccessLogTestHolder();

        $this->rebind($holder, self::USER_ID, self::ADMIN_ID);
        $this->handshake($holder, self::SECOND_ADDRESS);

        self::assertSame([], self::rows(), 'Neither the takeover nor its new address is the person\'s use');

        $this->rebind($holder, self::ADMIN_ID, null);

        self::assertSame([[self::ADMIN_ID, AccessLogEvent::SIGN_IN->value, self::SECOND_ADDRESS]], self::rows());
    }

    /**
     * The sweep removes rows past twelve months and keeps the younger ones; a full batch goes on
     * at the next tick without waiting for the hour.
     *
     * @throws HilosException When the sweep fails
     */
    public function testTheSweepRemovesRowsPastTheirLifeBatchByBatch(): void
    {
        $old = date('Y-m-d H:i:s', (int)strtotime('-13 months'));
        $young = date('Y-m-d H:i:s', (int)strtotime('-11 months'));
        for ($index = 0; $index < self::BATCH_PLUS_ONE; $index++) {
            self::seedRow(self::USER_ID, $old);
        }
        self::seedRow(self::USER_ID, $young);

        $holder = $this->runSweep();

        self::assertSame(2, self::rowCount(), 'One batch leaves the tail and the young row');

        $holder->onTick();

        self::assertSame([$young], self::moments());
    }

    /**
     * A privacy text deviating from standard.access_log keeps no log: a sign-in and a new address
     * write nothing, the session still keeps its address, and the sweep removes every row already
     * written, young ones included.
     *
     * @throws HilosException When a handshake, the sign-in or the sweep fails
     */
    public function testATextWithoutTheLogWritesNothingAndSweepsEverything(): void
    {
        self::bindAppClass(AccessLogWithoutLogTestHilos::class);
        self::assertFalse(AccessLogPolicy::keepsLog());
        self::seedRow(self::USER_ID, date('Y-m-d H:i:s', (int)strtotime('-1 day')));
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $holder = new AccessLogTestHolder();
        $this->handshake($holder, self::FIRST_ADDRESS);
        $this->rebind($holder, self::USER_ID, null);
        $this->handshake($holder, self::SECOND_ADDRESS);

        self::assertSame(self::SECOND_ADDRESS, self::sessionAddress());
        self::assertSame(1, self::rowCount(), 'Only the row written before the text changed');

        $this->runSweep();

        self::assertSame(0, self::rowCount());
    }

    /**
     * Opens one connection of the session through the library's handshake.
     *
     * @param AccessLogTestHolder $holder Sessions library of the case
     * @param ?string $clientIp Address the transport gives, or null when it gives none
     * @throws HilosException When the handshake fails
     */
    private function handshake(AccessLogTestHolder $holder, ?string $clientIp): void
    {
        $holder->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: self::ACCEPT_KEY,
                cookies: [],
                clientIp: $clientIp,
                sessionToken: self::SESSION_TOKEN,
            ),
            'test',
            'handshake',
        );
        $this->drain();
    }

    /**
     * Binds the session to a person through the rebind frame, the way an operator does.
     *
     * No connection asks, so nothing is rotated and the session keeps its token.
     *
     * @param AccessLogTestHolder $holder Sessions library of the case
     * @param int $userId Person the session acts as afterwards
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null when there is none
     * @throws HilosException When the rebind fails
     */
    private function rebind(AccessLogTestHolder $holder, int $userId, ?int $impersonatorUserId): void
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
        $this->drain();
    }

    /**
     * Arms the holder and runs one tick with the access log sweep due.
     *
     * @return AccessLogTestHolder The holder, kept for a following tick
     * @throws HilosException When the tick fails
     */
    private function runSweep(): AccessLogTestHolder
    {
        $holder = new AccessLogTestHolder();
        $holder->onStart();
        new ReflectionProperty(AbstractSessionsLibraryAgent::class, 'accessLogSweepBacklog')->setValue($holder, true);
        $holder->onTick();

        return $holder;
    }

    /** Empties the signal queue. */
    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // dropped
        }
    }

    /**
     * @return ?string Address the case's session keeps, read past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function sessionAddress(): ?string
    {
        Database::sql('SELECT `ip_address` FROM `hilos_session` WHERE `token` = ?', [self::SESSION_TOKEN]);
        $row = Database::row();
        self::assertNotNull($row, 'The session keeps its token: nothing here rotates it');

        return $row['ip_address'] === null ? null : (string)$row['ip_address'];
    }

    /**
     * @param int $userId Person whose account was used
     * @param string $occurredAt Moment of the use
     * @throws DatabaseException When the insert fails
     */
    private static function seedRow(int $userId, string $occurredAt): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_access_log` (`user_id`, `event`, `ip_address`, `occurred_at`) VALUES (?, ?, ?, ?)',
            [$userId, AccessLogEvent::SIGN_IN->value, self::FIRST_ADDRESS, $occurredAt],
        );
    }

    /**
     * @return list<array{int, string, ?string}> Person, event and address of every row, in the order written
     * @throws DatabaseException When the query fails
     */
    private static function rows(): array
    {
        Database::sql('SELECT `user_id`, `event`, `ip_address` FROM `hilos_access_log` ORDER BY `id`');

        return array_map(
            static fn (array $row): array => [
                (int)$row['user_id'],
                (string)$row['event'],
                $row['ip_address'] === null ? null : (string)$row['ip_address'],
            ],
            Database::rows(),
        );
    }

    /**
     * @return list<string> Moment of every row, in the order written
     * @throws DatabaseException When the query fails
     */
    private static function moments(): array
    {
        Database::sql('SELECT `occurred_at` FROM `hilos_access_log` ORDER BY `id`');

        return array_map(static fn (array $row): string => (string)$row['occurred_at'], Database::rows());
    }

    /**
     * @return int Rows of the access log
     * @throws DatabaseException When the count query fails
     */
    private static function rowCount(): int
    {
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_access_log`');

        return (int)(Database::row()['count'] ?? 0);
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade whose features and texts are read
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * @return list<string> Runtime collections the holder's other sweeps write, claimed for the case
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
 * Fixture project that draws a sign-in surface and declares no texts - the standard log.
 */
abstract class AccessLogTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Fixture project whose privacy text deviates from standard.access_log.
 */
abstract class AccessLogWithoutLogTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    protected const ?string LEGAL_CATALOG = AccessLogWithoutLogTestCatalog::class;
}

/**
 * A privacy text that keeps no access log, as the chat demo's does.
 */
final class AccessLogWithoutLogTestCatalog implements LegalCatalogProviderInterface
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
 * Sessions library of the fixture project.
 */
final class AccessLogTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Runtime holding the fixture's session-stage connection roster, empty.
 */
final class AccessLogTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[AccessLogTestConnections::RT_COLLECTION] = AccessLogTestConnections::init();
    }
}

/**
 * Session-stage connection collection of the fixture.
 */
final class AccessLogTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'accessLogTestConnections';
    public const string STATE_CLASS = AccessLogTestConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class AccessLogTestConnection extends HilosSessionConnection
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
