<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ArrayObject;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/** Scoped DATA follows its anchor, including lost rows and both ends of a joined-key move. */
final class BrowserContextDataScopeTest extends TestCase
{
    private DataScopeBrowserContext $context;
    private DataScopeRtContext $runtime;

    /** Mount three anchors and two windows of the first one. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = $this->runtime = new DataScopeRtContext();
        $this->runtime->configure();
        $this->context = new DataScopeBrowserContext();
        $this->context->bindHilosFacade(DataScopeHilos::class);
        foreach (['11' => 'qx', '22' => 'qy', '33' => 'qz'] as $id => $code) {
            $this->runtime->put(DataScopeRtContext::ANCHORS, (string)$id, $code);
        }
        foreach (['qx-one' => 'qx', 'qx-two' => 'qx', 'qy-one' => 'qy', 'qz-one' => 'qz'] as $acceptKey => $code) {
            Hilos::$sr->subscribeToPage(
                DataScopeBrowserContext::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, DataScopeBrowserContext::PAGE, ['code' => $code]),
            );
            $snapshot = $this->context->buildSubscribeSnapshot(
                DataScopeBrowserContext::PAGE, $acceptKey, new PageRouteParams(['code' => $code]),
            );
            self::assertSame($code, $snapshot->toArray()[PagePayload::data][DataScopeData::DATA]['code']);
        }
    }

    /** Release the worker-local fixture globals. */
    protected function tearDown(): void
    {
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testJoinedLifecycleUpdatesEveryOwnSubscriberWithoutForeignData(): void
    {
        foreach (['11' => ['qx-one', 'qx-two'], '22' => ['qy-one']] as $owner => $subscribers) {
            $this->runtime->put(DataScopeRtContext::CHILDREN, 'child', 'child-code', (string)$owner);
            $this->assertMemberCounts(
                SourceChange::rtCreated(DataScopeRtContext::CHILDREN, 'child', ['ownerId' => (string)$owner]),
                $subscribers, 1,
            );
            $this->runtime->put(DataScopeRtContext::CHILDREN, 'child', 'changed-code', (string)$owner);
            $this->assertMemberCounts(
                SourceChange::rtUpdated(DataScopeRtContext::CHILDREN, 'child', ['code' => 'changed-code']),
                $subscribers, 1,
            );
            $this->runtime->remove(DataScopeRtContext::CHILDREN, 'child');
            $this->assertMemberCounts(
                SourceChange::rtDeleted(DataScopeRtContext::CHILDREN, 'child', ['ownerId' => (string)$owner]),
                $subscribers, 0,
            );
        }
    }

    public function testJoinedOwnerMoveRebuildsCurrentAndPreviousScopesAndLeavesTheThirdAlone(): void
    {
        $this->runtime->put(DataScopeRtContext::CHILDREN, 'child', 'unrelated', '11');
        $this->assertMemberCounts(
            SourceChange::rtCreated(DataScopeRtContext::CHILDREN, 'child', ['ownerId' => '11']),
            ['qx-one', 'qx-two'], 1,
        );
        $this->runtime->put(DataScopeRtContext::CHILDREN, 'child', 'unrelated', '22');
        $this->context->record(new SourceChange(
            SourceChange::KIND_RT, DataScopeRtContext::CHILDREN, 'child', TableMutationType::Update,
            ['ownerId' => '22'], ['ownerId' => '11'],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());
        $cards = $this->drainCards();
        self::assertSame(['qx-one', 'qx-two', 'qy-one'], array_keys($cards));
        self::assertSame(0, $cards['qx-one']['memberCount']);
        self::assertSame($cards['qx-one'], $cards['qx-two']);
        self::assertSame(1, $cards['qy-one']['memberCount']);
        self::assertSame('qx', $cards['qx-one']['code']);
        self::assertSame('qy', $cards['qy-one']['code']);
    }

    public function testDeletedAnchorClearsOnlyItsOwnDataUsingThePreviousRow(): void
    {
        $this->runtime->remove(DataScopeRtContext::ANCHORS, '11');
        $this->context->record(SourceChange::rtDeleted(DataScopeRtContext::ANCHORS, '11', ['id' => '11', 'code' => 'qx']));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame(['qx-one' => [], 'qx-two' => []], $this->drainCards());
    }

    public function testAnchorWhereMoveClearsPreviousScopeAndRebuildsCurrentScope(): void
    {
        Hilos::$sr->subscribeToPage(
            DataScopeBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('renamed-one', DataScopeBrowserContext::PAGE, ['code' => 'renamed']),
        );
        $this->runtime->put(DataScopeRtContext::ANCHORS, '11', 'renamed');
        $this->context->record(new SourceChange(
            SourceChange::KIND_RT, DataScopeRtContext::ANCHORS, '11', TableMutationType::Update,
            ['code' => 'renamed'], ['code' => 'qx'],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame([
            'qx-one' => [],
            'qx-two' => [],
            'renamed-one' => ['id' => '11', 'code' => 'renamed', 'memberCount' => 0],
        ], $this->drainCards());
    }

    public function testClearKeepsItsCollectionWideMeaning(): void
    {
        foreach (['11', '22', '33'] as $id) {
            $this->runtime->remove(DataScopeRtContext::ANCHORS, $id);
        }
        $this->context->record(new SourceChange(
            SourceChange::KIND_RT, DataScopeRtContext::ANCHORS, '', TableMutationType::Clear,
        ));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame(['qx-one' => [], 'qx-two' => [], 'qy-one' => [], 'qz-one' => []], $this->drainCards());
    }

    public function testJoinedUserNameReachesBothAliasAnchorsWithoutForeignData(): void
    {
        $users = $this->subscribeConnections();
        foreach ([1 => ['own-one', 'own-two'], 2 => ['foreign-one']] as $userId => $subscribers) {
            $users[$userId] = ['id' => $userId, 'name' => 'Renamed ' . $userId];
            $this->context->record(SourceChange::dbUpdated(DataScopeUsers::USERS, (string)$userId, ['name' => 'Renamed ' . $userId]));
            self::assertSame([], $this->context->flushToSignalRouter());
            $cards = $this->drainCards(DataScopeBrowserContext::CONNECTION_PAGE, DataScopeConnectionData::DATA);
            self::assertSame($subscribers, array_keys($cards));
            foreach ($subscribers as $acceptKey) {
                self::assertSame(['acceptKey' => $acceptKey, 'userId' => $userId, 'name' => 'Renamed ' . $userId], $cards[$acceptKey]);
            }
        }
    }

    public function testDeletedAliasAnchorClearsOnlyItsOwnWindowAndLaterNameChangeReachesTheSurvivor(): void
    {
        $users = $this->subscribeConnections();
        $this->runtime->remove(DataScopeRtContext::CONNECTIONS, 'own-one');
        $this->context->record(SourceChange::rtDeleted(
            DataScopeRtContext::CONNECTIONS, 'own-one', ['acceptKey' => 'own-one', 'userId' => 1],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame([
            'own-one' => [],
            'own-two' => ['acceptKey' => 'own-two', 'userId' => 1, 'name' => 'Ada'],
        ], $this->drainCards(DataScopeBrowserContext::CONNECTION_PAGE, DataScopeConnectionData::DATA));

        $users[1] = ['id' => 1, 'name' => 'Survivor'];
        $this->context->record(SourceChange::dbUpdated(DataScopeUsers::USERS, '1', ['name' => 'Survivor']));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame([
            'own-two' => ['acceptKey' => 'own-two', 'userId' => 1, 'name' => 'Survivor'],
        ], $this->drainCards(DataScopeBrowserContext::CONNECTION_PAGE, DataScopeConnectionData::DATA));
    }

    public function testAliasLogicalKeyMoveRebuildsBothLogicalRowsWithoutTouchingTheThird(): void
    {
        $this->subscribeConnections();
        $this->runtime->putConnection('own-one', 3);
        $this->context->record(new SourceChange(
            SourceChange::KIND_RT, DataScopeRtContext::CONNECTIONS, 'own-one', TableMutationType::Update,
            ['userId' => 3], ['userId' => 1],
        ));
        self::assertSame([], $this->context->flushToSignalRouter());
        self::assertSame([
            'own-one' => ['acceptKey' => 'own-one', 'userId' => 3, 'name' => 'Linus'],
            'own-two' => ['acceptKey' => 'own-two', 'userId' => 1, 'name' => 'Ada'],
        ], $this->drainCards(DataScopeBrowserContext::CONNECTION_PAGE, DataScopeConnectionData::DATA));
    }

    /** @return ArrayObject<int, array<string, mixed>> Joined DB users shared only with this test's context */
    private function subscribeConnections(): ArrayObject
    {
        $users = new ArrayObject([
            1 => ['id' => 1, 'name' => 'Ada'],
            2 => ['id' => 2, 'name' => 'Grace'],
            3 => ['id' => 3, 'name' => 'Linus'],
        ]);
        Hilos::$db = new DataScopeUsers($users);
        foreach (['own-one' => 1, 'own-two' => 1, 'foreign-one' => 2] as $acceptKey => $userId) {
            $this->runtime->putConnection($acceptKey, $userId);
            Hilos::$sr->subscribeToPage(
                DataScopeBrowserContext::CONNECTION_PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, DataScopeBrowserContext::CONNECTION_PAGE),
            );
            self::assertSame([
                'acceptKey' => $acceptKey, 'userId' => $userId, 'name' => $users[$userId]['name'],
            ], $this->context->buildSubscribeSnapshot(
                DataScopeBrowserContext::CONNECTION_PAGE, $acceptKey, new PageRouteParams([]),
            )->toArray()[PagePayload::data][DataScopeConnectionData::DATA]);
        }

        return $users;
    }

    /**
     * @param SourceChange $change Actual source fact to record and flush
     * @param list<string> $subscribers Windows of the affected anchor
     * @param int $count Expected joined row count
     */
    private function assertMemberCounts(SourceChange $change, array $subscribers, int $count): void
    {
        $this->context->record($change);
        self::assertSame([], $this->context->flushToSignalRouter());
        $cards = $this->drainCards();
        self::assertSame($subscribers, array_keys($cards));
        foreach ($cards as $acceptKey => $card) {
            self::assertNotSame([], $card);
            self::assertSame(str_starts_with($acceptKey, 'qx') ? 'qx' : 'qy', $card['code']);
            self::assertSame(str_starts_with($acceptKey, 'qx') ? '11' : '22', $card['id']);
            self::assertSame($count, $card['memberCount']);
        }
    }

    /**
     * @param string $page Subscribed fixture page
     * @param string $data Browser DATA key
     * @return array<string, array<string, mixed>> Delivered DATA by actual connection addressee
     */
    private function drainCards(string $page = DataScopeBrowserContext::PAGE, string $data = DataScopeData::DATA): array
    {
        $cards = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            self::assertSame($page, $signal->data->data->pageKey);
            $cards[$signal->data->targetAcceptKey] = $signal->data->data->payload->toArray()[PagePayload::data][$data];
        }
        ksort($cards);
        return $cards;
    }
}

abstract class DataScopeHilos extends Hilos
{
    public const array BROWSER_DATA = [
        DataScopeData::DATA => DataScopeData::class,
        DataScopeConnectionData::DATA => DataScopeConnectionData::class,
    ];
}

final class DataScopeData
{
    public const string DATA = 'scopedCard';
    public const array BROWSER = [
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => DataScopeRtContext::ANCHORS,
                ],
                BrowserDataFieldKey::ROW_KEY => 'id',
                BrowserDataFieldKey::WHERE => [
                    'code' => [BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM, BrowserRefKey::KEY => 'code'],
                ],
                BrowserDataFieldKey::FIELDS => ['id', 'code', 'memberCount'],
            ],
            [
                BrowserDataFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => DataScopeRtContext::CHILDREN,
                ],
                BrowserDataFieldKey::ROW_KEY => 'ownerId',
                BrowserDataFieldKey::MANY => true,
                BrowserDataFieldKey::FIELDS => [],
            ],
        ],
    ];
}

final class DataScopeBrowserContext extends BrowserContext
{
    public const string PAGE = 'data_scope_page';
    public const string CONNECTION_PAGE = 'data_scope_connection_page';

    /**
     * @param string $page Page from the real subscription mirror
     * @return ?BrowserPageConfig Fixture page metadata
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return in_array($page, [self::PAGE, self::CONNECTION_PAGE], true)
            ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'data_scope_signal']) : null;
    }

    /**
     * @param string $page Page from the real subscription mirror
     * @return BrowserPageBindings Route code bound into the DATA anchor WHERE
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page === self::CONNECTION_PAGE) {
            return BrowserPageBindings::fromArray([
                DataScopeConnectionData::DATA => [BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => [BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY],
                ]],
            ]);
        }
        return BrowserPageBindings::fromArray([
            DataScopeData::DATA => [BrowserParamKey::PARAMS => [
                'code' => [BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM, BrowserRefKey::KEY => 'code'],
            ]],
        ]);
    }
}

/** The fixture's only storage is private to this test and its one BrowserContext. */
final class DataScopeRtContext extends RtContext
{
    public const string ANCHORS = 'dataScopeAnchors';
    public const string CHILDREN = 'dataScopeChildren';
    public const string CONNECTIONS = 'dataScopeConnections';

    /** Mount the two source collections. */
    public function configure(): void
    {
        foreach ([self::ANCHORS, self::CHILDREN] as $key) {
            $this->_stateCollections[$key] = DataScopeStates::init();
            $this->setRepresent($key, DataScopeCollection::class);
        }
        $this->_stateCollections[self::CONNECTIONS] = DataScopeConnectionStates::init();
        $this->setRepresent(self::CONNECTIONS, DataScopeConnections::class);
    }

    /**
     * @param string $acceptKey Storage primary id, distinct from the logical browser row key
     * @param int $userId Logical browser row key
     */
    public function putConnection(string $acceptKey, int $userId): void
    {
        $this->remove(self::CONNECTIONS, $acceptKey);
        $this->_stateCollections[self::CONNECTIONS]->add(DataScopeConnectionState::fromRow([
            'acceptKey' => $acceptKey, 'userId' => $userId,
        ]));
        $this->{self::CONNECTIONS}->clearCache();
    }

    /**
     * @param string $collection Fixture collection key
     * @param string $id Internal primary key
     * @param string $code External route code, deliberately distinct from the primary key
     * @param ?string $ownerId Joined anchor key for a child
     */
    public function put(string $collection, string $id, string $code, ?string $ownerId = null): void
    {
        $this->_stateCollections[$collection]->add(DataScopeState::fromRow(['id' => $id, 'code' => $code, 'ownerId' => $ownerId]));
        $this->{$collection}->clearCache();
    }

    /**
     * @param string $collection Fixture collection key
     * @param string $id Internal primary key to remove before recording the source fact
     */
    public function remove(string $collection, string $id): void
    {
        $this->_stateCollections[$collection]->remove($id);
        $this->{$collection}->clearCache();
    }
}

final class DataScopeStates extends RtStates
{
    public const string STATE_CLASS = DataScopeState::class;
}

final class DataScopeState extends RtState
{
    /**
     * @param string $id Primary key
     * @param string $code Route code
     * @param ?string $ownerId Joined anchor primary key
     */
    private function __construct(public readonly string $id, public readonly string $code, public readonly ?string $ownerId)
    {
        parent::__construct();
    }

    /** @return string Primary key */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Fixture source row
     * @return static Fixture state
     */
    public static function fromRow(array $row): static
    {
        return new static(self::requireString($row, 'id'), self::requireString($row, 'code'), self::optionalString($row, 'ownerId'));
    }

    /** @return array<string, mixed> Fixture source row */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'ownerId' => $this->ownerId];
    }
}

final class DataScopeCollection extends RtCollection
{
    /**
     * @param RtState $state Fixture state
     * @return RtItem Readable source item
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new DataScopeItem($state);
    }
}

/** @extends RtItem<DataScopeState> */
final class DataScopeItem extends RtItem
{
    /**
     * @param string $name Source field or computed aggregate
     * @return mixed Source value
     */
    public function __get(string $name): mixed
    {
        if ($name === 'memberCount') {
            $count = 0;
            foreach (Hilos::$rt->{DataScopeRtContext::CHILDREN} as $child) {
                if ($child->ownerId === $this->_state->id) {
                    $count++;
                }
            }
            return $count;
        }
        return match ($name) {
            'id' => $this->_state->id,
            'code' => $this->_state->code,
            'ownerId' => $this->_state->ownerId,
            default => parent::__get($name),
        };
    }

    /** @return array<string, mixed> Fixture source row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/** A connection is stored by acceptKey but contributes to the user's logical DATA row. */
final class DataScopeConnectionData
{
    public const string DATA = 'scopedConnection';
    public const array BROWSER = [
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => DataScopeRtContext::CONNECTIONS,
                ],
                BrowserDataFieldKey::ROW_KEY => 'userId',
                BrowserDataFieldKey::WHERE => [
                    'acceptKey' => [BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM, BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY],
                ],
                BrowserDataFieldKey::FIELDS => ['acceptKey', 'userId'],
            ],
            [
                BrowserDataFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => DataScopeUsers::USERS,
                ],
                BrowserDataFieldKey::ROW_KEY => 'id',
                BrowserDataFieldKey::VIA => ['id' => 'userId'],
                BrowserDataFieldKey::FIELDS => ['name'],
            ],
        ],
    ];
}

/** In-test DB boundary; the test mutates rows, and the real browser reads and projects them. */
final class DataScopeUsers extends HilosDbContext
{
    public const string USERS = 'dataScopeUsers';

    /** @param ArrayObject<int, array<string, mixed>> $users Shared fixture user rows */
    public function __construct(private readonly ArrayObject $users)
    {
        parent::__construct();
    }

    /**
     * @param string $name Declared DB source key
     * @return ArrayObject<int, array<string, mixed>> Joined fixture rows
     */
    public function __get(string $name)
    {
        return $this->users;
    }
}

final class DataScopeConnectionStates extends RtStates
{
    public const string STATE_CLASS = DataScopeConnectionState::class;
}

final class DataScopeConnectionState extends RtState
{
    /**
     * @param string $acceptKey Storage primary id
     * @param int $userId Logical browser row key
     */
    private function __construct(public readonly string $acceptKey, public readonly int $userId)
    {
        parent::__construct();
    }

    /** @return string Storage primary id */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @param array<string, mixed> $row Fixture connection row
     * @return static Fixture state
     */
    public static function fromRow(array $row): static
    {
        return new static(self::requireString($row, 'acceptKey'), self::requireInt($row, 'userId'));
    }

    /** @return array<string, mixed> Fixture connection row */
    public function toArray(): array
    {
        return ['acceptKey' => $this->acceptKey, 'userId' => $this->userId];
    }
}

final class DataScopeConnections extends RtCollection
{
    /**
     * @param RtState $state Fixture connection state
     * @return RtItem Readable connection
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new DataScopeConnectionItem($state);
    }
}

/** @extends RtItem<DataScopeConnectionState> */
final class DataScopeConnectionItem extends RtItem
{
    /**
     * @param string $name Source field
     * @return mixed Connection value
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'acceptKey' => $this->_state->acceptKey,
            'userId' => $this->_state->userId,
            default => parent::__get($name),
        };
    }

    /** @return array<string, mixed> Fixture connection row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
