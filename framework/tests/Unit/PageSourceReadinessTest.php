<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * A page is answered out of the state this process holds, or it is not answered yet (HIL-717).
 *
 * The worker takes up what the page reads and waits for it before the frame is judged, so a
 * subscription that still finds a collection missing is early rather than wrong - and the
 * difference has to survive as far as the client, which is why the refusal is the transient 503
 * the freeze already uses and not the internal error a refused read would otherwise become.
 *
 * The cases below are about which collections count and when they stop counting: only the RT
 * ones (a DB row is read out of the shared database and needs no copy here), all of them rather
 * than any of them, and only once the state has actually landed - saying what you want is not
 * the same as holding it.
 */
final class PageSourceReadinessTest extends TestCase
{
    /** @var string RT collection the test page draws its rows from */
    private const string COLLECTION = 'unitPageSourceRows';

    /** @var string Second RT collection, for the case about a page reading several */
    private const string OTHER_COLLECTION = 'unitPageSourceOther';

    /** @var string Consumer standing in for the subscribing connection */
    private const string ACCEPT_KEY = 'ak-page-source';

    protected function setUp(): void
    {
        // The refusal only exists where the copy is delivered, so the cases have to stand where
        // a worker stands; a process holding its own state answers every read and would prove
        // nothing here.
        SourceInterestRegistry::readsWhatIsDelivered();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAPageIsRefusedWhileNobodyHereReadsWhatItDrawsFrom(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        try {
            $context->assertSubscriptionAccess(
                PageSourceReadinessTestBrowserContext::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([]),
            );
            $this->fail('Expected a page with no state behind it to be refused.');
        } catch (PageServiceUnavailableException $e) {
            $this->assertSame(503, $e->httpCode);
            $this->assertSame('service_unavailable', $e->errorCode);
            // The collection name is engine detail and stays in the log; the wire carries a
            // domain sentence, and naming the missing collection on it would be the leak.
            $this->assertStringNotContainsString(self::COLLECTION, $e->getMessage());
        }
    }

    public function testAPageIsRefusedWhileItsStateIsStillOnItsWay(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        $this->expectException(PageServiceUnavailableException::class);
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );
    }

    public function testTheSubscriptionIsJudgedOnceTheStateHasLanded(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        // Passing the judge sends nothing, whichever way it goes.
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testEveryCollectionThePageReadsHasToBeHereAndNotJustOne(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::page(self::ACCEPT_KEY),
        );
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION, self::OTHER_COLLECTION]);

        $this->expectException(PageServiceUnavailableException::class);
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );
    }

    public function testAPageThatReadsNothingWaitsForNothing(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([]);

        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testADbSourceIsNotWaitedFor(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([], [self::COLLECTION]);

        // Nothing was taken up and nothing is ready, yet the page is answered: the rows are in
        // the shared database, and this process owes them no copy.
        $context->assertSubscriptionAccess(
            PageSourceReadinessTestBrowserContext::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheCollectionsOfAPageAreNamedFromTopologyAlone(): void
    {
        $context = new PageSourceReadinessTestBrowserContext(
            [self::COLLECTION, self::COLLECTION, self::OTHER_COLLECTION],
            [self::COLLECTION],
        );

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            $context->rtSourceKeysOfPage(PageSourceReadinessTestBrowserContext::PAGE),
        );
    }

    public function testAnUnboundPageNamesNoCollections(): void
    {
        $context = new PageSourceReadinessTestBrowserContext([self::COLLECTION]);

        $this->assertSame([], $context->rtSourceKeysOfPage('some_other_page'));
    }
}

final class PageSourceReadinessTestBrowserContext extends BrowserContext
{
    public const string PAGE = 'page_source_readiness_page';
    public const string SIGNAL = 'page_source_readiness_signal';

    /** @var string Browser key the test page binds, and the only one this fixture knows */
    private const string BROWSER_KEY = 'pageSourceReadinessList';

    /**
     * @param list<string> $rtCollectionKeys RT collections the bound source projects rows from
     * @param list<string> $dbCollectionKeys DB collections it projects rows from
     */
    public function __construct(
        private readonly array $rtCollectionKeys,
        private readonly array $dbCollectionKeys = [],
    ) {
        parent::__construct();
    }

    /**
     * Resolves the guard-less test page config: readiness is judged ahead of any guard.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guard-less page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * Binds the test page to the one source this fixture declares.
     *
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
     * Projects one row per injected collection key, standing in for a source's BROWSER constant.
     *
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Row projections of the bound source, or null when unknown
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::BROWSER_KEY) {
            return null;
        }

        $items = [];
        foreach ($this->rtCollectionKeys as $collectionKey) {
            $items[] = $this->rowConfig(BrowserSourceType::RT, $collectionKey);
        }
        foreach ($this->dbCollectionKeys as $collectionKey) {
            $items[] = $this->rowConfig(BrowserSourceType::DB, $collectionKey);
        }

        return BrowserSourceConfig::fromArray([BrowserListConfigKey::ITEMS => $items]);
    }

    /**
     * @param string $type Source type, RT or DB
     * @param string $collectionKey Collection the row draws from
     * @return array<string, mixed> One row projection config
     */
    private function rowConfig(string $type, string $collectionKey): array
    {
        return [
            BrowserFieldKey::SOURCE => [
                BrowserSourceKey::TYPE => $type,
                BrowserSourceKey::KEY => $collectionKey,
            ],
        ];
    }
}
