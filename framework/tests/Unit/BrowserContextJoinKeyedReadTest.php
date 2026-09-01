<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * A browser list joins its rows in by asking the table, not by walking memory (HIL-781).
 *
 * The list that found this out is the profile's identity list: six rows in the table, nothing on
 * the screen, nothing in the log. Its join reads a collection keyed by its own primary key, and
 * such a collection loads by key and only by key - so walking it answers with the rows this
 * worker happened to have fetched already, which on a fresh worker is none of them.
 *
 * Every case here therefore starts from a worker holding nothing, and asserts what the subscriber
 * is sent rather than what some collection ends up holding.
 */
final class BrowserContextJoinKeyedReadTest extends TestCase
{
    /** @var string Consumer standing in for the subscribing connection */
    private const string ACCEPT_KEY = 'ak-join-keyed';

    protected function setUp(): void
    {
        JoinReadOwnerEntity::reset([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ]);
        JoinReadNoteEntity::reset([
            ['id' => 10, 'owner_id' => 1, 'body' => 'first'],
            ['id' => 11, 'owner_id' => 1, 'body' => 'second'],
            ['id' => 12, 'owner_id' => 2, 'body' => 'third'],
        ]);
        JoinReadProfileEntity::reset([
            ['owner_id' => 1, 'bio' => 'counts'],
            ['owner_id' => 2, 'bio' => 'compiles'],
        ]);

        Hilos::$sr = new SignalRouter();
        Hilos::$db = new JoinReadDbContext();
        Hilos::$db->configure();
        foreach ([JoinReadDbContext::owners, JoinReadDbContext::notes, JoinReadDbContext::profiles] as $collectionKey) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $collectionKey, SourceConsumer::feature($collectionKey));
        }
    }

    protected function tearDown(): void
    {
        foreach ([JoinReadDbContext::owners, JoinReadDbContext::notes, JoinReadDbContext::profiles] as $collectionKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($collectionKey));
        }
        Hilos::$sr = null;
        Hilos::$db = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    /**
     * The defect itself: nothing of the joined collection is in memory, and the rows still arrive.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testAJoinByAForeignKeyArrivesWhenTheWorkerHoldsNoneOfThoseRows(): void
    {
        $rows = $this->snapshotRows(new JoinReadBrowserContext());

        $this->assertSame(
            [['id' => 10, 'body' => 'first'], ['id' => 11, 'body' => 'second']],
            $rows[0][PagePayload::slots][JoinReadDbContext::notes],
        );
        $this->assertSame(
            [['id' => 12, 'body' => 'third']],
            $rows[1][PagePayload::slots][JoinReadDbContext::notes],
        );
    }

    /**
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testTheWholeSnapshotAsksTheTableOnceForThatJoin(): void
    {
        $this->snapshotRows(new JoinReadBrowserContext());

        $this->assertSame(1, JoinReadNoteEntity::queryCount());
    }

    /**
     * A join whose column IS the child's own key needs no query by column: reading by key is
     * what a key-lazy collection does well, and it is the reading three of the chat list's own
     * joins take.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testAJoinByTheChildsOwnKeyIsReadByKey(): void
    {
        $rows = $this->snapshotRows(new JoinReadBrowserContext());

        $this->assertSame(['bio' => 'counts'], $rows[0][PagePayload::slots][JoinReadDbContext::profiles]);
        $this->assertSame(['bio' => 'compiles'], $rows[1][PagePayload::slots][JoinReadDbContext::profiles]);
        $this->assertSame(0, JoinReadProfileEntity::queryCount());
        $this->assertSame(2, JoinReadProfileEntity::byIdCount());
    }

    /**
     * A declaration naming nothing to join by used to answer with the whole collection filtered
     * in memory, which is the silent emptiness one declaration away. It is refused instead.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testADeclarationNamingNoJoinColumnIsRefusedOutLoud(): void
    {
        $this->expectException(PageInternalErrorException::class);
        $this->expectExceptionMessage(
            'Browser join cannot be read by key: table=joinReadList, source=joinReadNotes names no join column'
        );

        $this->snapshotRows(new JoinReadBrowserContext(joinColumnDeclared: false));
    }

    /**
     * The reactive path has no snapshot to batch with, so it is the road that has to hold on its
     * own: one row changed, one join read for it, out of the table rather than out of memory.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testAChangedRowReadsItsJoinOutOfTheTableToo(): void
    {
        Hilos::$sr?->subscribeToPage(
            JoinReadBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, JoinReadBrowserContext::PAGE),
        );

        $context = new JoinReadBrowserContext();
        $context->record(SourceChange::dbUpdated(JoinReadDbContext::owners, '2', ['name' => 'Grace']));
        $context->flushToSignalRouter();

        $rows = $this->rowsOfNextPageResponse();

        $this->assertSame(
            [['id' => 12, 'body' => 'third']],
            $rows[0][PagePayload::slots][JoinReadDbContext::notes],
        );
    }

    /**
     * Subscribes the test page and returns the browser rows the subscriber was sent.
     *
     * @param JoinReadBrowserContext $context Context under test
     * @return list<array<string, mixed>> Browser rows of the one bound list
     * @throws HilosException When the snapshot refuses the subscription
     */
    private function snapshotRows(JoinReadBrowserContext $context): array
    {
        $context->subscribeSnapshot(JoinReadBrowserContext::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));

        return $this->rowsOfNextPageResponse();
    }

    /**
     * Reads the browser rows out of the page response the subscriber was just sent.
     *
     * @return list<array<string, mixed>> Browser rows of the one bound list
     */
    private function rowsOfNextPageResponse(): array
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        $payload = $signal->data->data->toArray()[PageResponseSignalData::payload];

        return $payload[PagePayload::tables][JoinReadBrowserContext::BROWSER_KEY][PagePayload::rows];
    }
}

/**
 * Browser context declaring one list: an anchor, a join by a foreign key, and a join by the
 * child's own key.
 */
final class JoinReadBrowserContext extends BrowserContext
{
    public const string PAGE = 'join_read_page';
    public const string SIGNAL = 'join_read_signal';
    public const string BROWSER_KEY = 'joinReadList';

    /**
     * @param bool $joinColumnDeclared Whether the foreign-key join names the column it joins by
     */
    public function __construct(private readonly bool $joinColumnDeclared = true)
    {
        parent::__construct();
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guard-less page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings The single binding, or none for any other page
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([self::BROWSER_KEY => []]);
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig The declared list, or null when unknown
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::BROWSER_KEY) {
            return null;
        }

        $notes = [
            BrowserFieldKey::SOURCE => $this->source(JoinReadDbContext::notes),
            BrowserListFieldKey::MANY => true,
            BrowserListFieldKey::FIELDS => [JoinReadNoteObject::id, JoinReadNoteObject::body],
        ];
        if ($this->joinColumnDeclared) {
            $notes[BrowserListFieldKey::ITEM_KEY] = JoinReadNoteObject::ownerId;
        }

        $items = [
            [
                BrowserFieldKey::SOURCE => $this->source(JoinReadDbContext::owners),
                BrowserListFieldKey::ITEM_KEY => JoinReadOwnerObject::id,
                BrowserListFieldKey::FIELDS => [JoinReadOwnerObject::name],
            ],
            $notes,
            [
                BrowserFieldKey::SOURCE => $this->source(JoinReadDbContext::profiles),
                BrowserListFieldKey::ITEM_KEY => JoinReadProfileObject::ownerId,
                BrowserListFieldKey::FIELDS => [JoinReadProfileObject::bio],
            ],
        ];
        return BrowserSourceConfig::fromArray([BrowserListConfigKey::ITEMS => $items]);
    }

    /**
     * @param string $collectionKey Database collection the row draws from
     * @return array<string, string> One source declaration
     */
    private function source(string $collectionKey): array
    {
        return [
            BrowserSourceKey::TYPE => BrowserSourceType::DB,
            BrowserSourceKey::KEY => $collectionKey,
        ];
    }
}

/**
 * Database context mounting the three fixture collections, all key-lazy - the strategy the
 * identities collection is declared with, and the one the defect needs.
 */
final class JoinReadDbContext extends DbContext
{
    public const string owners = 'joinReadOwners';
    public const string notes = 'joinReadNotes';
    public const string profiles = 'joinReadProfiles';

    public function configure(): void
    {
        $this->_objectCollections[self::owners] = JoinReadOwnerObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::notes] = JoinReadNoteObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::profiles] = JoinReadProfileObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::owners, JoinReadOwnerCollection::class);
        $this->setRepresent(self::notes, JoinReadNoteCollection::class);
        $this->setRepresent(self::profiles, JoinReadProfileCollection::class);
    }
}

/**
 * Entity fixture answering out of an in-test table, so a join read is observable without a
 * database, and counting how it was asked so a case can tell a query by column from a read by key.
 *
 * The three filter forms it understands are the three the code under test builds: none at all
 * (the anchor's page query), one column equals one value, and a column in a list of values.
 */
abstract class JoinReadEntity extends Entity
{
    /** @var array<string, list<array<string, mixed>>> Rows the fake tables hold, by entity class */
    private static array $tableRows = [];

    /** @var array<string, int> How many times each fake table was queried by column */
    private static array $queryCounts = [];

    /** @var array<string, int> How many times each fake table was read by key */
    private static array $byIdCounts = [];

    /**
     * @param list<array<string, mixed>> $rows Rows the fake table holds from now on
     */
    public static function reset(array $rows): void
    {
        self::$tableRows[static::class] = $rows;
        self::$queryCounts[static::class] = 0;
        self::$byIdCounts[static::class] = 0;
    }

    /**
     * @return int How many times the fake table was queried by column
     */
    public static function queryCount(): int
    {
        return self::$queryCounts[static::class] ?? 0;
    }

    /**
     * @return int How many times the fake table was read by key
     */
    public static function byIdCount(): int
    {
        return self::$byIdCounts[static::class] ?? 0;
    }

    /**
     * @param array<string, mixed>|string $filters Column => value pairs, or a column IN clause
     * @param array<int, mixed>|string $filtersParam Values bound to the IN clause
     * @param array<string, string>|string $orderBy Unused; the fake table has one order
     * @param int $limit Unused; the fake table answers whole
     * @param int $offset Unused; the fake table answers whole
     * @return EntityCollection Matching entities keyed by primary key
     */
    public static function get(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = TableConstants::NO_LIMIT,
        int $offset = 0,
    ): EntityCollection {
        self::$queryCounts[static::class] = (self::$queryCounts[static::class] ?? 0) + 1;

        return static::matching($filters, $filtersParam);
    }

    /**
     * @param mixed $id Primary key value
     * @return ?static Entity carrying that key, or null when the fake table has no such row
     */
    public static function getById(mixed $id): ?static
    {
        self::$byIdCounts[static::class] = (self::$byIdCounts[static::class] ?? 0) + 1;

        return static::matching([static::_primary => $id], [])->first();
    }

    /**
     * @param array<string, mixed>|string $filters Column => value pairs, or a column IN clause
     * @param array<int, mixed>|string $filtersParam Values bound to the IN clause
     * @return int How many rows match
     */
    public static function count(array|string $filters = [], array|string $filtersParam = []): int
    {
        return static::matching($filters, $filtersParam)->count();
    }

    /**
     * Applies a filter to the fake table.
     *
     * @param array<string, mixed>|string $filters Column => value pairs, or a column IN clause
     * @param array<int, mixed>|string $filtersParam Values bound to the IN clause
     * @return EntityCollection Matching entities keyed by primary key
     */
    private static function matching(array|string $filters, array|string $filtersParam): EntityCollection
    {
        $column = null;
        $wanted = [];
        if (is_array($filters) && $filters !== []) {
            $column = (string) array_key_first($filters);
            $wanted = array_values($filters);
        } elseif (is_string($filters) && $filters !== '') {
            $column = trim(substr($filters, 0, (int) strpos($filters, ' ')), '`');
            $wanted = is_array($filtersParam) ? array_values($filtersParam) : [];
        }

        $collection = EntityCollection::empty();
        $primary = is_array(static::_primary) ? static::_primary[0] : static::_primary;
        foreach (self::$tableRows[static::class] ?? [] as $row) {
            if ($column !== null && !in_array($row[$column], $wanted, true)) {
                continue;
            }
            $entity = new static();
            foreach (static::_columns as $columnName) {
                $entity->{$columnName} = $row[$columnName];
            }
            $entity->flushRelated();
            $collection->add($entity, (string) $row[$primary]);
        }

        return $collection;
    }
}

/**
 * Anchor entity fixture.
 */
final class JoinReadOwnerEntity extends JoinReadEntity
{
    public const string _table = 'join_read_owner';
    public const string _primary = 'id';
    public const array _columns = ['id', 'name'];
    public const array _types = ['id' => 'integer', 'name' => 'string'];

    public ?int $id = null;
    public ?string $name = null;
}

/**
 * Joined entity fixture whose join column is somebody else's key.
 */
final class JoinReadNoteEntity extends JoinReadEntity
{
    public const string _table = 'join_read_note';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id', 'body'];
    public const array _types = ['id' => 'integer', 'owner_id' => 'integer', 'body' => 'string'];

    public ?int $id = null;
    public ?int $owner_id = null;
    public ?string $body = null;
}

/**
 * Joined entity fixture whose join column is its own key.
 */
final class JoinReadProfileEntity extends JoinReadEntity
{
    public const string _table = 'join_read_profile';
    public const string _primary = 'owner_id';
    public const array _columns = ['owner_id', 'bio'];
    public const array _types = ['owner_id' => 'integer', 'bio' => 'string'];

    public ?int $owner_id = null;
    public ?string $bio = null;
}

/**
 * Anchor object fixture.
 *
 * @property-read ?int $id
 * @property-read ?string $name
 */
final class JoinReadOwnerObject extends Object_
{
    public const string ENTITY_CLASS = JoinReadOwnerEntity::class;
    public const string id = 'id';
    public const string name = 'name';

    /**
     * @param string $property Property name (id, name)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            default => parent::__get($property),
        };
    }
}

/**
 * Joined object fixture, camelCase over the table's snake_case column.
 *
 * @property-read ?int $id
 * @property-read ?int $ownerId
 * @property-read ?string $body
 */
final class JoinReadNoteObject extends Object_
{
    public const string ENTITY_CLASS = JoinReadNoteEntity::class;
    public const string id = 'id';
    public const string ownerId = 'ownerId';
    public const string body = 'body';

    /**
     * @param string $property Property name (id, ownerId, body)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::ownerId => $this->entity->owner_id,
            self::body => $this->entity->body,
            default => parent::__get($property),
        };
    }
}

/**
 * Joined object fixture keyed by the join column itself.
 *
 * @property-read ?int $ownerId
 * @property-read ?string $bio
 */
final class JoinReadProfileObject extends Object_
{
    public const string ENTITY_CLASS = JoinReadProfileEntity::class;
    public const string ownerId = 'ownerId';
    public const string bio = 'bio';

    /**
     * @param string $property Property name (ownerId, bio)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::ownerId => $this->entity->owner_id,
            self::bio => $this->entity->bio,
            default => parent::__get($property),
        };
    }
}

/**
 * Anchor object collection fixture.
 */
final class JoinReadOwnerObjects extends Objects
{
    public const string OBJECT_CLASS = JoinReadOwnerObject::class;
    public const string COLLECTION_KEY = JoinReadDbContext::owners;
}

/**
 * Foreign-key joined object collection fixture.
 */
final class JoinReadNoteObjects extends Objects
{
    public const string OBJECT_CLASS = JoinReadNoteObject::class;
    public const string COLLECTION_KEY = JoinReadDbContext::notes;
}

/**
 * Own-key joined object collection fixture.
 */
final class JoinReadProfileObjects extends Objects
{
    public const string OBJECT_CLASS = JoinReadProfileObject::class;
    public const string COLLECTION_KEY = JoinReadDbContext::profiles;
}

/**
 * Anchor view collection fixture.
 */
final class JoinReadOwnerCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = JoinReadOwnerItem::class;
    public const string OBJECT_COLLECTION_CLASS = JoinReadOwnerObjects::class;
}

/**
 * Foreign-key joined view collection fixture.
 */
final class JoinReadNoteCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = JoinReadNoteItem::class;
    public const string OBJECT_COLLECTION_CLASS = JoinReadNoteObjects::class;
}

/**
 * Own-key joined view collection fixture.
 */
final class JoinReadProfileCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = JoinReadProfileItem::class;
    public const string OBJECT_COLLECTION_CLASS = JoinReadProfileObjects::class;
}

/**
 * Anchor view item fixture.
 *
 * @extends DbItem<JoinReadOwnerObject>
 */
final class JoinReadOwnerItem extends DbItem
{
    /**
     * @param string $name Property name (id, name)
     * @return mixed Property value
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            JoinReadOwnerObject::id => $this->_object->id,
            JoinReadOwnerObject::name => $this->_object->name,
            default => parent::__get($name),
        };
    }
}

/**
 * Foreign-key joined view item fixture.
 *
 * @extends DbItem<JoinReadNoteObject>
 */
final class JoinReadNoteItem extends DbItem
{
    /**
     * @param string $name Property name (id, ownerId, body)
     * @return mixed Property value
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            JoinReadNoteObject::id => $this->_object->id,
            JoinReadNoteObject::ownerId => $this->_object->ownerId,
            JoinReadNoteObject::body => $this->_object->body,
            default => parent::__get($name),
        };
    }
}

/**
 * Own-key joined view item fixture.
 *
 * @extends DbItem<JoinReadProfileObject>
 */
final class JoinReadProfileItem extends DbItem
{
    /**
     * @param string $name Property name (ownerId, bio)
     * @return mixed Property value
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            JoinReadProfileObject::ownerId => $this->_object->ownerId,
            JoinReadProfileObject::bio => $this->_object->bio,
            default => parent::__get($name),
        };
    }
}
