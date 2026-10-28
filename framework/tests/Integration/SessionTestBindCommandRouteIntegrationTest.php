<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Users\AccountStandingResolver;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateSessionConnection;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Drives the real session owner and observes its ordinary sign-in effects. */
final class SessionTestBindCommandRouteIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = '18250000000000000000000000000002';
    private const string OTHER_TOKEN = '18250000000000000000000000000003';

    private string $previousAppClass;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;
    private ?AnalyticsCollector $previousAnalytics = null;
    private SessionTestBindRouteLibrary $library;
    /** @var list<SignalDTO> All observed frames from the last command */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        SessionTestBindHilos::initBrowser();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousAnalytics = Hilos::$ac;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionTestBindRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::registerDaemon(SessionTestBindRuntime::fixtureConnections);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        Hilos::$ac = new AnalyticsCollector();
        $this->library = new SessionTestBindRouteLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
        AccountStandingResolver::forgetAll();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (42, 'Fixture person'), (43, 'Other person')");
        self::seedSession(self::TOKEN, null, '2026-01-01 00:00:00', null);
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        RtTruthSourceRegistry::unregisterDaemon(SessionTestBindRuntime::fixtureConnections);
        AccountStandingResolver::forgetAll();
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        Hilos::$ac = $this->previousAnalytics;
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    public function testBindsTheExistingBrowserAndPublishesOrdinaryStateWithoutRotationAckOrNotice(): void
    {
        Database::sqlRun('UPDATE `hilos_session` SET `ip_address` = ?, `browser_time_zone` = ? WHERE `token` = ?',
            ['203.0.113.25', 'Europe/Warsaw', self::TOKEN]);
        $sessionId = Hilos::$db->sessions->findByToken(self::TOKEN)->id;
        Hilos::$rt->fixtureConnections->actions->register('first-tab', null, self::TOKEN, $sessionId);
        Hilos::$rt->fixtureConnections->actions->register('second-tab', null, self::TOKEN, $sessionId);
        Hilos::$rt->fixtureConnections->actions->register('another-browser', null, self::OTHER_TOKEN);
        $this->drain();

        $reply = $this->command(self::payload());
        self::assertTrue($reply->isOk(), json_encode($reply->payload));
        self::assertSame(['sessionId' => $sessionId, 'userId' => 42], $reply->payload);
        $row = self::rawSession();
        self::assertSame(self::TOKEN, $row['token']);
        self::assertSame(42, (int)$row['user_id']);
        self::assertNull($row['pending_ack']);
        self::assertSame('Europe/Warsaw', $row['browser_time_zone']);
        $states = $this->named(HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(1, $states);
        self::assertInstanceOf(AgentSignalData::class, $states[0]->data);
        $state = $states[0]->data->data;
        self::assertInstanceOf(SessionStateSignalData::class, $state);
        self::assertSame(['first-tab', 'second-tab'], $state->acceptKeys);
        self::assertSame($sessionId, $state->sessionId);
        self::assertSame(42, $state->userId);
        self::assertSame(self::TOKEN, $state->sessionToken);
        self::assertNull($state->rotationTicket);
        self::assertNull($state->pendingAck);
        self::assertNull($state->pendingAuthStep);
        self::assertNull($state->requestId);
        self::assertNull($state->action);
        self::assertNull($state->outcome);
        self::assertSame([], $this->named(HilosSignalConstants::HILOS_SESSION_REBIND));
        self::assertSame([], $this->named(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertCount(0, Hilos::$rt->hilosSessionRotations);
        Database::sql('SELECT `user_id`, `event`, `ip_address` FROM `hilos_access_log`');
        self::assertSame([['user_id' => '42', 'event' => 'sign_in', 'ip_address' => '203.0.113.25']], Database::rows());
        $zones = $this->named(HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE);
        self::assertCount(1, $zones);
        self::assertSame(42, $zones[0]->data->data->userId);
        self::assertSame('Europe/Warsaw', $zones[0]->data->data->lastBrowserTimeZone);
        $records = $this->analyticsRecords();
        self::assertCount(2, $records);
        self::assertSame('browser_session_identity', $records[0]['t']);
        self::assertSame(self::TOKEN, $records[0]['sessionToken']);
        self::assertSame('user_id', $records[0]['identityType']);
        self::assertSame('42', $records[0]['identityValue']);
        self::assertSame('person_event', $records[1]['t']);
        self::assertSame('sign_in', $records[1]['eventKind']);
        self::assertSame(42, $records[1]['userId']);
        self::assertSame($sessionId, $records[1]['sessionId']);
        self::assertSame(self::TOKEN, $records[1]['sessionToken']);
        self::assertSame('203.0.113.25', $records[1]['ip']);
        self::assertNull($records[1]['subjectUserId']);
    }

    /** @return iterable<string, array{array}> Bad JSON types and unknown addresses */
    public static function invalidPayloads(): iterable
    {
        foreach (['sessionToken', 'userId'] as $key) {
            $payload = self::payload();
            unset($payload[$key]);
            yield 'missing ' . $key => [$payload];
        }
        foreach ([null, false, true, 42.0, '42', '1e2', 0, -1, [], 9999] as $i => $id) {
            yield 'id ' . $i => [[...self::payload(), 'userId' => $id]];
        }
        foreach ([null, true, 42, [], '', 'bad', self::OTHER_TOKEN, strtoupper('abcdef0123456789abcdef0123456789')] as $i => $token) {
            yield 'token ' . $i => [[...self::payload(), 'sessionToken' => $token]];
        }
    }

    /** @param array $payload Malformed or unknown target */
    #[DataProvider('invalidPayloads')]
    public function testRefusesBadOrUnknownAddressesWithoutRebinding(array $payload): void
    {
        $before = self::rawSession();
        self::assertFalse($this->command($payload)->isOk());
        $this->assertNoBindEffects($before);
    }

    /** @return iterable<string, array{string, int|string}> Occupied or unfinished browsers */
    public static function nonIdleSessions(): iterable
    {
        yield 'already this user' => ['user_id', 42];
        yield 'another user' => ['user_id', 43];
        yield 'impersonated' => ['impersonator_user_id', 43];
        yield 'registration' => ['pending_registration_identifier', 'unfinished@example.test'];
        yield 'second factor' => ['pending_second_factor_user_id', 42];
        yield 'unread ack' => ['pending_ack', 'auth_registered'];
        yield 'blocked notice' => ['blocked_user_id', 43];
    }

    /**
     * @param string $column Seeded busy marker
     * @param int|string $value Existing flow or person
     */
    #[DataProvider('nonIdleSessions')]
    public function testRefusesEveryOccupiedOrUnfinishedBrowser(string $column, int|string $value): void
    {
        Database::sqlRun('UPDATE `hilos_session` SET `' . $column . '` = ? WHERE `token` = ?', [$value, self::TOKEN]);
        $before = self::rawSession();
        self::assertFalse($this->command(self::payload())->isOk());
        $this->assertNoBindEffects($before);
    }

    public function testRefusesBlockedAndMergedPeopleById(): void
    {
        Database::sqlRun('UPDATE `hilos_user` SET `block` = 1 WHERE `id` = 42');
        Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`) VALUES (43, 42)');
        $before = self::rawSession();
        foreach ([42, 43] as $id) {
            self::assertFalse($this->command([...self::payload(), 'userId' => $id])->isOk());
            $this->assertNoBindEffects($before);
        }
    }

    public function testADeletedIdDoesNotFindAReplacementByItsOldEmail(): void
    {
        Database::sqlRun(
            "INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `verified`) VALUES (43, 'magic_link', 'old@example.test', 1)",
        );
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = 42');
        $before = self::rawSession();
        self::assertFalse($this->command(self::payload())->isOk());
        $this->assertNoBindEffects($before);
    }

    /**
     * @param array<string, mixed> $payload Command boundary
     * @return CommandReplyDTO Exactly one answer
     */
    private function command(array $payload): CommandReplyDTO
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), fn () => $this->library->onSignalCommand(
            new CommandRequestDTO('bind-cid', CliCommands::SESSION_TEST_BIND, $payload), '', '',
        ));
        Hilos::$ac->flush();
        $this->frames = $this->drain();
        $replies = array_values(array_filter($this->frames, static fn (SignalDTO $frame): bool => $frame->data instanceof CommandReplyDTO));
        self::assertCount(1, $replies, 'Every route answers once');
        self::assertSame('bind-cid', $replies[0]->data->correlationId);

        return $replies[0]->data;
    }

    /** @param array $before Stored browser state before the refusal */
    private function assertNoBindEffects(array $before): void
    {
        self::assertSame($before, self::rawSession());
        self::assertSame([], $this->named(HilosSignalConstants::HILOS_SESSION_STATE));
        self::assertSame([], $this->named(HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE));
        self::assertSame([], $this->named(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame([], $this->analyticsRecords());
        Database::sql('SELECT COUNT(*) AS n FROM `hilos_access_log`');
        self::assertSame(0, (int)Database::row()['n']);
    }

    /** @return array<string, mixed> Exact existing browser and user address */
    private static function payload(): array
    {
        return ['sessionToken' => self::TOKEN, 'userId' => 42];
    }

    /** @return array<string, mixed> Session row independent of ORM memory */
    private static function rawSession(): array
    {
        Database::sql('SELECT * FROM `hilos_session` WHERE `token` = ?', [self::TOKEN]);

        return Database::row();
    }

    /** @return list<SignalDTO> Frames queued since the last drain */
    private function drain(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $signal;
        }

        return $frames;
    }

    /**
     * @param string $name Signal name
     * @return list<SignalDTO> Matching command effects
     */
    private function named(string $name): array
    {
        return array_values(array_filter($this->frames, static fn (SignalDTO $frame): bool => $frame->signalName->getName() === $name));
    }

    /** @return list<array<string, mixed>> Analytics records emitted by the real collector */
    private function analyticsRecords(): array
    {
        $records = [];
        foreach ($this->named(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND) as $frame) {
            self::assertInstanceOf(AgentSignalData::class, $frame->data);
            self::assertInstanceOf(AnalyticsJournalAppendSignalData::class, $frame->data->data);
            foreach ($frame->data->data->lines as $line) {
                $records[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $records;
    }
}

final class SessionTestBindRouteLibrary extends AbstractSessionsLibraryAgent
{
}

abstract class SessionTestBindHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = null;
}

/** @property-read SessionTestBindConnections $fixtureConnections Fixture browser sockets */
final class SessionTestBindRuntime extends RtContext
{
    public const string fixtureConnections = 'fixtureConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::fixtureConnections] = SessionTestBindConnectionStates::init();
        $this->setRepresent(self::fixtureConnections, SessionTestBindConnections::class,
            SessionTestBindConnectionsActions::class, SessionTestBindConnectionActions::class);
    }
}

final class SessionTestBindConnectionStates extends StateSessionConnections
{
    public const string STATE_CLASS = SessionTestBindConnectionState::class;
}

final class SessionTestBindConnectionState extends StateSessionConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row No fixture-specific fields */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No fixture-specific fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff No fixture-specific fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosSessionConnections<SessionTestBindConnection, SessionTestBindConnectionsActions> */
final class SessionTestBindConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Session connection state
     * @return SessionTestBindConnection Fixture socket view
     */
    protected function createRtItem(RtState $state): SessionTestBindConnection
    {
        return new SessionTestBindConnection($state);
    }
}

/** @extends HilosSessionConnection<SessionTestBindConnectionState> */
final class SessionTestBindConnection extends HilosSessionConnection
{
}

final class SessionTestBindConnectionsActions extends HilosSessionConnectionsActions
{
}

final class SessionTestBindConnectionActions extends HilosConnectionActions
{
}
