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
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Browser lists must follow a joined row across owner keys without leaking foreign deletes.
 */
final class BrowserContextListKeyMoveTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-owner-1';
    private const string FOREIGN_ACCEPT_KEY = 'ak-owner-2';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new ListKeyMoveRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addConnection(ListKeyMoveConnectionState::create(self::ACCEPT_KEY, 1));
        Hilos::$sr->subscribeToPage(
            ListKeyMoveBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, ListKeyMoveBrowserContext::PAGE),
        );
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testUpdateLeavingAnOwnerRebuildsThePreviousOwnerRow(): void
    {
        Hilos::$rt?->addMembership(ListKeyMoveMembershipState::create('membership-1', null, self::ACCEPT_KEY));

        $context = new ListKeyMoveBrowserContext();
        $context->record(SourceChange::fromArray([
            SourceChange::FIELD_KIND => SourceChange::KIND_RT,
            SourceChange::FIELD_SOURCE_KEY => ListKeyMoveRtContext::MEMBERSHIPS,
            SourceChange::FIELD_SOURCE_ID => 'membership-1',
            SourceChange::FIELD_MUTATION_TYPE => TableMutationType::Update->value,
            SourceChange::FIELD_ROW => [ListKeyMoveMembershipState::userId => null],
            SourceChange::FIELD_PREVIOUS => [ListKeyMoveMembershipState::userId => 1],
        ]));
        $context->flushToSignalRouter();

        $rows = $this->rowsOfNextPageResponse();
        self::assertSame(1, $rows[0][PagePayload::rowKey]);
        self::assertSame([], $rows[0][PagePayload::slots][ListKeyMoveRtContext::MEMBERSHIPS]);
    }

    public function testForeignOwnerChangeSendsNeitherRowNorDeleteToSelfAnchoredList(): void
    {
        Hilos::$rt?->addMembership(ListKeyMoveMembershipState::create('membership-2', 2, self::FOREIGN_ACCEPT_KEY));

        $context = new ListKeyMoveBrowserContext();
        $context->record(SourceChange::rtUpdated(
            ListKeyMoveRtContext::MEMBERSHIPS,
            'membership-2',
            [ListKeyMoveMembershipState::userId => 2],
        ));
        $context->flushToSignalRouter();

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAcceptKeyScopedListKeepsTheSourceItemKey(): void
    {
        Hilos::$sr?->subscribeToPage(
            ListKeyMoveBrowserContext::SCOPED_PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, ListKeyMoveBrowserContext::SCOPED_PAGE),
        );
        $membership = ListKeyMoveMembershipState::create('membership-3', null, self::ACCEPT_KEY);
        Hilos::$rt?->addMembership($membership);

        $context = new ListKeyMoveBrowserContext();
        $context->record(SourceChange::rtCreated(
            ListKeyMoveRtContext::MEMBERSHIPS,
            $membership->getId(),
            $membership->toArray(),
        ));
        $context->flushToSignalRouter();

        $rows = $this->rowsOfNextPageResponse(ListKeyMoveBrowserContext::SCOPED_LIST);
        self::assertSame('membership-3', $rows[0][PagePayload::rowKey]);
    }

    /**
     * @param string $list Browser list key to read
     * @return list<array<string, mixed>> Browser rows sent to the subscriber
     */
    private function rowsOfNextPageResponse(string $list = ListKeyMoveBrowserContext::LIST): array
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        return $signal->data->data->toArray()[PageResponseSignalData::payload]
            [PagePayload::tables][$list][PagePayload::rows];
    }
}

/**
 * A self-anchored list whose joined membership can move between owners.
 */
final class ListKeyMoveBrowserContext extends BrowserContext
{
    public const string PAGE = 'list_key_move_page';
    public const string SCOPED_PAGE = 'list_key_move_scoped_page';
    public const string SIGNAL = 'list_key_move_signal';
    public const string LIST = 'listKeyMove';
    public const string SCOPED_LIST = 'listKeyMoveScoped';

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When the page declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE && $page !== self::SCOPED_PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page === self::SCOPED_PAGE) {
            return BrowserPageBindings::fromArray([
                self::SCOPED_LIST => [
                    BrowserParamKey::PARAMS => [
                        BrowserRuntimeParam::ACCEPT_KEY => [BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY],
                    ],
                ],
            ]);
        }
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            self::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => [BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY],
                ],
            ],
        ]);
    }

    /**
     * @param string $browserKey Browser list key
     * @return ?BrowserSourceConfig Test list metadata, or null when absent
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey === self::SCOPED_LIST) {
            return BrowserSourceConfig::fromArray([
                BrowserListConfigKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => [
                        BrowserParamKey::TYPE => BrowserParamType::STRING,
                        BrowserParamKey::REQUIRED => true,
                    ],
                ],
                BrowserListConfigKey::ITEMS => [
                    [
                        BrowserFieldKey::SOURCE => $this->source(ListKeyMoveRtContext::MEMBERSHIPS),
                        BrowserListFieldKey::ITEM_KEY => ListKeyMoveMembershipState::id,
                        BrowserListFieldKey::WHERE => [
                            ListKeyMoveMembershipState::acceptKey => [
                                BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                                BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
                            ],
                        ],
                        BrowserListFieldKey::FIELDS => [
                            ListKeyMoveMembershipState::id,
                            ListKeyMoveMembershipState::acceptKey,
                        ],
                    ],
                ],
            ]);
        }
        if ($browserKey !== self::LIST) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserListConfigKey::PARAMS => [
                BrowserRuntimeParam::ACCEPT_KEY => [
                    BrowserParamKey::TYPE => BrowserParamType::STRING,
                    BrowserParamKey::REQUIRED => true,
                ],
            ],
            BrowserListConfigKey::ITEMS => [
                [
                    BrowserFieldKey::SOURCE => $this->source(ListKeyMoveRtContext::CONNECTIONS),
                    BrowserListFieldKey::ITEM_KEY => ListKeyMoveConnectionState::userId,
                    BrowserListFieldKey::WHERE => [
                        ListKeyMoveConnectionState::acceptKey => [
                            BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                            BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
                        ],
                    ],
                    BrowserListFieldKey::FIELDS => [
                        ListKeyMoveConnectionState::acceptKey,
                        ListKeyMoveConnectionState::userId,
                    ],
                ],
                [
                    BrowserFieldKey::SOURCE => $this->source(ListKeyMoveRtContext::MEMBERSHIPS),
                    BrowserListFieldKey::ITEM_KEY => ListKeyMoveMembershipState::userId,
                    BrowserListFieldKey::MANY => true,
                    BrowserListFieldKey::FIELDS => [
                        ListKeyMoveMembershipState::id,
                        ListKeyMoveMembershipState::userId,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param string $collectionKey Runtime collection key
     * @return array<string, string> Runtime source declaration
     */
    private function source(string $collectionKey): array
    {
        return [
            BrowserSourceKey::TYPE => BrowserSourceType::RT,
            BrowserSourceKey::KEY => $collectionKey,
        ];
    }
}

/**
 * Runtime context holding the list anchor and its joined rows.
 */
final class ListKeyMoveRtContext extends RtContext
{
    public const string CONNECTIONS = 'listKeyMoveConnections';
    public const string MEMBERSHIPS = 'listKeyMoveMemberships';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = ListKeyMoveConnectionStates::init();
        $this->_stateCollections[self::MEMBERSHIPS] = ListKeyMoveMembershipStates::init();
        $this->setRepresent(self::CONNECTIONS, ListKeyMoveConnectionCollection::class);
        $this->setRepresent(self::MEMBERSHIPS, ListKeyMoveMembershipCollection::class);
    }

    /**
     * @param ListKeyMoveConnectionState $connection Connection row to mount
     */
    public function addConnection(ListKeyMoveConnectionState $connection): void
    {
        $this->_stateCollections[self::CONNECTIONS]->add($connection);
    }

    /**
     * @param ListKeyMoveMembershipState $membership Membership row to mount
     */
    public function addMembership(ListKeyMoveMembershipState $membership): void
    {
        $this->_stateCollections[self::MEMBERSHIPS]->add($membership);
    }
}

final class ListKeyMoveConnectionStates extends RtStates
{
    public const string STATE_CLASS = ListKeyMoveConnectionState::class;
}

final class ListKeyMoveMembershipStates extends RtStates
{
    public const string STATE_CLASS = ListKeyMoveMembershipState::class;
}

final class ListKeyMoveConnectionState extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';

    private function __construct(
        private(set) string $acceptKey,
        private(set) int $userId,
    ) {
        parent::__construct();
    }

    public static function create(string $acceptKey, int $userId): static
    {
        $connection = new static($acceptKey, $userId);
        $connection->markRtSyncBaseline();

        return $connection;
    }

    public static function getRtCollectionKey(): string
    {
        return ListKeyMoveRtContext::CONNECTIONS;
    }

    public function getId(): string
    {
        return $this->acceptKey;
    }

    public static function fromRow(array $row): static
    {
        return static::create(
            self::requireString($row, self::acceptKey),
            self::requireInt($row, self::userId),
        );
    }

    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
        ];
    }
}

final class ListKeyMoveMembershipState extends RtState
{
    public const string id = 'id';
    public const string userId = 'userId';
    public const string acceptKey = 'acceptKey';

    private function __construct(
        private(set) string $id,
        private(set) ?int $userId,
        private(set) string $acceptKey,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Membership row id
     * @param ?int $userId Joined user id, or null when the membership has no owner
     * @param string $acceptKey Connection that owns the membership
     * @return static Membership state ready for the collection
     */
    public static function create(string $id, ?int $userId, string $acceptKey): static
    {
        $membership = new static($id, $userId, $acceptKey);
        $membership->markRtSyncBaseline();

        return $membership;
    }

    public static function getRtCollectionKey(): string
    {
        return ListKeyMoveRtContext::MEMBERSHIPS;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public static function fromRow(array $row): static
    {
        return static::create(
            self::requireString($row, self::id),
            self::optionalInt($row, self::userId),
            self::requireString($row, self::acceptKey),
        );
    }

    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::userId => $this->userId,
            self::acceptKey => $this->acceptKey,
        ];
    }
}

final class ListKeyMoveConnectionCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new ListKeyMoveConnectionItem($state);
    }
}

final class ListKeyMoveMembershipCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new ListKeyMoveMembershipItem($state);
    }
}

/** @extends RtItem<ListKeyMoveConnectionState> */
final class ListKeyMoveConnectionItem extends RtItem
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            ListKeyMoveConnectionState::acceptKey => $this->_state->acceptKey,
            ListKeyMoveConnectionState::userId => $this->_state->userId,
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/** @extends RtItem<ListKeyMoveMembershipState> */
final class ListKeyMoveMembershipItem extends RtItem
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            ListKeyMoveMembershipState::id => $this->_state->id,
            ListKeyMoveMembershipState::userId => $this->_state->userId,
            ListKeyMoveMembershipState::acceptKey => $this->_state->acceptKey,
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
