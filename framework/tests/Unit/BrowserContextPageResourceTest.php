<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * A live page whose row disappeared is refused before this flush's deltas, from the raw buffer.
 *
 * Grouping can fold a delete into a later create of the same id. The refusal looks first.
 * The author of the action is not sent the sentence; every other open card of that primary
 * id is, once. Pages that never named a row keep their own deltas.
 */
final class BrowserContextPageResourceTest extends TestCase
{
    private const string REQUEST_ID = 'req-delete';

    private string $logFile = '';

    private ?RtContext $previousRt = null;

    private PageResourceBrowser $context;

    private PageSignalRouter $router;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-page-resource');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
        $runtime = new PageResourceRtContext();
        $runtime->configure();
        $runtime->addRow(PageResourceNoteState::create('1', 'Ada'));
        Hilos::$rt = $runtime;
        Hilos::$table = new PageResourceTableContext();
        Hilos::$table->configure();
        $this->router = new PageSignalRouter(
            new PageResourcePageFactory(new PageResourceAgent()),
            new ActionRouteConfig(),
        );
        $this->context = new PageResourceBrowser();
        $this->context->bindPageResender(new PageResourceRouterPort($this->router));
        Hilos::$browser = $this->context;
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Hilos::$sr = null;
        Hilos::$rt = $this->previousRt;
        Hilos::$table = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testADeleteRefusesBothTabsAndTheOtherConnectionButNotTheAuthor(): void
    {
        $this->openCard('ak-overview', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-names', PageResourceBrowser::NAMES, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-same-user', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-initiator', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-foreign', PageResourceBrowser::OVERVIEW, PageResourceBrowser::OTHER_COUNTRY_ID);
        $this->openList('ak-list');
        $this->openWatch('ak-watch');
        $this->queueAck('ak-initiator');

        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            ['code' => 'se'],
            'ak-initiator',
            self::REQUEST_ID,
        ));
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            ['code' => 'se'],
        ));
        $this->context->record(SourceChange::dbDeleted('pageResourceCountryNames', '70', ['countryId' => '7']));
        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Grace']));
        $this->context->recordSourceStaleness(PageResourceBrowser::NOTES, ['1']);
        $this->context->recordDatabaseBack();

        $this->assertSame([], $this->context->flushToSignalRouter());

        $signals = $this->signalsByAcceptKey();
        $this->assertDeletion($signals['ak-overview'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertDeletion($signals['ak-names'] ?? [], PageResourceBrowser::NAMES);
        $this->assertDeletion($signals['ak-same-user'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertSame([SignalConstants::ACTION_SUCCESS], $this->namesOf($signals['ak-initiator'] ?? []));
        $ack = $signals['ak-initiator'][0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $ack);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack->data);
        $this->assertSame(self::REQUEST_ID, $ack->data->requestId);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($signals['ak-foreign'] ?? []));
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($signals['ak-list'] ?? []));
        $watch = $this->namesOf($signals['ak-watch'] ?? []);
        $this->assertContains(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $watch);
        $this->assertContains(SignalTypeConstants::TABLE_VIEWPORT_COUNT, $watch);
        $this->assertContains(SignalTypeConstants::TABLE_FACET_COUNTS, $watch);
        $this->assertNotContains(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $watch);
        $this->assertNull(Hilos::$sr?->pageResource('ak-overview'));
        $this->assertNull(Hilos::$sr?->pageResource('ak-names'));
        $this->assertNull(Hilos::$sr?->pageResource('ak-initiator'));
        $this->assertSame(
            PageResourceBrowser::OTHER_COUNTRY_ID,
            Hilos::$sr?->pageResource('ak-foreign')?->idString,
        );
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure('ak-overview'));
        $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure('ak-initiator'));
        $this->assertSame(PageResourceBrowser::OVERVIEW, Hilos::$sr?->pageSubscription('ak-overview')?->page);
        $this->assertSame(4, $this->context->userIdOf('ak-overview'));
        $this->assertSame(4, $this->context->userIdOf('ak-same-user'));
        $this->assertSame(4, $this->context->userIdOf('ak-initiator'));
    }

    public function testAClearRefusesEveryOpenCardOfThatCollection(): void
    {
        $this->openCard('ak-overview', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->openCard('ak-foreign', PageResourceBrowser::OVERVIEW, PageResourceBrowser::OTHER_COUNTRY_ID);
        $this->openList('ak-list');
        $this->context->record(SourceChange::dbCleared(PageResourceBrowser::COUNTRIES));
        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Grace']));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey();
        $this->assertDeletion($signals['ak-overview'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertDeletion($signals['ak-foreign'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($signals['ak-list'] ?? []));
    }

    public function testADifferentCountryAndAnRtDeleteDoNotRefuse(): void
    {
        $this->openCard('ak-overview', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::OTHER_COUNTRY_ID,
            ['code' => 'no'],
        ));
        $this->context->record(SourceChange::rtDeleted(PageResourceBrowser::COUNTRIES, PageResourceBrowser::COUNTRY_ID));

        $this->context->flushToSignalRouter();

        $this->assertSame([], $this->signalsByAcceptKey());
        $this->assertSame(
            PageResourceBrowser::COUNTRY_ID,
            Hilos::$sr?->pageResource('ak-overview')?->idString,
        );
    }

    public function testADeleteWithoutAnActionRequestRefusesItsOriginToo(): void
    {
        $this->openCard('ak-initiator', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->openCard('ak-same-user', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            [],
            'ak-initiator',
            null,
        ));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey();
        $this->assertDeletion($signals['ak-initiator'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertDeletion($signals['ak-same-user'] ?? [], PageResourceBrowser::OVERVIEW);
    }

    public function testAnAlreadyOwedPageIsNotRedrawnAndTheNextFactAnswersItWhole(): void
    {
        $this->openCard('ak-overview', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID, window: true);
        Hilos::$sr?->markPageDeliveryFailure('ak-overview');
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
        ));
        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Grace']));
        $this->context->recordDatabaseBack();

        $this->context->flushToSignalRouter();

        $this->assertSame([], $this->signalsByAcceptKey()['ak-overview'] ?? []);
        $this->assertNull(Hilos::$sr?->pageResource('ak-overview'));
        $this->assertTrue(Hilos::$sr?->hasPageDeliveryFailure('ak-overview'));

        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Lin']));
        $this->context->flushToSignalRouter();

        $again = $this->signalsByAcceptKey()['ak-overview'] ?? [];
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $this->namesOf($again)[0] ?? null);
        $this->assertNotContains(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $this->namesOf($again));
        $frame = $again[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $frame);
        $this->assertInstanceOf(PageResponseSignalData::class, $frame->data);
        $this->assertSame(PageResourceBrowser::OVERVIEW, $frame->data->payload->data['own'] ?? null);
        $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure('ak-overview'));
    }

    public function testARecreatedRowWithTheSameCodeDoesNotReceiveTheOldDelete(): void
    {
        $this->openCard('ak-old', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->openCard('ak-new', PageResourceBrowser::OVERVIEW, PageResourceBrowser::RECREATED_ID);
        $this->openWatch('ak-watch');
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            ['code' => 'se'],
        ));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey();
        $this->assertDeletion($signals['ak-old'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertSame([], $signals['ak-new'] ?? []);
        $this->assertNull(Hilos::$sr?->pageResource('ak-old'));
        $this->assertSame(PageResourceBrowser::RECREATED_ID, Hilos::$sr?->pageResource('ak-new')?->idString);
        $this->assertContains(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $this->namesOf($signals['ak-watch'] ?? []));
    }

    public function testADeleteCollapsedByGroupingIsStillRefused(): void
    {
        $grouping = new PageResourceGroupingBrowser();
        $grouping->bindPageResender(new PageResourceRouterPort($this->router));
        Hilos::$browser = $grouping;
        $this->openCard('ak-overview', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $grouping->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            ['code' => 'se'],
        ));
        $grouping->record(SourceChange::dbCreated(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
            ['id' => PageResourceBrowser::COUNTRY_ID, 'code' => 'se'],
        ));

        $grouping->flushToSignalRouter();

        $this->assertSame([TableMutationType::Update], $grouping->groupedMutations);
        $signals = $this->signalsByAcceptKey();
        $this->assertDeletion($signals['ak-overview'] ?? [], PageResourceBrowser::OVERVIEW);
    }

    public function testAnUnservedPageAndAThrowStayInsideOneSubscription(): void
    {
        $this->context->bindPageResender(new PageResourceScriptedPort(
            new PageResourceRouterPort($this->router),
            throwFor: 'ak-throw',
            unservedFor: 'ak-unserved',
        ));
        $this->openCard('ak-throw', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-unserved', PageResourceBrowser::NAMES, PageResourceBrowser::COUNTRY_ID, window: true);
        $this->openCard('ak-ok', PageResourceBrowser::OVERVIEW, PageResourceBrowser::COUNTRY_ID);
        $this->openList('ak-list');
        $this->context->record(SourceChange::dbDeleted(
            PageResourceBrowser::COUNTRIES,
            PageResourceBrowser::COUNTRY_ID,
        ));
        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Grace']));

        $contained = $this->context->flushToSignalRouter();

        $this->assertCount(2, $contained);
        $this->assertSame('deleted-page refusal failed', $contained[0]->failure->getMessage());
        $this->assertStringContainsString('ak-throw', $contained[0]->address);
        $this->assertStringContainsString('nobody serves here', $contained[1]->failure->getMessage());
        $this->assertStringContainsString('ak-unserved', $contained[1]->address);
        $signals = $this->signalsByAcceptKey();
        $this->assertDeliveryFailure($signals['ak-throw'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertDeliveryFailure($signals['ak-unserved'] ?? [], PageResourceBrowser::NAMES);
        $this->assertDeletion($signals['ak-ok'] ?? [], PageResourceBrowser::OVERVIEW);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($signals['ak-list'] ?? []));
        $this->assertSame(
            PageResourceBrowser::COUNTRY_ID,
            Hilos::$sr?->pageResource('ak-throw')?->idString,
        );

        $this->context->record(SourceChange::rtUpdated(PageResourceBrowser::NOTES, '1', ['name' => 'Lin']));
        $this->assertSame([], $this->context->flushToSignalRouter());
        $again = $this->signalsByAcceptKey();
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $this->namesOf($again['ak-throw'] ?? [])[0] ?? null);
        $this->assertNotContains(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $this->namesOf($again['ak-throw'] ?? []));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $this->namesOf($again['ak-unserved'] ?? [])[0] ?? null);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($again['ak-list'] ?? []));
        $thrown = $again['ak-throw'][0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $thrown);
        $this->assertInstanceOf(PageResponseSignalData::class, $thrown->data);
        $this->assertSame(PageResourceBrowser::OVERVIEW, $thrown->data->payload->data['own'] ?? null);
    }

    /**
     * @param string $acceptKey Connection to subscribe
     * @param string $page Page key
     * @param string $countryId Primary id the page is about
     * @param bool $window Whether the connection holds the country window and its facets
     */
    private function openCard(string $acceptKey, string $page, string $countryId, bool $window = false): void
    {
        Hilos::$sr?->subscribeToPage(
            $page,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page, ['code' => 'se']),
        );
        Hilos::$sr?->bindPageResource($acceptKey, PageResource::db(PageResourceBrowser::COUNTRIES, $countryId));
        if ($window) {
            $this->holdWindow($acceptKey, $countryId);
        }
    }

    /**
     * @param string $acceptKey Connection subscribed to the list, which names no row
     */
    private function openList(string $acceptKey): void
    {
        Hilos::$sr?->subscribeToPage(
            PageResourceBrowser::LIST,
            new WebSocketPageSubscribeSignalDTO($acceptKey, PageResourceBrowser::LIST),
        );
    }

    /**
     * @param string $acceptKey Connection watching the country window without a page resource
     */
    private function openWatch(string $acceptKey): void
    {
        Hilos::$sr?->subscribeToPage(
            PageResourceBrowser::WATCH,
            new WebSocketPageSubscribeSignalDTO($acceptKey, PageResourceBrowser::WATCH),
        );
        $this->holdWindow($acceptKey, PageResourceBrowser::COUNTRY_ID);
    }

    /**
     * @param string $acceptKey Connection whose window holds the row
     * @param string $rowKey Row the window was given
     */
    private function holdWindow(string $acceptKey, string $rowKey): void
    {
        $viewport = new TableViewportSubscription(tableKey: PageResourceBrowser::WINDOW, limit: 10);
        $viewport->recordRow($rowKey, [
            PagePayload::rowKey => $rowKey,
            PagePayload::slots => [],
        ]);
        $viewport->recordTotal(1, true);
        Hilos::$sr?->setTableViewport($acceptKey, $viewport);
        Hilos::$sr?->setTableFacets($acceptKey, PageResourceBrowser::WINDOW, ['kind' => ['open']]);
    }

    /**
     * @param string $acceptKey Connection the acknowledgement is addressed to
     * @throws InvalidArgumentException When the action-success signal cannot be named
     */
    private function queueAck(string $acceptKey): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::AGENT, 'test'),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::ACTION_SUCCESS),
            signalData: new WebSocketSignalData(
                data: new PageActionSuccessSignalData('country.delete', self::REQUEST_ID),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * @return array<string, list<SignalDTO>> Queued signals grouped by accept key, oldest first
     */
    private function signalsByAcceptKey(): array
    {
        $grouped = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $grouped[$signal->data->targetAcceptKey][] = $signal;
        }

        return $grouped;
    }

    /**
     * @param list<SignalDTO> $signals Signals one connection was sent
     * @return list<string> Signal names, oldest first
     */
    private function namesOf(array $signals): array
    {
        return array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals);
    }

    /**
     * @param list<SignalDTO> $signals Signals one connection was sent
     * @param string $page Page the deletion sentence must name
     */
    private function assertDeletion(array $signals, string $page): void
    {
        $this->assertCount(1, $signals);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $error = $signals[0]->data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame($page, $error->page);
        $this->assertSame(404, $error->httpCode);
        $this->assertSame('not_found', $error->errorCode);
        $this->assertSame(PageErrorMessages::RESOURCE_DELETED_MESSAGE, $error->transitionMessage);
    }

    /**
     * @param list<SignalDTO> $signals Signals one connection was sent
     * @param string $page Page the delivery failure must name
     */
    private function assertDeliveryFailure(array $signals, string $page): void
    {
        $this->assertCount(1, $signals);
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $error = $signals[0]->data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame($page, $error->page);
        $this->assertSame(500, $error->httpCode);
        $this->assertSame('internal_error', $error->errorCode);
        $this->assertNull($error->transitionMessage);
    }
}

/**
 * Browser context whose country cards name a row and whose list does not.
 *
 * The same user id sits behind several accept keys, so a refusal keyed by account
 * instead of by connection would hide the other tab.
 */
class PageResourceBrowser extends BrowserContext
{
    public const string COUNTRIES = 'pageResourceCountries';

    public const string NOTES = 'pageResourceNotes';

    public const string OVERVIEW = 'page_resource_country';

    public const string NAMES = 'page_resource_country_names';

    public const string LIST = 'page_resource_country_list';

    public const string WATCH = 'page_resource_country_watch';

    public const string WINDOW = 'pageResourceWindow';

    public const string NOTE_TABLE = 'pageResourceNotesTable';

    public const string COUNTRY_ID = '7';

    public const string OTHER_COUNTRY_ID = '9';

    public const string RECREATED_ID = '8';

    private const string SIGNAL = 'page_resource_signal';

    /** @var array<string, int> */
    public const array USERS = [
        'ak-overview' => 4,
        'ak-names' => 4,
        'ak-same-user' => 4,
        'ak-initiator' => 4,
        'ak-foreign' => 5,
        'ak-list' => 4,
        'ak-watch' => 6,
        'ak-old' => 4,
        'ak-new' => 4,
        'ak-throw' => 4,
        'ak-unserved' => 4,
        'ak-ok' => 4,
    ];

    /**
     * @param string $acceptKey Connection whose user is being read
     * @return ?int User id the fixture settled, or null for a guest
     */
    public function userIdOf(string $acceptKey): ?int
    {
        return $this->resolveConnectionIdentity($acceptKey)->userId;
    }

    /**
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity Settled identity of the fixture's user map
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(self::USERS[$acceptKey] ?? null);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if (!in_array($page, [self::OVERVIEW, self::NAMES, self::LIST, self::WATCH], true)) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return match ($page) {
            self::OVERVIEW, self::NAMES => BrowserPageBindings::fromArray([
                self::WINDOW => [],
                self::NOTE_TABLE => [],
            ]),
            self::LIST => BrowserPageBindings::fromArray([self::NOTE_TABLE => []]),
            self::WATCH => BrowserPageBindings::fromArray([self::WINDOW => []]),
            default => BrowserPageBindings::empty(),
        };
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig The notes list, or null for the viewport table
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::NOTE_TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => self::NOTES,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'name'],
            ]],
        ]);
    }
}

/**
 * Reads the mutation grouping left behind, so a delete folded into an update is still visible.
 */
final class PageResourceGroupingBrowser extends PageResourceBrowser
{
    /** @var list<TableMutationType> */
    public array $groupedMutations = [];

    /**
     * Records the mutation types grouping kept.
     */
    protected function groupSourceChanges(): void
    {
        parent::groupSourceChanges();
        foreach ($this->changes->all() as $change) {
            $this->groupedMutations[] = $change->mutationType;
        }
    }
}

/**
 * Country window: a delete or a clear of the country collection removes the held row.
 */
final class PageResourceWindowTable extends TableDefinition implements SelfSnapshotTable
{
    /**
     * @param SourceChange $change Source change to turn into a row mutation
     * @return ?TableRowMutationDTO A delete of the changed id, or of the held country when the collection was cleared
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if (!$change->isDb() || $change->sourceKey !== PageResourceBrowser::COUNTRIES) {
            return null;
        }
        if ($change->mutationType !== TableMutationType::Delete && $change->mutationType !== TableMutationType::Clear) {
            return null;
        }

        $rowKey = $change->sourceId !== '' ? $change->sourceId : PageResourceBrowser::COUNTRY_ID;

        return new TableRowMutationDTO(TableMutationType::Delete, $rowKey);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot of the held country
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([['id' => PageResourceBrowser::COUNTRY_ID]], $query);
    }

    /**
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [PageResourceBrowser::WINDOW => $row->toArray()],
        ];
    }

    /**
     * @param TableQueryDTO $query Window query the counts are beside
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> One exact count per asked option
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $counts = [];
        foreach ($wanted as $filter => $options) {
            $optionCounts = [];
            foreach ($options as $option) {
                $optionCounts[$option] = new TableFacetCountDTO(1, true);
            }
            $counts[$filter] = [
                'any' => new TableFacetCountDTO(1, true),
                'options' => $optionCounts,
            ];
        }

        return $counts;
    }
}

/**
 * Registers the country window.
 */
final class PageResourceTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(PageResourceBrowser::WINDOW, new PageResourceWindowTable());
    }
}

/**
 * Hands the browser context the page router's own re-send and deletion refusal.
 *
 * The router answers both, and it is not itself the {@see PageResender} the context binds.
 */
final class PageResourceRouterPort implements PageResender
{
    /**
     * @param PageSignalRouter $router Router that forms the refusal frame
     */
    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When a frame of the answer cannot be named
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        return $this->router->resendPage($page, $acceptKey, $params);
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the refusal is addressed to
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return $this->router->refuseDeletedResource($page, $acceptKey);
    }
}

/**
 * The router port, with two connections scripted to miss.
 */
final class PageResourceScriptedPort implements PageResender
{
    /**
     * @param PageResender $inner Port that refuses every other connection
     * @param string $throwFor Accept key whose refusal throws
     * @param string $unservedFor Accept key whose refusal is unserved
     */
    public function __construct(
        private readonly PageResender $inner,
        private readonly string $throwFor,
        private readonly string $unservedFor,
    ) {
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription
     * @return PageResendOutcome What the inner port answered
     * @throws InvalidArgumentException When a frame of the answer cannot be named
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        return $this->inner->resendPage($page, $acceptKey, $params);
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the refusal is addressed to
     * @return PageResendOutcome What the inner port answered, or Unserved for the scripted connection
     * @throws LogicException When the scripted connection is the one whose refusal fails
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        if ($acceptKey === $this->throwFor) {
            throw new LogicException('deleted-page refusal failed');
        }
        if ($acceptKey === $this->unservedFor) {
            return PageResendOutcome::Unserved;
        }

        return $this->inner->refuseDeletedResource($page, $acceptKey);
    }
}

/**
 * One country card. The page key is the only thing the four fixtures differ by.
 */
class PageResourceFixturePage extends AbstractPage
{
    public const string PAGE = PageResourceBrowser::OVERVIEW;

    /**
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params from the page subscription
     * @return PagePayload The page's own part, so a full re-send is distinguishable from a delta
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: ['own' => static::PAGE]);
    }
}

/**
 * The names tab of the same country.
 */
final class PageResourceNamesPage extends PageResourceFixturePage
{
    public const string PAGE = PageResourceBrowser::NAMES;
}

/**
 * The country list, which names no row.
 */
final class PageResourceListPage extends PageResourceFixturePage
{
    public const string PAGE = PageResourceBrowser::LIST;
}

/**
 * A window with no page resource.
 */
final class PageResourceWatchPage extends PageResourceFixturePage
{
    public const string PAGE = PageResourceBrowser::WATCH;
}

/**
 * @extends AbstractPageFactory<PageResourceAgent>
 */
final class PageResourcePageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageResourceBrowser::OVERVIEW => new PageResourceFixturePage($this->agent),
            PageResourceBrowser::NAMES => new PageResourceNamesPage($this->agent),
            PageResourceBrowser::LIST => new PageResourceListPage($this->agent),
            PageResourceBrowser::WATCH => new PageResourceWatchPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is one of the fixture pages
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [
            PageResourceBrowser::OVERVIEW,
            PageResourceBrowser::NAMES,
            PageResourceBrowser::LIST,
            PageResourceBrowser::WATCH,
        ], true);
    }
}

/**
 * Agent the fixture pages belong to.
 */
final class PageResourceAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'page-resource-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source for page helpers
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/**
 * Runtime notes the list and the cards draw beside the country.
 */
final class PageResourceRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[PageResourceBrowser::NOTES] = PageResourceNoteStates::init();
        $this->setRepresent(PageResourceBrowser::NOTES, PageResourceNoteCollection::class);
    }

    /**
     * @param PageResourceNoteState $row Row to add to the collection
     */
    public function addRow(PageResourceNoteState $row): void
    {
        $this->_stateCollections[PageResourceBrowser::NOTES]->add($row);
    }
}

final class PageResourceNoteStates extends RtStates
{
    public const string STATE_CLASS = PageResourceNoteState::class;
}

final class PageResourceNoteState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Row key
     * @param string $name Row label
     * @return self Row state
     */
    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Raw row
     * @return static Row state
     */
    public static function fromRow(array $row): static
    {
        return new static((string) $row['id'], (string) $row['name']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}

final class PageResourceNoteCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new PageResourceNoteItem($state);
    }
}

final class PageResourceNoteItem extends RtItem
{
    /**
     * @param string $name Field name
     * @return mixed Field value
     */
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
