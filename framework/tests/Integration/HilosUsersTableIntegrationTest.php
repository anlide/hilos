<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The framework users table over the framework's own people (HIL-1201).
 *
 * The table reads the people of `hilos_user` itself, so the cases seed them past every action -
 * they are the ground the rows stand on - and read the rows back through the table. Presence
 * comes from a connections collection the fixture project names by key, the one thing a project
 * says to the table; the defaults that read presence and find a connection's person are the
 * framework's, and so is the refusal when the key names a collection that reports no presence.
 */
final class HilosUsersTableIntegrationTest extends HilosSessionIntegrationTestCase
{
    /** Agent that holds the fixture connections, the way a project's connection holder does. */
    private const string CONNECTIONS_OWNER_AGENT_ID = 'test-agent:connections-owner';

    private const string LAST_ACTIVITY = '2026-09-30 10:00:00';

    private const string FIRST_TAB = 'accept-1';

    private const string SECOND_TAB = 'accept-2';

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /**
     * @throws HilosException When the schema reset or the runtime context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $rt = new UsersTableTestRtContext();
        $rt->mountFeatureRuntime([]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        Hilos::$rt = $rt;
        RtTruthSourceRegistry::register(
            UsersTableTestRtContext::connections,
            TruthSourceKeys::all(),
            self::CONNECTIONS_OWNER_AGENT_ID,
        );
        ExecutionContext::setCurrentAgentId(self::CONNECTIONS_OWNER_AGENT_ID);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;

        parent::tearDown();
    }

    /**
     * A person created or changed becomes a row carrying the name and the last activity.
     *
     * @throws HilosException On database or runtime error
     */
    public function testACreatedOrChangedPersonBecomesARowWithTheNameAndTheLastActivity(): void
    {
        $ann = self::seedPerson('Ann', self::LAST_ACTIVITY);

        foreach ([
            SourceChange::dbCreated(HilosDbContext::users, (string) $ann, []),
            SourceChange::dbUpdated(HilosDbContext::users, (string) $ann, []),
        ] as $change) {
            $row = self::userRow($this->table()->buildMutationForSourceEvent($change), $change->mutationType, $ann);

            $this->assertSame('Ann', $row->name);
            $this->assertSame(self::LAST_ACTIVITY, $row->lastActivity);
            $this->assertFalse($row->admin);
            $this->assertFalse($row->block);
        }
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testARemovedPersonIsARowDeletion(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(SourceChange::dbDeleted(HilosDbContext::users, '5'));

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame(5, $mutation->rowKey);
        $this->assertNull($mutation->row);
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testAChangeOfAPersonWhoIsNoLongerThereIsIgnored(): void
    {
        $this->assertNull(
            $this->table()->buildMutationForSourceEvent(SourceChange::dbUpdated(HilosDbContext::users, '999', [])),
        );
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testAnIdBelowOneIsNotAPerson(): void
    {
        $this->assertNull(
            $this->table()->buildMutationForSourceEvent(SourceChange::dbUpdated(HilosDbContext::users, '0', [])),
        );
    }

    /**
     * The window carries every person, is searched by the name, and is sorted by it.
     *
     * @throws HilosException On database or runtime error
     */
    public function testTheWindowCarriesEveryPersonSearchedAndSortedByTheName(): void
    {
        $carol = self::seedPerson('Carol');
        $ann = self::seedPerson('Ann');
        $bob = self::seedPerson('Bob');
        $table = $this->table();

        $this->assertSame([$carol, $ann, $bob], self::rowKeys($table->getPage(new TableQueryDTO())->rows));
        $this->assertSame([$bob], self::rowKeys($table->getPage(new TableQueryDTO(search: 'bo'))->rows));
        $this->assertSame(
            [$ann, $bob, $carol],
            self::rowKeys($table->getPage(new TableQueryDTO(
                sort: TableSortOrderDTO::of(new TableSortDTO(HilosUserTableRow::name)),
            ))->rows),
        );
    }

    /**
     * The presence of a row is read out of the collection the project named by its key.
     *
     * @throws HilosException On database or runtime error
     */
    public function testPresenceIsReadFromTheCollectionTheProjectNamed(): void
    {
        $ann = self::seedPerson('Ann');
        $this->connections()->actions->register(self::FIRST_TAB, $ann);
        $this->connections()->actions->register(self::SECOND_TAB, $ann);

        $row = self::userRow(
            $this->table()->buildMutationForSourceEvent(SourceChange::dbUpdated(HilosDbContext::users, (string) $ann, [])),
            TableMutationType::Update,
            $ann,
        );

        $this->assertSame(2, $row->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $row->presence);
    }

    /**
     * A connection change that carries its person names the row to refresh.
     *
     * @throws HilosException On database or runtime error
     */
    public function testAConnectionChangeCarryingItsPersonRefreshesThatPersonsRow(): void
    {
        $ann = self::seedPerson('Ann');

        $mutation = $this->table()->buildMutationForSourceEvent(SourceChange::rtUpdated(
            UsersTableTestRtContext::connections,
            self::FIRST_TAB,
            [StateHilosConnection::userId => $ann],
        ));

        self::userRow($mutation, TableMutationType::Update, $ann);
    }

    /**
     * A narrow diff carries no person, and the live connection row answers instead.
     *
     * @throws HilosException On database or runtime error
     */
    public function testAConnectionChangeWithoutItsPersonIsAnsweredByTheLiveRow(): void
    {
        $ann = self::seedPerson('Ann');
        $this->connections()->actions->register(self::FIRST_TAB, $ann);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(UsersTableTestRtContext::connections, self::FIRST_TAB, []),
        );

        self::userRow($mutation, TableMutationType::Update, $ann);
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testAConnectionChangeNoPersonCanBeFoundForIsIgnored(): void
    {
        $this->assertNull($this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(UsersTableTestRtContext::connections, self::FIRST_TAB, []),
        ));
    }

    /**
     * A key naming a collection that reports no presence is refused loudly, not read as nobody online.
     *
     * @throws HilosException On database or runtime error
     */
    public function testAKeyNamingACollectionThatReportsNoPresenceIsRefusedWithTheKey(): void
    {
        $ann = self::seedPerson('Ann');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(StateHilosClusterNode::RT_COLLECTION);

        $this->table(StateHilosClusterNode::RT_COLLECTION)->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::users, (string) $ann, []),
        );
    }

    /**
     * @param string $presenceKey Runtime key the fixture project names as its connections
     * @return UsersTableTestTable Users table of the fixture project
     */
    private function table(string $presenceKey = UsersTableTestRtContext::connections): UsersTableTestTable
    {
        return new UsersTableTestTable($presenceKey);
    }

    /**
     * @return UsersTableTestConnections Live connections of the mounted context
     */
    private function connections(): UsersTableTestConnections
    {
        $connections = Hilos::$rt->connectionsRegistry();
        $this->assertInstanceOf(UsersTableTestConnections::class, $connections);

        return $connections;
    }

    /**
     * @param ?TableRowMutationDTO $mutation Mutation the table built
     * @param TableMutationType $type Mutation type expected
     * @param int $userId Person the row is expected for
     * @return HilosUserTableRow The row the mutation carries
     */
    private static function userRow(?TableRowMutationDTO $mutation, TableMutationType $type, int $userId): HilosUserTableRow
    {
        self::assertNotNull($mutation);
        self::assertSame($type, $mutation->type);
        self::assertSame($userId, $mutation->rowKey);
        self::assertInstanceOf(HilosUserTableRow::class, $mutation->row);

        return $mutation->row;
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @return list<int|string> Their keys, in the window's order
     */
    private static function rowKeys(array $rows): array
    {
        return array_map(static fn(AbstractTableRow $row): int|string => $row->getRowKey(), $rows);
    }

    /**
     * @param string $name Name the row carries
     * @param ?string $lastActivity Last activity as an SQL datetime, or null when the person never acted
     * @return int Id of the inserted person
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(string $name, ?string $lastActivity = null): int
    {
        Database::sql('INSERT INTO `hilos_user` (`name`, `last_activity`) VALUES (?, ?)', [$name, $lastActivity]);

        return Database::lastInsertId();
    }
}

/**
 * A project's users table: it names its connections and leaves the rest to the framework.
 */
final class UsersTableTestTable extends AbstractHilosUsersTable
{
    public function __construct(private readonly string $presenceKey)
    {
        parent::__construct();
    }

    /**
     * @return string Runtime key of the fixture project's connections
     */
    protected function presenceSourceKey(): string
    {
        return $this->presenceKey;
    }
}

/**
 * Presence-stage row with nothing of its own, as the two simple demos have.
 */
final class UsersTableTestConnection extends StateHilosConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<UsersTableTestConnection>
 */
final class UsersTableTestStates extends StateHilosConnections
{
    public const string STATE_CLASS = UsersTableTestConnection::class;
}

/**
 * @extends HilosConnection<UsersTableTestConnection>
 */
final class UsersTableTestItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<UsersTableTestItem>
 */
final class UsersTableTestItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<UsersTableTestItem, UsersTableTestConnections>
 */
final class UsersTableTestCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<UsersTableTestItem, UsersTableTestCollectionActions>
 */
final class UsersTableTestConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return UsersTableTestItem View item over the row
     */
    protected function createRtItem(RtState $state): UsersTableTestItem
    {
        /** @var UsersTableTestConnection $state */
        return new UsersTableTestItem($state);
    }
}

/**
 * Runtime context of a project that mounts its connections under a key of its own.
 */
final class UsersTableTestRtContext extends RtContext
{
    public const string connections = 'usersTableTestConnections';

    /**
     * Mounts the connections collection and gives it the write API the cases seed it through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = UsersTableTestStates::init();
        $this->setRepresent(
            self::connections,
            UsersTableTestConnections::class,
            UsersTableTestCollectionActions::class,
            UsersTableTestItemActions::class,
        );
    }
}
