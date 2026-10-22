<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateSessionConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Throwable;

/**
 * The zone a browser names on connecting, carried from the handshake to the session row and to the
 * person at the keyboard on real tables, real ownership claims and the declarative router (HIL-1582).
 *
 * The handshake enters the production sessions library the way the master hands it over, serialized;
 * every frame it queues is serialized again and routed by the router of a fixture registry: the
 * session state to a minimal project host, the report to the indexed agent of the person. That agent
 * is the framework's own with one seam, a write of the zone a case can make fail once. The change-log
 * database is not configured here, so a write the agent skips is read off the announcements the
 * tables send: a row that did not change announces nothing.
 */
final class BrowserTimeZoneSessionIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int PERSON = 7;
    private const int ADMIN = 8;
    private const int STRANGER = 9;
    private const string TOKEN_PERSON = '0123456789abcdef0123456789abcdef';
    private const string TOKEN_PERSON_OTHER = '89abcdef0123456789abcdef01234567';
    private const string TOKEN_GUEST = 'fedcba9876543210fedcba9876543210';
    private const string TOKEN_ADMIN = 'aaaabbbbccccddddaaaabbbbccccdddd';
    private const string TAB_PERSON = 'browser-zone-tab-person';
    private const string TAB_PERSON_OTHER = 'browser-zone-tab-person-other';
    private const string TAB_GUEST = 'browser-zone-tab-guest';
    private const string TAB_ADMIN = 'browser-zone-tab-admin';
    private const string TOKYO = 'Asia/Tokyo';
    private const string NEW_YORK = 'America/New_York';
    private const string AUCKLAND = 'Pacific/Auckland';
    /** A backward alias, kept as the browser named it. */
    private const string KIEV = 'Europe/Kiev';

    private BrowserTimeZoneSessionTestUsersLibrary $users;
    private BrowserTimeZoneSessionTestSessionsLibrary $sessions;
    private BrowserTimeZoneSessionTestHost $host;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRuntime = null;
    private string $boundAppClass;

    public function testTheFirstGuestHandshakeWritesTheSessionWithNobodyInIt(): void
    {
        self::assertNull(self::sessionRow(self::TOKEN_GUEST));

        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST, self::KIEV);
        $frames = $this->carryFrames();

        self::assertSame([null, self::KIEV], self::storedSession(self::TOKEN_GUEST));
        self::assertSame([], $this->reports($frames));
        foreach ([self::PERSON, self::ADMIN, self::STRANGER] as $userId) {
            self::assertNull(self::storedPerson($userId));
        }
        self::assertCount(1, $this->handshakeFrames($frames));
        $this->assertNothingShowsTheZones($frames, self::KIEV);
    }

    public function testASignedInHandshakeWritesTheSessionAndThePersonThroughTheirOwnOwners(): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $queued = $this->takeQueued();

        // The library's own step wrote its row and asked the person's agent; the person is not written yet.
        self::assertSame([self::PERSON, self::TOKYO], self::storedSession(self::TOKEN_PERSON));
        self::assertNull(self::storedPerson(self::PERSON));
        self::assertSame([[self::PERSON, self::TOKYO]], $this->reports($queued));
        self::assertSame([self::TOKYO], $this->updates($queued, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertSame([], $this->updates($queued, HilosDbContext::users, EntityUser::last_browser_time_zone));

        $frames = $this->carryFrames($queued);

        self::assertSame(self::TOKYO, self::storedPerson(self::PERSON));
        $person = $this->personAgent(self::PERSON);
        self::assertInstanceOf(BrowserTimeZoneSessionTestPerson::class, $person);
        self::assertSame([$person->getId()], $person->writtenBy);
        self::assertSame([self::TOKYO], $this->updates($frames, HilosDbContext::users, EntityUser::last_browser_time_zone));
        self::assertCount(1, $this->handshakeFrames($frames));
        $this->assertNothingShowsTheZones($frames, self::TOKYO);
        self::assertArrayNotHasKey('lastBrowserTimeZone', Hilos::$db->users[self::PERSON]->toArray());
        self::assertArrayNotHasKey('browserTimeZone', Hilos::$db->sessions->findByToken(self::TOKEN_PERSON)->toArray());
    }

    /** @return iterable<string, array{?string}> What a browser may send that is not a usable zone */
    public static function unusableZones(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'padded' => [' ' . self::TOKYO];
        yield 'other case' => [strtolower(self::TOKYO)];
        yield 'offset' => ['+09:00'];
        yield 'abbreviation' => ['JST'];
        yield 'unknown' => ['Mars/Olympus_Mons'];
    }

    /** @param ?string $named What the browser put in the parameter, or null when it sent none */
    #[DataProvider('unusableZones')]
    public function testAMissingOrUnusableZoneClearsTheSessionAndKeepsThePerson(?string $named): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->carryFrames();

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, $named);
        $frames = $this->carryFrames();

        self::assertSame([self::PERSON, null], self::storedSession(self::TOKEN_PERSON));
        self::assertSame(self::TOKYO, self::storedPerson(self::PERSON));
        self::assertSame([null], $this->updates($frames, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertSame([], $this->reports($frames));
        self::assertSame([], $this->updates($frames, HilosDbContext::users, EntityUser::last_browser_time_zone));
        self::assertCount(1, $this->handshakeFrames($frames));
    }

    public function testAReconnectWithAnotherZoneMovesBothAndTheSameZoneSyncsNothing(): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->carryFrames();

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::NEW_YORK);
        $moved = $this->carryFrames();

        self::assertSame([self::PERSON, self::NEW_YORK], self::storedSession(self::TOKEN_PERSON));
        self::assertSame(self::NEW_YORK, self::storedPerson(self::PERSON));
        self::assertSame([self::NEW_YORK], $this->updates($moved, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertSame([self::NEW_YORK], $this->updates($moved, HilosDbContext::users, EntityUser::last_browser_time_zone));

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::NEW_YORK);
        $repeated = $this->carryFrames();

        // The report goes again, since an earlier one may not have arrived; neither owner writes anything.
        self::assertSame([[self::PERSON, self::NEW_YORK]], $this->reports($repeated));
        self::assertSame([], $this->updates($repeated, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertSame([], $this->updates($repeated, HilosDbContext::users, EntityUser::last_browser_time_zone));
        $person = $this->personAgent(self::PERSON);
        self::assertInstanceOf(BrowserTimeZoneSessionTestPerson::class, $person);
        self::assertCount(2, $person->writtenBy, 'The repeat never reaches the write, nor the receipt around it');
        self::assertSame([self::PERSON, self::NEW_YORK], self::storedSession(self::TOKEN_PERSON));
        self::assertSame(self::NEW_YORK, self::storedPerson(self::PERSON));
    }

    public function testTwoBrowsersLeaveThePersonTheZoneTheServerTookLast(): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->handshake(self::TAB_PERSON_OTHER, self::TOKEN_PERSON_OTHER, self::AUCKLAND);
        $frames = $this->carryFrames();

        self::assertSame([[self::PERSON, self::TOKYO], [self::PERSON, self::AUCKLAND]], $this->reports($frames));
        self::assertSame([self::PERSON, self::TOKYO], self::storedSession(self::TOKEN_PERSON));
        self::assertSame([self::PERSON, self::AUCKLAND], self::storedSession(self::TOKEN_PERSON_OTHER));
        self::assertSame(self::AUCKLAND, self::storedPerson(self::PERSON));

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->carryFrames();

        self::assertSame(self::TOKYO, self::storedPerson(self::PERSON));
    }

    public function testAReportLostOnTheWayOrRefusedByTheWriteIsCarriedAgainByTheNextConnection(): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $lost = array_values(array_filter(
            $this->takeQueued(),
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() !== HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
        ));
        $this->carryFrames($lost);

        self::assertSame([self::PERSON, self::TOKYO], self::storedSession(self::TOKEN_PERSON));
        self::assertNull(self::storedPerson(self::PERSON));

        $this->asPerson(self::PERSON, static function (AbstractUserAgent $agent): void {
            self::assertInstanceOf(BrowserTimeZoneSessionTestPerson::class, $agent);
            $agent->nextWriteFailure = new DatabaseException('Injected browser time zone write failure');
        });
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $refused = $this->carryFrames();

        self::assertSame([[self::PERSON, self::TOKYO]], $this->reports($refused));
        self::assertSame([], $this->updates($refused, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertNull(self::storedPerson(self::PERSON));

        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $carried = $this->carryFrames();

        self::assertSame([[self::PERSON, self::TOKYO]], $this->reports($carried));
        self::assertSame([], $this->updates($carried, HilosDbContext::sessions, EntitySession::browser_time_zone));
        self::assertSame([self::TOKYO], $this->updates($carried, HilosDbContext::users, EntityUser::last_browser_time_zone));
        self::assertSame(self::TOKYO, self::storedPerson(self::PERSON));
    }

    /** @return iterable<string, array{?string}> Endings that bind a guest's browser to a person, by the ack they earn */
    public static function signIns(): iterable
    {
        yield 'sign-in' => [null];
        yield 'registration' => [SessionAck::REGISTERED];
    }

    /** @param ?string $ack Ack the ending earns */
    #[DataProvider('signIns')]
    public function testAGuestSignedInWithoutReconnectingFillsThePersonAndTheRotationKeepsTheZone(?string $ack): void
    {
        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST, self::AUCKLAND);
        $this->carryFrames();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::TOKEN_GUEST)->id;

        ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), fn () => $this->users->sendToAgent(
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
            new AuthSessionGrantSignalData(sessionToken: self::TOKEN_GUEST, userId: self::PERSON, acceptKey: self::TAB_GUEST, ack: $ack),
        ));
        $frames = $this->carryFrames();

        Database::sql('SELECT `token`, `user_id`, `browser_time_zone` FROM `hilos_session` WHERE `id` = ?', [$sessionId]);
        $row = Database::row();
        self::assertNotNull($row);
        self::assertNotSame(self::TOKEN_GUEST, $row['token'], 'The sign-in rotates the token');
        self::assertSame(self::PERSON, (int)$row['user_id']);
        self::assertSame(self::AUCKLAND, $row['browser_time_zone']);
        self::assertSame([[self::PERSON, self::AUCKLAND]], $this->reports($frames));
        self::assertSame(self::AUCKLAND, self::storedPerson(self::PERSON));
        $states = $this->framesNamed($frames, HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(1, $states);
        self::assertInstanceOf(AgentSignalData::class, $states[0]->data);
        self::assertInstanceOf(SessionStateSignalData::class, $states[0]->data->data);
        self::assertSame(self::PERSON, $states[0]->data->data->userId);
        self::assertSame($ack, $states[0]->data->data->pendingAck);
        self::assertNotNull($states[0]->data->data->rotationTicket);
        self::assertCount(1, $this->handshakeFrames($frames));
        $this->assertNothingShowsTheZones($frames, self::AUCKLAND);
    }

    public function testABrowserLetBackInAtItsHandshakeReportsOnlyTheZoneItNamesNow(): void
    {
        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST, self::TOKYO);
        $this->carryFrames();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::TOKEN_GUEST)->id;
        ExecutionContext::run(new ExecutionFrame(agentId: $this->sessions->getId()), static fn () => Hilos::$db->sessions
            ->findByToken(self::TOKEN_GUEST)->actions->holdBlockedNotice(self::PERSON, true));
        self::bindAppClass(BrowserTimeZoneSessionTestPeopleHilos::class);

        $this->handshake(self::TAB_GUEST, self::TOKEN_GUEST, self::NEW_YORK);
        $frames = $this->carryFrames();

        // The way back in binds the person before the zone is written: the zone the session held is never reported.
        Database::sql('SELECT `token`, `user_id`, `blocked_user_id`, `browser_time_zone` FROM `hilos_session` WHERE `id` = ?', [$sessionId]);
        $row = Database::row();
        self::assertNotNull($row);
        self::assertNotSame(self::TOKEN_GUEST, $row['token'], 'The way back in rotates the token');
        self::assertSame(self::PERSON, (int)$row['user_id']);
        self::assertNull($row['blocked_user_id']);
        self::assertSame(self::NEW_YORK, $row['browser_time_zone']);
        self::assertSame([[self::PERSON, self::NEW_YORK]], $this->reports($frames));
        self::assertSame(self::NEW_YORK, self::storedPerson(self::PERSON));
        $states = $this->framesNamed($frames, HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(1, $states);
        self::assertInstanceOf(AgentSignalData::class, $states[0]->data);
        self::assertInstanceOf(SessionStateSignalData::class, $states[0]->data->data);
        self::assertSame(self::PERSON, $states[0]->data->data->userId);
        self::assertNotNull($states[0]->data->data->rotationTicket);
        $this->assertNothingShowsTheZones($frames, self::TOKYO, self::NEW_YORK);
    }

    public function testATakeoverReportsToTheAdministratorAtTheKeyboardAndTheStopToTheAdministratorAgain(): void
    {
        $this->asPerson(self::PERSON, static fn () => Hilos::$db->users[self::PERSON]->actions->setLastBrowserTimeZone(self::KIEV));
        $this->handshake(self::TAB_ADMIN, self::TOKEN_ADMIN, self::TOKYO);
        $this->carryFrames();
        self::assertSame(self::TOKYO, self::storedPerson(self::ADMIN));

        $this->command(CliCommands::IMPERSONATE_START, [
            AdminCommandConstants::FIELD_SESSION_TOKEN => self::TOKEN_ADMIN,
            AdminCommandConstants::FIELD_TARGET_USER_ID => self::PERSON,
        ]);
        $started = $this->carryFrames();

        $this->assertCommandAnswered($started);
        self::assertSame([self::PERSON, self::ADMIN], self::takeover(self::TOKEN_ADMIN));
        self::assertSame([[self::ADMIN, self::TOKYO]], $this->reports($started));

        $this->handshake(self::TAB_ADMIN, self::TOKEN_ADMIN, self::NEW_YORK);
        $inside = $this->carryFrames();

        self::assertSame([[self::ADMIN, self::NEW_YORK]], $this->reports($inside));
        self::assertSame([self::PERSON, self::NEW_YORK], self::storedSession(self::TOKEN_ADMIN));
        self::assertSame(self::NEW_YORK, self::storedPerson(self::ADMIN));
        self::assertSame(self::KIEV, self::storedPerson(self::PERSON));

        $this->command(CliCommands::IMPERSONATE_STOP, [AdminCommandConstants::FIELD_SESSION_TOKEN => self::TOKEN_ADMIN]);
        $stopped = $this->carryFrames();

        $this->assertCommandAnswered($stopped);
        self::assertSame([self::ADMIN, null], self::takeover(self::TOKEN_ADMIN));
        self::assertSame([[self::ADMIN, self::NEW_YORK]], $this->reports($stopped));

        $this->handshake(self::TAB_ADMIN, self::TOKEN_ADMIN, self::AUCKLAND);
        $after = $this->carryFrames();

        self::assertSame([[self::ADMIN, self::AUCKLAND]], $this->reports($after));
        self::assertSame(self::AUCKLAND, self::storedPerson(self::ADMIN));
        self::assertSame(self::KIEV, self::storedPerson(self::PERSON));
        $this->assertNothingShowsTheZones([...$started, ...$inside, ...$stopped, ...$after], self::TOKYO, self::NEW_YORK, self::AUCKLAND, self::KIEV);
    }

    public function testEndingEverySessionKeepsThePersonsMemory(): void
    {
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->handshake(self::TAB_PERSON_OTHER, self::TOKEN_PERSON_OTHER, self::AUCKLAND);
        $this->handshake(self::TAB_ADMIN, self::TOKEN_ADMIN, self::NEW_YORK);
        $this->carryFrames();

        ExecutionContext::run(new ExecutionFrame(agentId: $this->sessions->getId()), static function (): void {
            foreach ([self::TOKEN_PERSON, self::TOKEN_PERSON_OTHER, self::TOKEN_ADMIN] as $token) {
                Hilos::$db->sessions->findByToken($token)->actions->delete();
            }
        });
        $this->carryFrames();

        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_session`');
        self::assertSame(0, (int)Database::row()['count']);
        self::assertSame(self::AUCKLAND, self::storedPerson(self::PERSON));
        self::assertSame(self::NEW_YORK, self::storedPerson(self::ADMIN));
    }

    public function testThePersonsRowIsWrittenOnlyByThatPersonsAgent(): void
    {
        // The sessions library holds the people for the merge and the erasure, yet the zone is not its write.
        $this->handshake(self::TAB_PERSON, self::TOKEN_PERSON, self::TOKYO);
        $this->takeQueued();
        self::assertNull(self::storedPerson(self::PERSON));

        ExecutionContext::run(new ExecutionFrame(agentId: $this->sessions->getId()), fn () => $this->sessions->sendToAgent(
            HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
            new UserLastBrowserTimeZoneWriteSignalData(self::STRANGER, self::NEW_YORK),
        ));
        $queued = $this->takeQueued();
        self::assertCount(1, $queued);
        self::assertSame((string)self::STRANGER, $this->agentDestination($queued[0])->agentIndex);
        self::assertInstanceOf(AgentSignalData::class, $queued[0]->data);
        $misdelivered = $queued[0]->data;
        try {
            $this->asPerson(self::PERSON, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
                $misdelivered,
                '',
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE,
            ));
            self::fail('The agent of one person took a report naming another');
        } catch (AgentException $refused) {
            self::assertStringContainsString('#' . self::STRANGER . ' reached the agent of user #' . self::PERSON, $refused->getMessage());
        }

        try {
            $this->asPerson(self::PERSON, static fn () => Hilos::$db->users[self::STRANGER]->actions->setLastBrowserTimeZone(self::NEW_YORK));
            self::fail('The agent of one person wrote the row of another');
        } catch (WriteNotAllowedException) {
        }

        self::assertNull(self::storedPerson(self::PERSON));
        self::assertNull(self::storedPerson(self::STRANGER));
    }

    /** @throws HilosException When the schema or fixture setup fails */
    protected function setUp(): void
    {
        parent::setUp();

        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES (?, ?, 0), (?, ?, 1), (?, ?, 0)', [
            self::PERSON, 'Browser zone person', self::ADMIN, 'Administrator', self::STRANGER, 'Stranger',
        ]);
        foreach ([self::TOKEN_PERSON => self::PERSON, self::TOKEN_PERSON_OTHER => self::PERSON,
            self::TOKEN_ADMIN => self::ADMIN] as $token => $userId
        ) {
            Hilos::$db->sessions->actions->createAnonymous($token)->actions->bindUser($userId);
        }
        $this->boundAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new BrowserTimeZoneSessionTestRouter();
        Hilos::$rt = new BrowserTimeZoneSessionTestRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        AccountStandingResolver::forgetAll();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new BrowserTimeZoneSessionTestUsersLibrary();
        $this->sessions = new BrowserTimeZoneSessionTestSessionsLibrary();
        $this->host = new BrowserTimeZoneSessionTestHost();
        foreach ([$this->users, $this->sessions, $this->host] as $agent) {
            OwnershipDeclaration::claimAll($agent);
        }
        $this->carryFrames();
    }

    /** @throws HilosException When the parent schema teardown fails */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->users, $this->sessions, $this->host] as $agent) {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
        }
        AccountStandingResolver::forgetAll();
        SourceChangeBus::reset();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRuntime;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    /**
     * @param string $userId Person addressed by the report
     * @return BrowserTimeZoneSessionTestPerson The framework's agent of the person, with its zone write observed
     */
    protected function newPersonAgent(string $userId): BrowserTimeZoneSessionTestPerson
    {
        return new BrowserTimeZoneSessionTestPerson($userId);
    }

    /**
     * Hands the sessions library a handshake the way the master does, serialized, its zone on the socket address.
     *
     * @param string $acceptKey Tab arriving or reconnecting
     * @param string $token Browser cookie token
     * @param ?string $browserTimeZone What the browser put in the parameter, or null when it sent none
     */
    private function handshake(string $acceptKey, string $token, ?string $browserTimeZone): void
    {
        $path = $browserTimeZone === null ? '/ws' : '/ws?' . BrowserTimeZone::QUERY_PARAM . '=' . rawurlencode($browserTimeZone);
        $handshake = WebSocketHandshakeSignalDTO::fromArray(new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: null,
            queryParams: RequestQueryParams::fromPath($path),
            sessionToken: $token,
        )->toArray());
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            fn () => $this->sessions->onSignalHandshake($handshake, 'test', 'handshake'),
        );
    }

    /**
     * Hands the sessions library an operator's command, serialized, with the impersonation settings at their defaults.
     *
     * The settings accessor knows the impersonation catalog only while the command runs: the identity
     * the host builds afterwards reads the installation's settings as every other case does.
     *
     * @param string $command Command wire name
     * @param array<string, mixed> $payload Command payload
     */
    private function command(string $command, array $payload): void
    {
        $request = CommandRequestDTO::fromArray(new CommandRequestDTO(
            correlationId: 'browser-zone-' . $command,
            command: $command,
            payload: $payload,
        )->toArray());
        $setting = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(ImpersonationSettingsCatalog::class);
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->sessions->getId()),
                fn () => $this->sessions->onSignalCommand($request, 'test', $command),
            );
        } finally {
            Hilos::$setting = $setting;
        }
    }

    /** @return list<SignalDTO> Frames queued so far, serialized as they travel and delivered to nobody yet */
    private function takeQueued(): array
    {
        $frames = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = SignalDTO::fromArray($queued->toArray());
        }

        return $frames;
    }

    /**
     * Delivers the frames already taken, then every frame queued on the way, until none is left.
     *
     * @param list<SignalDTO> $taken Frames taken off the queue before, in their order
     * @return list<SignalDTO> All frames observed, the real intermediate hops included
     */
    private function carryFrames(array $taken = []): array
    {
        $frames = [];
        $pending = $taken;
        while ($pending !== [] || ($pending = $this->takeQueued()) !== []) {
            $signal = array_shift($pending);
            $frames[] = $signal;
            if ($signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL) {
                continue;
            }
            $destination = $this->agentDestination($signal);
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            if ($destination->agentType === BrowserTimeZoneSessionTestPerson::AGENT_TYPE) {
                self::assertInstanceOf(UserLastBrowserTimeZoneWriteSignalData::class, $signal->data->data);
                self::assertSame((string)$signal->data->data->userId, $destination->agentIndex);
                $this->deliverToPerson($signal);
                continue;
            }
            $agent = match ($destination->agentType) {
                BrowserTimeZoneSessionTestUsersLibrary::AGENT_TYPE => $this->users,
                BrowserTimeZoneSessionTestSessionsLibrary::AGENT_TYPE => $this->sessions,
                BrowserTimeZoneSessionTestHost::AGENT_TYPE => $this->host,
                default => self::fail('Unexpected destination: ' . $destination->agentType),
            };
            ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => $agent->onSignalAgent(
                $signal->data,
                SignalSource::describe($signal->signalSource),
                $signal->signalName->getName(),
            ));
        }

        return $frames;
    }

    /**
     * @param SignalDTO $signal Real queued frame
     * @return AgentDestination Single declaratively resolved local agent
     */
    private function agentDestination(SignalDTO $signal): AgentDestination
    {
        $destinations = Hilos::$sr->getDestinations($signal);
        self::assertCount(1, $destinations);
        self::assertInstanceOf(AgentDestination::class, $destinations[0]);

        return $destinations[0];
    }

    /**
     * @param list<SignalDTO> $frames Observed frames
     * @param string $name Frame name
     * @return list<SignalDTO> Matching frames, in delivery order
     */
    private function framesNamed(array $frames, string $name): array
    {
        return array_values(array_filter($frames, static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name));
    }

    /**
     * Every report among the frames, each sent by the sessions library and resolved to the agent of the person it names.
     *
     * @param list<SignalDTO> $frames Observed frames
     * @return list<array{int, string}> Person and zone of each report, in order
     */
    private function reports(array $frames): array
    {
        $reports = [];
        foreach ($this->framesNamed($frames, HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE) as $signal) {
            self::assertStringContainsString(BrowserTimeZoneSessionTestSessionsLibrary::AGENT_TYPE, SignalSource::describe($signal->signalSource));
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            $report = $signal->data->data;
            self::assertInstanceOf(UserLastBrowserTimeZoneWriteSignalData::class, $report);
            $destination = $this->agentDestination($signal);
            self::assertSame(BrowserTimeZoneSessionTestPerson::AGENT_TYPE, $destination->agentType);
            self::assertSame((string)$report->userId, $destination->agentIndex);
            $reports[] = [$report->userId, $report->lastBrowserTimeZone];
        }

        return $reports;
    }

    /**
     * @param list<SignalDTO> $frames Observed frames
     * @param string $collection Table the update was announced for
     * @param string $column Column the update must have carried
     * @return list<mixed> The column's value in every announced update of the table that changed it, in order
     */
    private function updates(array $frames, string $collection, string $column): array
    {
        $values = [];
        foreach ($this->framesNamed($frames, SignalConstants::DB_SYNC_UPDATED) as $signal) {
            self::assertInstanceOf(DbSyncUpdatedSignalData::class, $signal->data);
            if ($signal->data->collectionKey === $collection && array_key_exists($column, $signal->data->row)) {
                $values[] = $signal->data->row[$column];
            }
        }

        return $values;
    }

    /**
     * @param list<SignalDTO> $frames Observed frames
     * @return list<WebSocketSignalData> Actual stamped handshake replies
     */
    private function handshakeFrames(array $frames): array
    {
        $handshakes = [];
        foreach ($this->framesNamed($frames, BrowserTimeZoneSessionTestHost::HANDSHAKE_RESPONSE) as $signal) {
            self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HandshakeResponseSignalData::class, $signal->data->data);
            $handshakes[] = $signal->data;
        }

        return $handshakes;
    }

    /**
     * No frame to a browser and no session state frame names either zone, or any of the zones the case used.
     *
     * @param list<SignalDTO> $frames Observed frames
     * @param string ...$zones Zones the case named
     */
    private function assertNothingShowsTheZones(array $frames, string ...$zones): void
    {
        foreach ($frames as $signal) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData) {
                $payload = $data->data->toArray();
            } elseif ($data instanceof AgentSignalData && $data->data instanceof SessionStateSignalData) {
                $payload = $data->data->toArray();
            } else {
                continue;
            }
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('rowserTimeZone', $json);
            foreach ($zones as $zone) {
                self::assertStringNotContainsString($zone, $json);
            }
        }
    }

    /** @param list<SignalDTO> $frames Observed frames, holding the one answer to the operator */
    private function assertCommandAnswered(array $frames): void
    {
        $replies = array_values(array_filter(
            $frames,
            static fn (SignalDTO $signal): bool => $signal->signalType->getType() === SignalTypeConstants::COMMAND_REPLY,
        ));
        self::assertCount(1, $replies);
        self::assertInstanceOf(CommandReplyDTO::class, $replies[0]->data);
        self::assertTrue($replies[0]->data->isOk());
    }

    /**
     * Makes the given facade the running one, the way a project's start binds it.
     *
     * @param class-string<Hilos> $hilosClass Facade whose declared features the library asks
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * @param string $token Session cookie token
     * @return array{?int, ?string} Person and browser zone of the session row, read past every collection
     */
    private static function storedSession(string $token): array
    {
        Database::sql('SELECT `user_id`, `browser_time_zone` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $row = Database::row();
        self::assertNotNull($row);

        return [$row['user_id'] === null ? null : (int)$row['user_id'], $row['browser_time_zone']];
    }

    /**
     * @param string $token Session cookie token
     * @return array{?int, ?int} Person the session acts as and the administrator behind it, read past every collection
     */
    private static function takeover(string $token): array
    {
        Database::sql('SELECT `user_id`, `impersonator_user_id` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $row = Database::row();
        self::assertNotNull($row);

        return [
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['impersonator_user_id'] === null ? null : (int)$row['impersonator_user_id'],
        ];
    }

    /**
     * @param int $userId Person to read
     * @return ?string The last usable zone a browser of the person named, read past every collection
     */
    private static function storedPerson(int $userId): ?string
    {
        Database::sql('SELECT `last_browser_time_zone` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);

        return $row['last_browser_time_zone'];
    }
}

/** Production users library; sends the sign-in grant it would send after a proof. */
final class BrowserTimeZoneSessionTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'browser_time_zone_test_users';
}

/** Production sessions library: handshake, sign-in, takeover and the report to the person. */
final class BrowserTimeZoneSessionTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'browser_time_zone_test_sessions';
}

/** The framework's agent of one person; a case sees in whose frame the zone is written, and can make the next write fail. */
final class BrowserTimeZoneSessionTestPerson extends AbstractUserAgent
{
    /** @var ?Throwable Failure the next write of the zone raises instead of writing, then forgets */
    public ?Throwable $nextWriteFailure = null;

    /** @var list<?string> Agent in whose frame each write of the zone ran, in order */
    public array $writtenBy = [];

    /**
     * @param string $lastBrowserTimeZone Zone reaching the owner's write
     * @throws Throwable When the case injected a failure or the guarded production write fails
     */
    protected function writeLastBrowserTimeZone(string $lastBrowserTimeZone): void
    {
        $this->writtenBy[] = ExecutionContext::currentAgentId();
        $failure = $this->nextWriteFailure;
        if ($failure !== null) {
            $this->nextWriteFailure = null;
            throw $failure;
        }

        parent::writeLastBrowserTimeZone($lastBrowserTimeZone);
    }
}

/** Minimal project session receiver, using the framework's identity builder and stamped send. */
final class BrowserTimeZoneSessionTestHost extends AbstractAgent
{
    public const string AGENT_TYPE = 'browser_time_zone_test_host';
    public const string HANDSHAKE_RESPONSE = 'handshake_response';
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class];
    public const array OWNS_RT = [BrowserTimeZoneSessionTestRuntime::zoneConnections => TruthSourceOperation::ALL];

    /** No lifecycle cleanup is needed in this in-process fixture. */
    public function onStop(): void
    {
    }

    /**
     * @param AgentSignalData $data Serialized session-state wrapper
     * @param string $sender Sender identity, unused by the fixture
     * @param string $name Session-state signal name
     * @throws HilosException When identity construction or runtime settlement fails
     * @throws LogicException When a collection's class constants are not configured
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionStateSignalData::class, $data->data);
                }
                $state = $data->data;
                $identity = $this->handshakeIdentity($state);
                foreach (Hilos::$rt->zoneConnections->acceptKeysForSessionFrame($state->acceptKeys, $state->sessionToken) as $acceptKey) {
                    if (!isset(Hilos::$rt->zoneConnections[$acceptKey])) {
                        Hilos::$rt->zoneConnections->actions->register($acceptKey, $state->userId, $state->sessionToken, $state->sessionId);
                    } else {
                        Hilos::$rt->zoneConnections[$acceptKey]->actions->bindUser($state->userId);
                    }
                    $this->sendHandshakeResponse(self::HANDSHAKE_RESPONSE, $acceptKey, $identity, $state);
                }
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

/** Fixture registry retains the inherited production action and indexed agent declarations. */
abstract class BrowserTimeZoneSessionTestHilos extends Hilos
{
    public const array AGENTS = [
        BrowserTimeZoneSessionTestUsersLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => BrowserTimeZoneSessionTestUsersLibrary::class],
        BrowserTimeZoneSessionTestSessionsLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => BrowserTimeZoneSessionTestSessionsLibrary::class],
        BrowserTimeZoneSessionTestPerson::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BrowserTimeZoneSessionTestPerson::class,
            AgentRegistryKey::INDEXED => true,
        ],
        BrowserTimeZoneSessionTestHost::AGENT_TYPE => [AgentRegistryKey::WORKER => BrowserTimeZoneSessionTestHost::class],
    ];
}

/** Fixture project that administers its people, so a handshake enforces an account block and lifts it. */
abstract class BrowserTimeZoneSessionTestPeopleHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::HILOS_USERS];
}

/** Uses ordinary topology resolution against the fixture project registry. */
final class BrowserTimeZoneSessionTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology owner */
    protected function hilosClass(): string
    {
        return BrowserTimeZoneSessionTestHilos::class;
    }
}

/** @property-read BrowserTimeZoneSessionTestConnections $zoneConnections Fixture sockets */
final class BrowserTimeZoneSessionTestRuntime extends RtContext
{
    public const string zoneConnections = 'zoneConnections';

    /**
     * Mounts a fully represented session-stage collection; every mutation uses actions.
     *
     * @throws HilosException When the fixture collection cannot be represented
     */
    public function configure(): void
    {
        $this->_stateCollections[self::zoneConnections] = BrowserTimeZoneSessionTestConnectionStates::init();
        $this->setRepresent(self::zoneConnections, BrowserTimeZoneSessionTestConnections::class,
            BrowserTimeZoneSessionTestConnectionsActions::class, BrowserTimeZoneSessionTestConnectionActions::class);
    }
}

/** Session-stage rows, with no extra fields. */
final class BrowserTimeZoneSessionTestConnectionStates extends StateSessionConnections
{
    public const string STATE_CLASS = BrowserTimeZoneSessionTestConnectionState::class;
}

/** Session-stage row with an empty project half. */
final class BrowserTimeZoneSessionTestConnectionState extends StateSessionConnection
{
    /** The fixture adds no project defaults. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Incoming row, with no project fields */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> Empty project field set */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming diff, with no project fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * Fully represented fixture sockets, inheriting the production session lookups.
 *
 * @extends HilosSessionConnections<BrowserTimeZoneSessionTestConnection, BrowserTimeZoneSessionTestConnectionsActions>
 */
final class BrowserTimeZoneSessionTestConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Session-stage state row
     * @return BrowserTimeZoneSessionTestConnection Read-only fixture connection
     */
    protected function createRtItem(RtState $state): BrowserTimeZoneSessionTestConnection
    {
        return new BrowserTimeZoneSessionTestConnection($state);
    }
}

/** @extends HilosSessionConnection<BrowserTimeZoneSessionTestConnectionState> */
final class BrowserTimeZoneSessionTestConnection extends HilosSessionConnection
{
}

/**
 * Session registration uses the inherited guarded collection actions.
 *
 * @property-read BrowserTimeZoneSessionTestConnectionStates $stateCollection Backing fixture sockets
 */
final class BrowserTimeZoneSessionTestConnectionsActions extends HilosSessionConnectionsActions
{
}

/** Connection changes and removal use the inherited guarded item actions. */
final class BrowserTimeZoneSessionTestConnectionActions extends HilosConnectionActions
{
}
