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
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PagePayload;
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
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
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
 * An open page whose row changed the column its address is read from moves to the new address (HIL-2190).
 *
 * The flush reads the raw updates before grouping, after the live 404. Every window of that row
 * is moved, its author too; a window refused in the same flush is not, and a moved window gets
 * nothing else from this flush.
 */
final class BrowserContextPageMoveTest extends TestCase
{
    private string $logFile = '';

    private ?RtContext $previousRt = null;

    private PageMoveBrowser $context;

    private PageSignalRouter $router;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-page-move');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
        $runtime = new PageMoveRtContext();
        $runtime->configure();
        $runtime->addRow(PageMoveNoteState::create('1', 'Ada'));
        Hilos::$rt = $runtime;
        $this->router = new PageSignalRouter(new PageMovePageFactory(new PageMoveAgent()), new ActionRouteConfig());
        $this->context = new PageMoveBrowser();
        $this->context->bindPageResender(new PageMoveRouterPort($this->router));
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
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAnAddressUpdateMovesEveryWindowOfThatRowItsAuthorToo(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->openCard('ak-author', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->openCard('ak-other-row', PageMoveBrowser::CARD, PageMoveBrowser::OTHER_COUNTRY_ID, 'no');
        $this->openCard('ak-plain', PageMoveBrowser::PLAIN, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(
            PageMoveBrowser::COUNTRIES,
            PageMoveBrowser::COUNTRY_ID,
            ['code' => 'sw'],
            'ak-author',
            'req-rename',
        ));

        $this->assertSame([], $this->context->flushToSignalRouter());

        $signals = $this->signalsByAcceptKey();
        $this->assertSame(['ak-one', 'ak-author'], array_keys($signals));
        foreach (['ak-one', 'ak-author'] as $acceptKey) {
            $this->assertMove($signals[$acceptKey], PageMoveBrowser::CARD, 'se', 'sw');
            $this->assertNull(Hilos::$sr?->pageResource($acceptKey));
            $this->assertFalse(Hilos::$sr?->hasPageDeliveryFailure($acceptKey));
            $this->assertSame(PageMoveBrowser::CARD, Hilos::$sr?->pageSubscription($acceptKey)?->page);
        }
        $this->assertSame(PageMoveBrowser::OTHER_COUNTRY_ID, Hilos::$sr?->pageResource('ak-other-row')?->idString);
        $this->assertSame(PageMoveBrowser::COUNTRY_ID, Hilos::$sr?->pageResource('ak-plain')?->idString);
    }

    public function testAnotherRowAnUpdateWithoutTheColumnAnRtFactOrAnEmptyValueMoveNothing(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::OTHER_COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['name' => 'Sweden']));
        $this->context->record(SourceChange::dbUpdated('pageMoveCities', PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::rtUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));

        $this->context->flushToSignalRouter();

        $this->assertSame([], $this->signalsByAcceptKey());
        $this->assertSame(PageMoveBrowser::COUNTRY_ID, Hilos::$sr?->pageResource('ak-one')?->idString);

        foreach (['', null] as $emptyValue) {
            $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => $emptyValue]));

            $this->context->flushToSignalRouter();

            $this->assertSame([], $this->signalsByAcceptKey());
            $this->assertSame(PageMoveBrowser::COUNTRY_ID, Hilos::$sr?->pageResource('ak-one')?->idString);
        }
    }

    public function testAParamThatNamesNoAddressIsLeftOutAndTheWindowStillMoves(): void
    {
        $held = ['code' => 'se', 'id' => PageMoveBrowser::COUNTRY_ID, 'tab' => '', 'filters' => ['north'], 'page' => 2];
        $this->openPage('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, $held);
        $this->openPage('ak-listed', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, ['code' => ['se'], 'id' => PageMoveBrowser::COUNTRY_ID]);
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey();
        $this->assertSame(['ak-one', 'ak-listed'], array_keys($signals));
        $moved = $this->onlyMove($signals['ak-one']);
        $this->assertSame($held, $moved->params);
        $this->assertSame(['code' => 'sw', 'id' => PageMoveBrowser::COUNTRY_ID, 'page' => '2'], $moved->targetParams);
        $this->assertSame(['id' => PageMoveBrowser::COUNTRY_ID, 'code' => 'sw'], $this->onlyMove($signals['ak-listed'])->targetParams);
    }

    public function testTwoUpdatesInOneFlushMoveToTheLastValue(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sv']));

        $this->context->flushToSignalRouter();

        $this->assertMove($this->signalsByAcceptKey()['ak-one'] ?? [], PageMoveBrowser::CARD, 'se', 'sv');
    }

    public function testAValueBackToTheHeldOneMovesNothing(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'se']));

        $this->context->flushToSignalRouter();

        $this->assertSame([], $this->signalsByAcceptKey());
        $this->assertSame(PageMoveBrowser::COUNTRY_ID, Hilos::$sr?->pageResource('ak-one')?->idString);
    }

    public function testADeleteAndAnUpdateOfTheRowInOneFlushOnlyRefuse(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::dbDeleted(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey()['ak-one'] ?? [];
        $this->assertCount(1, $signals);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $error = $signals[0]->data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame(404, $error->httpCode);
        $this->assertSame(PageErrorMessages::RESOURCE_DELETED_MESSAGE, $error->transitionMessage);
    }

    public function testAMovedWindowGetsNoOtherFrameOfThisFlush(): void
    {
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->openCard('ak-plain', PageMoveBrowser::PLAIN, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));
        $this->context->record(SourceChange::rtUpdated(PageMoveBrowser::NOTES, '1', ['name' => 'Grace']));

        $this->context->flushToSignalRouter();

        $signals = $this->signalsByAcceptKey();
        $this->assertMove($signals['ak-one'] ?? [], PageMoveBrowser::CARD, 'se', 'sw');
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->namesOf($signals['ak-plain'] ?? []));
    }

    public function testAnUnservedMoveAndAThrowStayInsideOneSubscription(): void
    {
        $this->context->bindPageResender(new PageMoveRouterPort($this->router, throwFor: 'ak-throw', unservedFor: 'ak-unserved'));
        $this->openCard('ak-throw', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->openCard('ak-unserved', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->openCard('ak-one', PageMoveBrowser::CARD, PageMoveBrowser::COUNTRY_ID, 'se');
        $this->context->record(SourceChange::dbUpdated(PageMoveBrowser::COUNTRIES, PageMoveBrowser::COUNTRY_ID, ['code' => 'sw']));

        $contained = $this->context->flushToSignalRouter();

        $this->assertCount(2, $contained);
        $this->assertSame('renamed-page move failed', $contained[0]->failure->getMessage());
        $this->assertStringContainsString('ak-throw', $contained[0]->address);
        $this->assertStringContainsString('nobody serves here', $contained[1]->failure->getMessage());
        $this->assertStringContainsString('ak-unserved', $contained[1]->address);
        $signals = $this->signalsByAcceptKey();
        $this->assertDeliveryFailure($signals['ak-throw'] ?? []);
        $this->assertDeliveryFailure($signals['ak-unserved'] ?? []);
        $this->assertMove($signals['ak-one'] ?? [], PageMoveBrowser::CARD, 'se', 'sw');
    }

    /**
     * @param string $acceptKey Connection to subscribe
     * @param string $page Page key
     * @param string $countryId Primary id the page is about
     * @param string $code Address the window was opened at
     */
    private function openCard(string $acceptKey, string $page, string $countryId, string $code): void
    {
        $this->openPage($acceptKey, $page, $countryId, ['code' => $code, 'id' => $countryId]);
    }

    /**
     * @param string $acceptKey Connection to subscribe
     * @param string $page Page key
     * @param string $countryId Primary id the page is about
     * @param array<string, mixed> $params Params the subscription holds
     */
    private function openPage(string $acceptKey, string $page, string $countryId, array $params): void
    {
        Hilos::$sr?->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params));
        $resource = PageResource::db(PageMoveBrowser::COUNTRIES, $countryId);
        Hilos::$sr?->bindPageResource(
            $acceptKey,
            $page === PageMoveBrowser::CARD ? $resource->addressedBy('code', 'code') : $resource,
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
     * @param string $page Page the move must name
     * @param string $from Address param the window was opened at
     * @param string $to Address param the window moves to
     */
    private function assertMove(array $signals, string $page, string $from, string $to): void
    {
        $moved = $this->onlyMove($signals);
        $this->assertSame($page, $moved->page);
        $this->assertSame(['code' => $from, 'id' => PageMoveBrowser::COUNTRY_ID], $moved->params);
        $this->assertSame($page, $moved->targetPage);
        $this->assertSame(['code' => $to, 'id' => PageMoveBrowser::COUNTRY_ID], $moved->targetParams);
    }

    /**
     * Asserts one connection was sent one page_moved frame and nothing else, and returns it.
     *
     * @param list<SignalDTO> $signals Signals one connection was sent
     * @return PageMovedSignalData The move the connection was sent
     */
    private function onlyMove(array $signals): PageMovedSignalData
    {
        $this->assertSame([SignalConstants::PAGE_MOVED], $this->namesOf($signals));
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $moved = $signals[0]->data->data;
        $this->assertInstanceOf(PageMovedSignalData::class, $moved);

        return $moved;
    }

    /**
     * @param list<SignalDTO> $signals Signals one connection was sent
     */
    private function assertDeliveryFailure(array $signals): void
    {
        $this->assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->namesOf($signals));
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $error = $signals[0]->data->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame(PageMoveBrowser::CARD, $error->page);
        $this->assertSame(500, $error->httpCode);
        $this->assertSame('internal_error', $error->errorCode);
    }
}

/**
 * Browser context whose country card reads its address from the code column, and a card that does not.
 */
final class PageMoveBrowser extends BrowserContext
{
    public const string COUNTRIES = 'pageMoveCountries';

    public const string NOTES = 'pageMoveNotes';

    public const string CARD = 'page_move_country';

    public const string PLAIN = 'page_move_country_plain';

    public const string NOTE_TABLE = 'pageMoveNotesTable';

    public const string COUNTRY_ID = '7';

    public const string OTHER_COUNTRY_ID = '9';

    private const string SIGNAL = 'page_move_signal';

    /**
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity of one signed-in person
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(4);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::CARD && $page !== self::PLAIN) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings The notes list on both cards
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([self::NOTE_TABLE => []]);
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig The notes list, or null for anything else
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
 * Hands the browser context the page router's own port, with two connections scripted to miss a move.
 */
final class PageMoveRouterPort implements PageResender
{
    /**
     * @param PageSignalRouter $router Router that forms the frames
     * @param string $throwFor Accept key whose move throws
     * @param string $unservedFor Accept key whose move is unserved
     */
    public function __construct(
        private readonly PageSignalRouter $router,
        private readonly string $throwFor = '',
        private readonly string $unservedFor = '',
    ) {
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription
     * @param bool $reportRefusal Whether this deletion attempt may report its current domain refusal
     * @return PageResendOutcome What the router answered
     * @throws InvalidArgumentException When a frame of the answer cannot be named
     */
    public function resendPage(string $page, string $acceptKey, array $params, bool $reportRefusal = false): PageResendOutcome
    {
        return $this->router->resendPage($page, $acceptKey, $params, $reportRefusal);
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

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the move is addressed to
     * @param array<string, string> $targetParams Every route param of the new address
     * @return PageResendOutcome What the router answered, or Unserved for the scripted connection
     * @throws LogicException When the scripted connection is the one whose move fails
     * @throws InvalidArgumentException When the page-moved signal cannot be named
     */
    public function moveRenamedResource(string $page, string $acceptKey, array $targetParams): PageResendOutcome
    {
        if ($acceptKey === $this->throwFor) {
            throw new LogicException('renamed-page move failed');
        }
        if ($acceptKey === $this->unservedFor) {
            return PageResendOutcome::Unserved;
        }

        return $this->router->moveRenamedResource($page, $acceptKey, $targetParams);
    }
}

/**
 * A country card addressed by its code.
 */
class PageMoveCardPage extends AbstractPage
{
    public const string PAGE = PageMoveBrowser::CARD;

    /**
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route parameters identifying the row
     * @return PageResource The country, addressed by its code
     * @throws PageInternalErrorException When the id names no row
     */
    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): PageResource
    {
        return PageResource::db(PageMoveBrowser::COUNTRIES, $params->getString('id') ?? PageMoveBrowser::COUNTRY_ID)
            ->addressedBy('code', 'code');
    }

    /**
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused)
     * @param PageRouteParams $params Route params from the page subscription (unused)
     * @return PagePayload The page's own part
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(data: ['own' => static::PAGE]);
    }
}

/**
 * The same country card, with no address read from the row.
 */
final class PageMovePlainPage extends PageMoveCardPage
{
    public const string PAGE = PageMoveBrowser::PLAIN;

    /**
     * @param string $acceptKey Subscribing connection (unused)
     * @param PageRouteParams $params Route parameters identifying the row
     * @return PageResource The country, with no address declared
     * @throws PageInternalErrorException When the id names no row
     */
    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): PageResource
    {
        return PageResource::db(PageMoveBrowser::COUNTRIES, $params->getString('id') ?? PageMoveBrowser::COUNTRY_ID);
    }
}

/**
 * @extends AbstractPageFactory<PageMoveAgent>
 */
final class PageMovePageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageMoveBrowser::CARD => new PageMoveCardPage($this->agent),
            PageMoveBrowser::PLAIN => new PageMovePlainPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether this is one of the fixture pages
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PageMoveBrowser::CARD || $pageName === PageMoveBrowser::PLAIN;
    }
}

/**
 * Agent the fixture pages belong to.
 */
final class PageMoveAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'page-move-test-agent';
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
 * Runtime notes both cards draw beside the country.
 */
final class PageMoveRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[PageMoveBrowser::NOTES] = PageMoveNoteStates::init();
        $this->setRepresent(PageMoveBrowser::NOTES, PageMoveNoteCollection::class);
    }

    /**
     * @param PageMoveNoteState $row Row to add to the collection
     */
    public function addRow(PageMoveNoteState $row): void
    {
        $this->_stateCollections[PageMoveBrowser::NOTES]->add($row);
    }
}

final class PageMoveNoteStates extends RtStates
{
    public const string STATE_CLASS = PageMoveNoteState::class;
}

final class PageMoveNoteState extends RtState
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

final class PageMoveNoteCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new PageMoveNoteItem($state);
    }
}

final class PageMoveNoteItem extends RtItem
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
