<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * A list item joined by a field of its anchor, and the way its source's change travels back (HIL-1662).
 *
 * The shape is the person card's sessions list: each session item carries the administrator acting in
 * it, read out of the people table by the session's own impersonator field, and that row declares no
 * key of its own. Renaming the administrator has to reach exactly the sessions pointing at them, on the
 * subscriber whose person they belong to - and never a session whose own number merely equals the
 * administrator's id, which is what taking the changed row's id for an item key used to do.
 *
 * The fixture: Ada (1) has sessions 101 and 103 taken over by Root (7), 102 nobody acts in, and session 7
 * - the number of Root - taken over by Ops (8); Grace (2) has session 201 taken over by Root. A viewer of
 * the admin view mode looks at Ada's sessions only where a case subscribes them; the verdicts they are
 * judged by are the framework's own for both tables: every session column personal, a person's id not.
 */
final class BrowserContextAnchorFieldJoinTest extends TestCase
{
    /** @var string Subscriber looking at Ada's sessions */
    private const string ADA_VIEWER = 'ak-ada';

    /** @var string Subscriber looking at Grace's sessions */
    private const string GRACE_VIEWER = 'ak-grace';

    /** @var string Viewer of the admin view mode looking at Ada's sessions */
    private const string ADA_VIEW_MODE = 'ak-ada-view-mode';

    /** @var array<string, array<string, mixed>> The one shape every session item takes for the viewer */
    private const array HIDDEN_SESSION = [
        AnchorFieldDbContext::sessions => [
            'id' => [HiddenValue::KEY => true],
            'userId' => [HiddenValue::KEY => true],
            'impersonatorUserId' => [HiddenValue::KEY => true],
        ],
    ];

    private AnchorFieldBrowserContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        AnchorFieldUserEntity::reset([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
            ['id' => 7, 'name' => 'Root'],
            ['id' => 8, 'name' => 'Ops'],
        ]);
        AnchorFieldSessionEntity::reset([
            ['id' => 7, 'user_id' => 1, 'impersonator_user_id' => 8],
            ['id' => 101, 'user_id' => 1, 'impersonator_user_id' => 7],
            ['id' => 102, 'user_id' => 1, 'impersonator_user_id' => null],
            ['id' => 103, 'user_id' => 1, 'impersonator_user_id' => 7],
            ['id' => 201, 'user_id' => 2, 'impersonator_user_id' => 7],
        ]);

        Hilos::$sr = new SignalRouter();
        Hilos::$db = new AnchorFieldDbContext();
        Hilos::$db->configure();
        foreach ([AnchorFieldDbContext::sessions, AnchorFieldDbContext::users] as $collectionKey) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $collectionKey, SourceConsumer::feature($collectionKey));
        }
        $this->context = new AnchorFieldBrowserContext([self::ADA_VIEW_MODE], self::verdicts(impersonatorShown: false));
        $this->context->bindHilosFacade(AnchorFieldHilos::class);
        foreach ([self::ADA_VIEWER => '1', self::GRACE_VIEWER => '2'] as $acceptKey => $userId) {
            Hilos::$sr->subscribeToPage(
                AnchorFieldBrowserContext::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, AnchorFieldBrowserContext::PAGE, ['userId' => $userId]),
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ([AnchorFieldDbContext::sessions, AnchorFieldDbContext::users] as $collectionKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($collectionKey));
        }
        Hilos::$sr = null;
        Hilos::$db = null;
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /**
     * The first answer reads the administrator by the key the session holds.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testTheSnapshotCarriesTheAdministratorTheSessionPointsAt(): void
    {
        $items = $this->snapshotItems(self::ADA_VIEWER, '1');

        self::assertSame([7, 101, 102, 103], array_keys($items));
        self::assertSame(['id' => 7, 'name' => 'Root'], $items[101][AnchorFieldDbContext::users]);
        self::assertSame(['id' => 7, 'name' => 'Root'], $items[103][AnchorFieldDbContext::users]);
        self::assertSame(['id' => 8, 'name' => 'Ops'], $items[7][AnchorFieldDbContext::users]);
    }

    /**
     * A session nobody acts in carries the session and nothing joined to it.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testASessionWithoutAnImpersonatorCarriesNoAdministrator(): void
    {
        $items = $this->snapshotItems(self::ADA_VIEWER, '1');

        self::assertSame(
            [AnchorFieldDbContext::sessions => ['id' => 102, 'userId' => 1, 'impersonatorUserId' => null]],
            $items[102],
        );
    }

    /**
     * The rename rebuilds the items pointing at the administrator, each on the subscriber of its own person.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testRenamingTheAdministratorRebuildsExactlyTheItemsPointingAtThem(): void
    {
        $this->renameUser(7, 'Root renamed');

        $sent = $this->drainLists();
        self::assertSame([self::ADA_VIEWER, self::GRACE_VIEWER], array_keys($sent));
        self::assertSame([101, 103], array_keys($sent[self::ADA_VIEWER][PagePayload::items]));
        self::assertSame([201], array_keys($sent[self::GRACE_VIEWER][PagePayload::items]));
        foreach ($sent as $list) {
            self::assertArrayNotHasKey(PagePayload::deleted, $list);
            foreach ($list[PagePayload::items] as $slots) {
                self::assertSame(['id' => 7, 'name' => 'Root renamed'], $slots[AnchorFieldDbContext::users]);
            }
        }
    }

    /**
     * An administrator acting only in Ada's sessions is none of Grace's subscriber's business: not a row, not a deletion.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testTheSubscriberOfAnotherPersonIsSentNeitherRowsNorDeletions(): void
    {
        $this->renameUser(8, 'Ops renamed');

        $sent = $this->drainLists();
        self::assertSame([self::ADA_VIEWER], array_keys($sent));
        self::assertSame([7], array_keys($sent[self::ADA_VIEWER][PagePayload::items]));
        self::assertSame(['id' => 8, 'name' => 'Ops renamed'], $sent[self::ADA_VIEWER][PagePayload::items][7][AnchorFieldDbContext::users]);
    }

    /**
     * Session 7 shares Root's number and points at Ops: the rename of Root leaves it alone.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testAnItemWhoseKeyEqualsTheAdministratorsIdIsNotRebuilt(): void
    {
        $this->renameUser(7, 'Root renamed');

        $ada = $this->drainLists()[self::ADA_VIEWER];
        self::assertArrayNotHasKey(7, $ada[PagePayload::items]);
        self::assertArrayNotHasKey(PagePayload::deleted, $ada);
    }

    /**
     * Another administrator taking the session over is a change of the session itself, and the item carries the new name.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testChangingTheImpersonatorChangesTheName(): void
    {
        AnchorFieldSessionEntity::updateRow(101, ['impersonator_user_id' => 8]);
        $this->context->record(SourceChange::dbUpdated(
            AnchorFieldDbContext::sessions,
            '101',
            ['impersonator_user_id' => 8],
            previous: ['impersonator_user_id' => 7],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());

        $sent = $this->drainLists();
        self::assertSame([101], array_keys($sent[self::ADA_VIEWER][PagePayload::items]));
        self::assertSame(['id' => 8, 'name' => 'Ops'], $sent[self::ADA_VIEWER][PagePayload::items][101][AnchorFieldDbContext::users]);
        self::assertArrayNotHasKey(PagePayload::items, $sent[self::GRACE_VIEWER] ?? []);
    }

    /**
     * An administrator gone from the table leaves the sessions pointing at them without the fragment, and still listed.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testADeletedAdministratorDropsTheFragment(): void
    {
        AnchorFieldUserEntity::removeRow(7);
        $this->context->record(SourceChange::dbDeleted(AnchorFieldDbContext::users, '7', ['id' => 7, 'name' => 'Root']));
        self::assertSame([], $this->context->flushToSignalRouter());

        $sent = $this->drainLists();
        self::assertSame([self::ADA_VIEWER, self::GRACE_VIEWER], array_keys($sent));
        self::assertSame([101, 103], array_keys($sent[self::ADA_VIEWER][PagePayload::items]));
        self::assertSame([201], array_keys($sent[self::GRACE_VIEWER][PagePayload::items]));
        foreach ($sent as $list) {
            self::assertArrayNotHasKey(PagePayload::deleted, $list);
            foreach ($list[PagePayload::items] as $slots) {
                self::assertSame([AnchorFieldDbContext::sessions], array_keys($slots));
            }
        }
    }

    /**
     * A viewer is sent no administrator and no sign of one: the session taken over and the one nobody acts in arrive alike.
     *
     * The people row reads its own id as not personal, but the viewer reaches it only through the session's
     * impersonator field, which is hidden; sent masked by its own columns, the row would still give that
     * field away by its id and by being there.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testAViewerIsSentNoAdministratorInTheSnapshot(): void
    {
        $items = $this->snapshotItems(self::ADA_VIEW_MODE, '1');

        self::assertSame([7, 101, 102, 103], array_keys($items));
        foreach ($items as $slots) {
            self::assertSame(self::HIDDEN_SESSION, $slots);
        }
    }

    /**
     * Renaming the administrator sends the viewer nothing: a frame for the sessions pointing at them would name those sessions.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testRenamingTheAdministratorSendsAViewerNoFrame(): void
    {
        $this->subscribeTheViewer();

        $this->renameUser(7, 'Root renamed');

        $sent = $this->drainLists();
        self::assertSame([self::ADA_VIEWER, self::GRACE_VIEWER], array_keys($sent));
        self::assertSame(['id' => 7, 'name' => 'Root renamed'], $sent[self::ADA_VIEWER][PagePayload::items][101][AnchorFieldDbContext::users]);
    }

    /**
     * A session losing its administrator and one gaining an administrator reach the viewer as the same hidden session.
     *
     * @throws HilosException When the fan-out refuses the change
     */
    public function testRebindingTheSessionsReachesAViewerWithoutTheAdministrator(): void
    {
        $this->subscribeTheViewer();
        AnchorFieldSessionEntity::updateRow(101, ['impersonator_user_id' => null]);
        AnchorFieldSessionEntity::updateRow(102, ['impersonator_user_id' => 7]);
        $this->context->record(SourceChange::dbUpdated(
            AnchorFieldDbContext::sessions,
            '101',
            ['impersonator_user_id' => null],
            previous: ['impersonator_user_id' => 7],
        ));
        $this->context->record(SourceChange::dbUpdated(
            AnchorFieldDbContext::sessions,
            '102',
            ['impersonator_user_id' => 7],
            previous: ['impersonator_user_id' => null],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());

        $sent = $this->drainLists();
        self::assertSame(['id' => 7, 'name' => 'Root'], $sent[self::ADA_VIEWER][PagePayload::items][102][AnchorFieldDbContext::users]);
        self::assertSame([101, 102], array_keys($sent[self::ADA_VIEW_MODE][PagePayload::items]));
        foreach ($sent[self::ADA_VIEW_MODE][PagePayload::items] as $slots) {
            self::assertSame(self::HIDDEN_SESSION, $slots);
        }
    }

    /**
     * An anchor field a viewer is shown leaves the joined row to its own columns, like any other row.
     *
     * @throws HilosException When the snapshot refuses the subscription
     */
    public function testAnAnchorFieldShownToAViewerLeavesTheRowToItsOwnColumns(): void
    {
        $this->context = new AnchorFieldBrowserContext([self::ADA_VIEW_MODE], self::verdicts(impersonatorShown: true));
        $this->context->bindHilosFacade(AnchorFieldHilos::class);

        $items = $this->snapshotItems(self::ADA_VIEW_MODE, '1');

        self::assertSame(['id' => 7, 'name' => HiddenValue::mark()], $items[101][AnchorFieldDbContext::users]);
        self::assertSame(7, $items[101][AnchorFieldDbContext::sessions]['impersonatorUserId']);
        self::assertSame([AnchorFieldDbContext::sessions], array_keys($items[102]));
    }

    /** Subscribes the viewer of the admin view mode to Ada's sessions. */
    private function subscribeTheViewer(): void
    {
        Hilos::$sr?->subscribeToPage(
            AnchorFieldBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ADA_VIEW_MODE, AnchorFieldBrowserContext::PAGE, ['userId' => '1']),
        );
    }

    /**
     * Renames one person in the table, then records and flushes the change the database would announce.
     *
     * @param int $userId Person renamed
     * @param string $name New name
     */
    private function renameUser(int $userId, string $name): void
    {
        AnchorFieldUserEntity::updateRow($userId, ['name' => $name]);
        $this->context->record(SourceChange::dbUpdated(AnchorFieldDbContext::users, (string) $userId, ['name' => $name]));
        self::assertSame([], $this->context->flushToSignalRouter());
    }

    /**
     * Subscribes one viewer's snapshot and returns its list items' slots by item key.
     *
     * @param string $acceptKey Subscriber
     * @param string $userId Person whose sessions the subscriber looks at
     * @return array<int|string, array<string, mixed>> Slots of each item, by item key
     * @throws HilosException When the snapshot refuses the subscription
     */
    private function snapshotItems(string $acceptKey, string $userId): array
    {
        $snapshot = $this->context->buildSubscribeSnapshot(
            AnchorFieldBrowserContext::PAGE,
            $acceptKey,
            new PageRouteParams(['userId' => $userId]),
        );

        return $this->slotsByItemKey($snapshot->toArray()[PagePayload::lists][AnchorFieldSessionsList::LIST][PagePayload::items]);
    }

    /**
     * Reads every page response queued since the flush: the list section each subscriber was sent.
     *
     * @return array<string, array<string, mixed>> List section by addressee, with items keyed by item key
     */
    private function drainLists(): array
    {
        $sent = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $list = $signal->data->data->payload->toArray()[PagePayload::lists][AnchorFieldSessionsList::LIST];
            if (isset($list[PagePayload::items])) {
                $list[PagePayload::items] = $this->slotsByItemKey($list[PagePayload::items]);
            }
            $sent[$signal->data->targetAcceptKey] = $list;
        }
        ksort($sent);

        return $sent;
    }

    /**
     * The verdicts the viewer's columns are judged by: a person's name personal, their id not, and a session's
     * columns personal throughout, or its impersonator field alone opened.
     *
     * @param bool $impersonatorShown Whether the session's impersonator field is declared not personal
     * @return PiiRegistry Verdicts of the two fixture tables
     */
    private static function verdicts(bool $impersonatorShown): PiiRegistry
    {
        $notPersonal = [AnchorFieldUserEntity::_table => ['id']];
        if ($impersonatorShown) {
            $notPersonal[AnchorFieldSessionEntity::_table] = ['impersonator_user_id'];
        }

        return new PiiRegistry(
            [0 => [
                AnchorFieldSessionEntity::_table => $impersonatorShown
                    ? ['user_id' => AnonymizationStrategy::NULLIFY]
                    : AnonymizationStrategy::PURGE,
                AnchorFieldUserEntity::_table => ['name' => AnonymizationStrategy::FAKE_NAME],
            ]],
            [0 => $notPersonal],
        );
    }

    /**
     * @param list<array<string, mixed>> $items List items as the wire carries them
     * @return array<int|string, array<string, mixed>> Slots of each item, by item key, in key order
     */
    private function slotsByItemKey(array $items): array
    {
        $slots = array_column($items, PagePayload::slots, PagePayload::itemKey);
        ksort($slots);

        return $slots;
    }
}

abstract class AnchorFieldHilos extends Hilos
{
    public const array PAGES = [AnchorFieldBrowserContext::PAGE => AbstractPage::class];
    public const array BROWSER_LISTS = [AnchorFieldSessionsList::LIST => AnchorFieldSessionsList::class];
}

/** One person's sessions, each with the administrator acting in it joined by the session's own field. */
final class AnchorFieldSessionsList
{
    public const string LIST = 'anchorFieldSessions';

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [self::SESSIONS, self::USERS],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => self::SESSIONS,
                BrowserListFieldKey::ITEM_KEY => AnchorFieldSessionObject::id,
                BrowserListFieldKey::WHERE => [
                    AnchorFieldSessionObject::userId => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => 'userId',
                    ],
                ],
                BrowserListFieldKey::FIELDS => [
                    AnchorFieldSessionObject::id,
                    AnchorFieldSessionObject::userId,
                    AnchorFieldSessionObject::impersonatorUserId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => self::USERS,
                BrowserListFieldKey::VIA => [AnchorFieldUserObject::id => AnchorFieldSessionObject::impersonatorUserId],
                BrowserListFieldKey::FIELDS => [AnchorFieldUserObject::id, AnchorFieldUserObject::name],
                BrowserListFieldKey::TRIGGERS => [AnchorFieldUserObject::name],
            ],
        ],
    ];

    private const array SESSIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => AnchorFieldDbContext::sessions,
    ];

    private const array USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => AnchorFieldDbContext::users,
    ];
}

/** The person's page, binding its route's person into the list's WHERE, with the viewers it is told of. */
final class AnchorFieldBrowserContext extends BrowserContext
{
    public const string PAGE = 'anchor_field_page';

    /**
     * @param list<string> $viewers Connections looking at the page as viewers of the admin view mode
     * @param PiiRegistry $verdicts Personal-data verdicts the viewers' columns are judged by
     */
    public function __construct(
        private readonly array $viewers,
        private readonly PiiRegistry $verdicts,
    ) {
        parent::__construct();
    }

    /**
     * @param string $pageClass Page class registered by the fixture
     * @param string $acceptKey Connection the frame goes to
     * @return bool Whether the connection is one of the fixture's viewers
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return in_array($acceptKey, $this->viewers, true);
    }

    /** @return PiiRegistry The fixture's verdicts */
    protected function viewerPiiRegistry(): PiiRegistry
    {
        return $this->verdicts;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guard-less page metadata, or null for any other page
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'anchor_field_signal']) : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings The list bound to the page's person, or none for any other page
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            AnchorFieldSessionsList::LIST => [BrowserParamKey::PARAMS => [
                'userId' => [BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM, BrowserRefKey::KEY => 'userId'],
            ]],
        ]);
    }
}

/** Database context mounting both fixture tables key-lazy, so every read below asks the table. */
final class AnchorFieldDbContext extends HilosDbContext
{
    public const string sessions = 'anchorFieldSessions';
    public const string users = 'anchorFieldUsers';

    public function configure(): void
    {
        $this->_objectCollections[self::sessions] = AnchorFieldSessionObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::users] = AnchorFieldUserObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::sessions, AnchorFieldSessionCollection::class);
        $this->setRepresent(self::users, AnchorFieldUserCollection::class);
    }
}

/**
 * Entity fixture answering out of an in-test table, so the reads under test need no database.
 *
 * The filter forms it understands are the ones the code under test builds: none (the snapshot's page
 * query), one column equal to one value, and a column in a list of values. Values compare as text, as
 * SQL compares a key carried as a string with an integer column.
 */
abstract class AnchorFieldEntity extends Entity
{
    /** @var array<string, array<int, array<string, mixed>>> Rows the fake tables hold, by entity class and key */
    private static array $tableRows = [];

    /**
     * @param list<array<string, mixed>> $rows Rows the fake table holds from now on
     */
    public static function reset(array $rows): void
    {
        self::$tableRows[static::class] = array_column($rows, null, static::_primary);
    }

    /**
     * @param int $id Key of the row to change
     * @param array<string, mixed> $values Columns to overwrite
     */
    public static function updateRow(int $id, array $values): void
    {
        self::$tableRows[static::class][$id] = array_replace(self::$tableRows[static::class][$id], $values);
    }

    /** @param int $id Key of the row to remove */
    public static function removeRow(int $id): void
    {
        unset(self::$tableRows[static::class][$id]);
    }

    /**
     * @param array<string, mixed>|string $filters Column => value pairs, or a column IN clause
     * @param array<int, mixed>|string $filtersParam Values bound to the IN clause
     * @param array<string, string>|string $orderBy Unused; the fake table answers in key order
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
        return static::matching($filters, $filtersParam);
    }

    /**
     * @param mixed $id Primary key value
     * @return ?static Entity carrying that key, or null when the fake table has no such row
     */
    public static function getById(mixed $id): ?static
    {
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
        $wanted = array_map(static fn(mixed $value): string => (string) $value, $wanted);

        $collection = EntityCollection::empty();
        $rows = self::$tableRows[static::class] ?? [];
        ksort($rows);
        foreach ($rows as $key => $row) {
            if ($column !== null && ($row[$column] === null || !in_array((string) $row[$column], $wanted, true))) {
                continue;
            }
            $entity = new static();
            foreach (static::_columns as $columnName) {
                $entity->{$columnName} = $row[$columnName];
            }
            $entity->flushRelated();
            $collection->add($entity, (string) $key);
        }

        return $collection;
    }
}

/** The session table: a person, and the administrator acting as them, if any. */
final class AnchorFieldSessionEntity extends AnchorFieldEntity
{
    public const string _table = 'anchor_field_session';
    public const string _primary = 'id';
    public const array _columns = ['id', 'user_id', 'impersonator_user_id'];
    public const array _types = ['id' => 'integer', 'user_id' => 'integer', 'impersonator_user_id' => 'integer'];
    public const array _indexes = ['idx_anchor_field_session_impersonator' => [Entity::INDEX_COLUMNS => ['impersonator_user_id']]];

    public ?int $id = null;
    public ?int $user_id = null;
    public ?int $impersonator_user_id = null;
}

/** The people table. */
final class AnchorFieldUserEntity extends AnchorFieldEntity
{
    public const string _table = 'anchor_field_user';
    public const string _primary = 'id';
    public const array _columns = ['id', 'name'];
    public const array _types = ['id' => 'integer', 'name' => 'string'];

    public ?int $id = null;
    public ?string $name = null;
}

/**
 * Session object fixture, camelCase over the table's snake_case columns.
 *
 * @property-read ?int $id
 * @property-read ?int $userId
 * @property-read ?int $impersonatorUserId
 */
final class AnchorFieldSessionObject extends Object_
{
    public const string ENTITY_CLASS = AnchorFieldSessionEntity::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string impersonatorUserId = 'impersonatorUserId';

    /**
     * @param string $property Property name (id, userId, impersonatorUserId)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::impersonatorUserId => $this->entity->impersonator_user_id,
            default => parent::__get($property),
        };
    }
}

/**
 * Person object fixture.
 *
 * @property-read ?int $id
 * @property-read ?string $name
 */
final class AnchorFieldUserObject extends Object_
{
    public const string ENTITY_CLASS = AnchorFieldUserEntity::class;
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

final class AnchorFieldSessionObjects extends Objects
{
    public const string OBJECT_CLASS = AnchorFieldSessionObject::class;
    public const string COLLECTION_KEY = AnchorFieldDbContext::sessions;
}

final class AnchorFieldUserObjects extends Objects
{
    public const string OBJECT_CLASS = AnchorFieldUserObject::class;
    public const string COLLECTION_KEY = AnchorFieldDbContext::users;
}

final class AnchorFieldSessionCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = AnchorFieldSessionItem::class;
    public const string OBJECT_COLLECTION_CLASS = AnchorFieldSessionObjects::class;
}

final class AnchorFieldUserCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = AnchorFieldUserItem::class;
    public const string OBJECT_COLLECTION_CLASS = AnchorFieldUserObjects::class;
}

/** @extends DbItem<AnchorFieldSessionObject> */
final class AnchorFieldSessionItem extends DbItem
{
    /**
     * @param string $name Property name (id, userId, impersonatorUserId)
     * @return mixed Property value
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            AnchorFieldSessionObject::id => $this->_object->id,
            AnchorFieldSessionObject::userId => $this->_object->userId,
            AnchorFieldSessionObject::impersonatorUserId => $this->_object->impersonatorUserId,
            default => parent::__get($name),
        };
    }
}

/** @extends DbItem<AnchorFieldUserObject> */
final class AnchorFieldUserItem extends DbItem
{
    /**
     * @param string $name Property name (id, name)
     * @return mixed Property value
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            AnchorFieldUserObject::id => $this->_object->id,
            AnchorFieldUserObject::name => $this->_object->name,
            default => parent::__get($name),
        };
    }
}
