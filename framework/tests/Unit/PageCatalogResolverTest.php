<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Demo\BinanceBtcTracker\Database\Pages\PageCatalog as BinancePageCatalog;
use Demo\Chat\Database\Pages\PageCatalog as ChatPageCatalog;
use Demo\Tasks\Database\Pages\PageCatalog as TasksPageCatalog;
use Demo\EcommerceShop\Database\Pages\PageCatalog as EcommercePageCatalog;
use Demo\Polls\Database\Pages\PageCatalog as PollsPageCatalog;
use Demo\OnlineTesting\Database\Pages\PageCatalog as OnlineTestingPageCatalog;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use Hilos\Constants\HilosAgentType;
use Hilos\Cluster\ClusterContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use LogicException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../demo/chat/backend/Constants/PageConstants.php';
require_once __DIR__ . '/../../../demo/chat/backend/Database/Pages/PageCatalog.php';
require_once __DIR__ . '/../../../demo/binance-btc-tracker/backend/Database/Pages/PageCatalog.php';
require_once __DIR__ . '/../../../demo/tasks/backend/Database/Pages/PageCatalog.php';
require_once __DIR__ . '/../../../demo/ecommerce-shop/backend/Database/Pages/PageCatalog.php';
require_once __DIR__ . '/../../../demo/polls/backend/Database/Pages/PageCatalog.php';
require_once __DIR__ . '/../../../demo/online-testing/backend/Database/Pages/PageCatalog.php';

/**
 * Unit tests for the page catalog reader: the merge, a page's identity, its breadcrumb, its children.
 *
 * The project half is bound the way a running process binds it - a facade fixture naming its own
 * provider, captured by initBrowser() - because the merge rule is only interesting when there is
 * something to merge, and because a project entry under a framework key is the supported way to
 * rename a section rather than a collision.
 */
final class PageCatalogResolverTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new PageHeadTestSignalRouter();
    }

    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    public function testLegalOptInHasFourTabsAndKeepsDocumentAndRevisionAsSeparateOwners(): void
    {
        ChatLegalPageHeadTestHilos::initBrowser();
        foreach ([HilosPageConstants::HILOS_LEGAL, HilosPageConstants::HILOS_LEGAL_DOCUMENTS,
            HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, HilosPageConstants::HILOS_LEGAL_SETTINGS] as $page) {
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame([
                'page' => HilosPageConstants::HILOS_LEGAL,
                'label' => 'Legal',
                'lead' => 'What the project declares, and who accepted it.',
                'icon' => 'bi-file-earmark-check',
            ], $head->pageHeadOwner);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_LEGAL, 'label' => 'Legal'],
            ], $head->pageBreadcrumb);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_LEGAL, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_LEGAL_DOCUMENTS, 'label' => 'Documents'],
                ['page' => HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, 'label' => 'Acceptances'],
                ['page' => HilosPageConstants::HILOS_LEGAL_SETTINGS, 'label' => 'Settings'],
            ], $head->pageTabs);
        }
        foreach ([HilosPageConstants::HILOS_LEGAL_DOCUMENT, HilosPageConstants::HILOS_LEGAL_REVISION] as $page) {
            self::assertSame($page, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, new PageRouteParams([])));
        }

        Hilos::$sr = $this->createStub(SignalRouter::class);
        Hilos::$sr->method('servesPage')->willReturnCallback(static fn (string $page): bool => $page !== HilosPageConstants::HILOS_LEGAL_DOCUMENTS);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_LEGAL, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, 'label' => 'Acceptances'],
            ['page' => HilosPageConstants::HILOS_LEGAL_SETTINGS, 'label' => 'Settings'],
        ], PageCatalogResolver::head(HilosPageConstants::HILOS_LEGAL, new PageRouteParams([]))->pageTabs);
    }

    public function testLegalFrameworkAndExistingProvidersKeepTheirLegacyHead(): void
    {
        foreach ([LegacyLegalPageHeadTestHilos::class, TasksLegalPageHeadTestHilos::class,
            PollsLegalPageHeadTestHilos::class] as $facade) {
            $facade::initBrowser();
            foreach ([HilosPageConstants::HILOS_LEGAL, HilosPageConstants::HILOS_LEGAL_DOCUMENTS,
                HilosPageConstants::HILOS_LEGAL_DOCUMENT, HilosPageConstants::HILOS_LEGAL_REVISION,
                HilosPageConstants::HILOS_LEGAL_ACCEPTANCES, HilosPageConstants::HILOS_LEGAL_SETTINGS] as $page) {
                self::assertNull(PageCatalogResolver::head($page, new PageRouteParams([])), $facade . ': ' . $page);
            }
        }
    }

    public function testChangeLogTabsFollowCatalogOrderAndStopAtTheTrackedTableEntity(): void
    {
        ChangeLogHeadHilos::initBrowser();
        foreach ([HilosPageConstants::HILOS_CHANGE_LOG, HilosPageConstants::HILOS_CHANGE_LOG_FEED,
            HilosPageConstants::HILOS_CHANGE_LOG_TABLES, HilosPageConstants::HILOS_CHANGE_LOG_NOISY] as $page) {
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_FEED, 'label' => 'Feed'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLES, 'label' => 'Tracked tables'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_NOISY, 'label' => 'Noisy columns'],
            ], $head->pageTabs);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Change Log'],
            ], $head->pageBreadcrumb);
        }
        $entity = PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, new PageRouteParams([]));
        self::assertNull($entity);
        ChangeLogOverviewOnlyHeadHilos::initBrowser();
        self::assertSame([['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Overview']],
            PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG, new PageRouteParams([]))->pageTabs);
    }

    public function testSecurityHeadKeepsItsOrderedTabsAndOAuthProviderBreadcrumb(): void
    {
        SecurityPageHeadTestHilos::initBrowser();
        $securityPages = [
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_SECURITY_2FA, 'label' => 'Two-factor'],
            ['page' => HilosPageConstants::HILOS_SECURITY_STEP_UP, 'label' => 'Operations that ask for confirmation'],
            ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
            ['page' => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS, 'label' => 'Sign-in methods'],
            ['page' => HilosPageConstants::HILOS_SECURITY_IMPERSONATION, 'label' => 'Impersonation'],
        ];
        $securityBreadcrumb = [
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
        ];

        foreach (array_column($securityPages, 'page') as $page) {
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame($securityPages, $head->pageTabs);
            self::assertSame($securityBreadcrumb, $head->pageBreadcrumb);
        }

        $provider = HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER;
        self::assertSame($provider, PageCatalogResolver::rowOwner($provider));
        self::assertSame(
            HilosPageConstants::HILOS_SECURITY_OAUTH,
            HilosPageCatalog::CATALOG[$provider][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
        $providerHead = PageCatalogResolver::head($provider, new PageRouteParams(['providerId' => 'oauth:github']));
        self::assertNotNull($providerHead);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
            ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
            ['page' => $provider, 'label' => 'OAuth provider'],
        ], $providerHead->pageBreadcrumb);
        self::assertSame([['page' => $provider, 'label' => 'Overview']], $providerHead->pageTabs);
    }

    public function testSecurityHeadFiltersAnUnservedSectionTab(): void
    {
        SecurityPageHeadTestHilos::initBrowser();
        Hilos::$sr = $this->createStub(SignalRouter::class);
        Hilos::$sr->method('servesPage')->willReturnCallback(
            static fn (string $page): bool => $page !== HilosPageConstants::HILOS_SECURITY_STEP_UP,
        );

        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_SECURITY, new PageRouteParams([]));

        self::assertNotNull($head);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_SECURITY_2FA, 'label' => 'Two-factor'],
            ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
            ['page' => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS, 'label' => 'Sign-in methods'],
            ['page' => HilosPageConstants::HILOS_SECURITY_IMPERSONATION, 'label' => 'Impersonation'],
        ], $head->pageTabs);
    }

    /** Chat adopts the complete Security section with its six pages. */
    public function testChatAdoptsTheSecuritySectionWithItsSixPages(): void
    {
        $owner = HilosPageConstants::HILOS_SECURITY;
        self::assertSame(
            array_replace(HilosPageCatalog::CATALOG[$owner], [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true]),
            ChatPageCatalog::pages()[$owner],
        );
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, HilosPageCatalog::CATALOG[$owner]);
        foreach ([BinancePageCatalog::class, TasksPageCatalog::class, EcommercePageCatalog::class,
            PollsPageCatalog::class, OnlineTestingPageCatalog::class] as $provider) {
            self::assertSame(HilosPageCatalog::CATALOG[$owner], PageCatalogResolver::catalogOf($provider)[$owner]);
        }

        ChatSecurityPageHeadTestHilos::initBrowser();
        $tabs = [
            ['page' => $owner, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_SECURITY_2FA, 'label' => 'Two-factor'],
            ['page' => HilosPageConstants::HILOS_SECURITY_STEP_UP, 'label' => 'Operations that ask for confirmation'],
            ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
            ['page' => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS, 'label' => 'Sign-in methods'],
            ['page' => HilosPageConstants::HILOS_SECURITY_IMPERSONATION, 'label' => 'Impersonation'],
        ];
        foreach (array_column($tabs, 'page') as $page) {
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame($tabs, $head->pageTabs);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => $owner, 'label' => 'Security Center'],
            ], $head->pageBreadcrumb);
            self::assertSame($owner, $head->pageHeadOwner['page']);
        }
    }

    /** The actual Chat provider entry adopts its own head without adopting other projects. */
    public function testOnlyChatAdoptsTheOAuthProviderWithItsListAncestorAndSingletonOverview(): void
    {
        $owner = HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER;
        self::assertSame(
            array_replace(HilosPageCatalog::CATALOG[$owner], [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true]),
            ChatPageCatalog::pages()[$owner],
        );
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, HilosPageCatalog::CATALOG[$owner]);
        foreach ([BinancePageCatalog::class, TasksPageCatalog::class, EcommercePageCatalog::class,
            PollsPageCatalog::class, OnlineTestingPageCatalog::class] as $provider) {
            self::assertSame(HilosPageCatalog::CATALOG[$owner], PageCatalogResolver::catalogOf($provider)[$owner]);
        }

        ChatSecurityPageHeadTestHilos::initBrowser();
        $head = PageCatalogResolver::head($owner, new PageRouteParams(['providerId' => 'oauth:github']));
        self::assertNotNull($head);
        self::assertSame([
            'page' => $owner,
            'label' => 'OAuth provider',
            'lead' => 'A single OAuth provider configuration.',
        ], $head->pageHeadOwner);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
            ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
            ['page' => $owner, 'label' => 'OAuth provider'],
        ], $head->pageBreadcrumb);
        self::assertSame([['page' => $owner, 'label' => 'Overview']], $head->pageTabs);
    }

    /** The real Vue providers adopt the whole section, stopping at the nested table entity. */
    public function testVueProvidersAdoptTheChangeLogHeadOnAllFourOwnerPages(): void
    {
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG]);
        foreach ([TasksPageCatalog::class, EcommercePageCatalog::class, PollsPageCatalog::class,
            OnlineTestingPageCatalog::class] as $provider) {
            self::assertArrayNotHasKey(HilosPageConstants::HILOS_CHANGE_LOG, $provider::pages());
            self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD,
                PageCatalogResolver::catalogOf($provider)[HilosPageConstants::HILOS_CHANGE_LOG]);
            self::assertArrayNotHasKey(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, $provider::pages());
            self::assertSame(HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
                PageCatalogResolver::catalogOf($provider)[HilosPageConstants::HILOS_CHANGE_LOG_TABLE]);
        }
        ChangeLogDefaultHeadHilos::initBrowser();
        self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG, new PageRouteParams([])));

        foreach ([ChatChangeLogHeadHilos::class, BinanceChangeLogHeadHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_CHANGE_LOG]);
            foreach ([HilosPageConstants::HILOS_CHANGE_LOG, HilosPageConstants::HILOS_CHANGE_LOG_FEED,
                HilosPageConstants::HILOS_CHANGE_LOG_TABLES, HilosPageConstants::HILOS_CHANGE_LOG_NOISY] as $page) {
                self::assertSame(HilosPageConstants::HILOS_CHANGE_LOG, PageCatalogResolver::rowOwner($page));
                $head = PageCatalogResolver::head($page, new PageRouteParams([]));
                self::assertNotNull($head);
                self::assertSame([
                    'pageHeadOwner' => [
                        'page' => HilosPageConstants::HILOS_CHANGE_LOG,
                        'label' => 'Change Log',
                        'lead' => 'Who changed what in the tracked tables, and through which action.',
                        'icon' => 'bi-clock-history',
                    ],
                    'pageBreadcrumb' => [
                        ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                        ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Change Log'],
                    ],
                    'pageTabs' => [
                        ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Overview'],
                        ['page' => HilosPageConstants::HILOS_CHANGE_LOG_FEED, 'label' => 'Feed'],
                        ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLES, 'label' => 'Tracked tables'],
                        ['page' => HilosPageConstants::HILOS_CHANGE_LOG_NOISY, 'label' => 'Noisy columns'],
                    ],
                    'pageChildren' => [],
                ], $head->toArray());
                self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
            }
            self::assertSame(HilosPageConstants::HILOS_CHANGE_LOG_TABLE,
                PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_CHANGE_LOG_TABLE));
            self::assertNotNull(PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, new PageRouteParams([])));
        }
    }

    /** Missing pages and an inapplicable tab have no links in either Vue provider's section row. */
    public function testVueChangeLogHeadsFilterUnservedAndInapplicableTabs(): void
    {
        foreach ([ChatChangeLogPartialHeadHilos::class, BinanceChangeLogPartialHeadHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLES, 'label' => 'Tracked tables'],
            ], PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG,
                new PageRouteParams(['variant' => 'disabled']))->pageTabs);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_FEED, 'label' => 'Feed'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLES, 'label' => 'Tracked tables'],
            ], PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG,
                new PageRouteParams(['variant' => 'enabled']))->pageTabs);
        }
    }

    public function testTrackedTableRowHasFourTabsOrTwoWithoutAJournalLabelAndStopsTheSectionRow(): void
    {
        foreach ([TableEntityHeadHilos::class, ChatChangeLogHeadHilos::class, BinanceChangeLogHeadHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_CHANGE_LOG_TABLE]);
            foreach ([HilosPageConstants::HILOS_CHANGE_LOG_TABLE, HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS,
                HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS, HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY] as $page) {
                $head = PageCatalogResolver::head($page, new PageRouteParams(['tableId' => 'tracked']));
                self::assertSame(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, $head->pageHeadOwner['page']);
                self::assertSame([
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS, 'label' => 'Columns'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS, 'label' => 'Triggers'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY, 'label' => 'History'],
                ], $head->pageTabs);
                self::assertSame([
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG, 'label' => 'Change Log'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLES, 'label' => 'Tracked tables'],
                    ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE, 'label' => 'Tracked table'],
                ], $head->pageBreadcrumb);
            }
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS, 'label' => 'Triggers'],
            ], PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG_TABLE,
                new PageRouteParams(['tableId' => 'untracked']))->pageTabs);
        }
        foreach ([ChangeLogDefaultHeadHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_CHANGE_LOG_TABLE, new PageRouteParams(['tableId' => 'tracked'])));
            self::assertSame([
                HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS,
                HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS,
                HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY,
            ], array_column(PageCatalogResolver::children(HilosPageConstants::HILOS_CHANGE_LOG_TABLE), 'page'));
        }
    }

    public function testStatusSharesItsCatalogHeadAndOmitsAnUnservedChild(): void
    {
        StatusPageCatalogHilos::initBrowser();
        foreach ([HilosPageConstants::HILOS_STATUS, HilosPageConstants::HILOS_STATUS_COMPONENTS,
            HilosPageConstants::HILOS_STATUS_SUBSCRIBERS] as $page) {
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertSame([
                'page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Status',
                'lead' => 'A public status page: service health, incidents, and planned work.', 'icon' => 'bi-activity',
            ], $head->pageHeadOwner);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Status'],
            ], $head->pageBreadcrumb);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_STATUS_COMPONENTS, 'label' => 'Components'],
                ['page' => HilosPageConstants::HILOS_STATUS_SUBSCRIBERS, 'label' => 'Subscribers'],
            ], $head->pageTabs);
        }
        StatusPageCatalogOverviewOnlyHilos::initBrowser();
        self::assertSame([['page' => HilosPageConstants::HILOS_STATUS, 'label' => 'Overview']],
            PageCatalogResolver::head(HilosPageConstants::HILOS_STATUS, new PageRouteParams([]))->pageTabs);
    }

    public function testProjectEntriesJoinTheFrameworkOnes(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        $catalog = PageCatalogResolver::catalog();

        self::assertArrayHasKey(HilosPageConstants::HILOS_LOGS_KEYS, $catalog);
        self::assertArrayHasKey(PageCatalogResolverTestCatalog::MODERATION, $catalog);
        self::assertSame(
            [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Queue',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Reports waiting for a moderator.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => PageCatalogResolverTestCatalog::MODERATION,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Short queue',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            $catalog[PageCatalogResolverTestCatalog::MODERATION_QUEUE],
        );
    }

    public function testAProjectEntryUnderAFrameworkKeyRenamesThatSection(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Languages of the product',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Product copy in every language it ships in.',
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-translate',
            ],
            PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N),
        );
    }

    /**
     * Identity is the entry without its place in the tree: the parent belongs to the breadcrumb,
     * and a page that sends it as its own would put its parent's key on the wire twice.
     */
    public function testIdentityCarriesNoParent(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By key',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by log key.',
            ],
            PageCatalogResolver::identity(HilosPageConstants::HILOS_LOGS_KEYS),
        );
    }

    public function testTheRootPageGetsAChainOfOneLink(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                ],
            ],
            PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_DASHBOARD),
        );
    }

    public function testAThirdLevelPageGetsTheWholeChainRootFirst(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                ],
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'Logs',
                ],
                [
                    PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                    PageCatalogConstants::WIRE_CRUMB_LABEL => 'By key',
                ],
            ],
            PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_LOGS_KEYS),
        );
    }

    /**
     * A project page reaches the framework root through its own section, which is the whole point
     * of letting a project name a framework key as its parent.
     */
    public function testAProjectPageWalksUpIntoTheFrameworkTree(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogResolverTestCatalog::MODERATION,
                PageCatalogResolverTestCatalog::MODERATION_QUEUE,
            ],
            array_column(
                PageCatalogResolver::breadcrumb(PageCatalogResolverTestCatalog::MODERATION_QUEUE),
                PageCatalogConstants::WIRE_CRUMB_PAGE,
            ),
        );
    }

    /**
     * A routed page the catalog does not carry is not an error: the guardian mirror gap answers
     * without identity until its own catalog leaf lands.
     */
    public function testAPageWithoutAnEntryHasNoIdentityAndNoBreadcrumb(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertNull(PageCatalogResolver::identity(HilosPageConstants::HILOS_GUARDIAN));
        self::assertSame([], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_GUARDIAN));
    }

    public function testASectionListsItsChildrenInCatalogOrder(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                HilosPageConstants::HILOS_LOGS_KEYS,
                HilosPageConstants::HILOS_LOGS_WORKERS,
                HilosPageConstants::HILOS_LOGS_ROTATIONS,
                HilosPageConstants::HILOS_LOGS_SETTINGS,
                HilosPageConstants::HILOS_LOGS_VIEW,
            ],
            array_column(
                PageCatalogResolver::children(HilosPageConstants::HILOS_LOGS),
                PageCatalogConstants::WIRE_CHILD_PAGE,
            ),
        );
    }

    /**
     * A card carries the identity of the page it points at, the same shape a dashboard card
     * travels in - so a port renders both from one template.
     */
    public function testAChildCarriesItsOwnIdentityBesideItsKey(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By key',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by log key.',
            ],
            PageCatalogResolver::children(HilosPageConstants::HILOS_LOGS)[0],
        );
    }

    public function testALeafHasNoChildren(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_LOGS_KEYS));
    }

    /**
     * A page with no entry answers the same empty list as a leaf: having no children and being
     * outside the catalog look alike to the frontend, and neither is an error.
     */
    public function testAPageWithoutAnEntryHasNoChildren(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_GUARDIAN));
    }

    /**
     * A project page naming a framework key as its parent shows up among that page's children,
     * which is how a project's screen reaches the panel as a card rather than as a second-class
     * link somewhere else.
     */
    public function testAProjectPageIsAChildOfTheFrameworkPageItNames(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertContains(
            PageCatalogResolverTestCatalog::MODERATION,
            array_column(
                PageCatalogResolver::children(HilosPageConstants::HILOS_DASHBOARD),
                PageCatalogConstants::WIRE_CHILD_PAGE,
            ),
        );
    }

    public function testNodePagesShareTheirOwnHeadAndOrderedTabs(): void
    {
        DaemonPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['nodeId' => 'node /?#']);
        $expected = [
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_DAEMON_NODE,
                'label' => 'Node',
                'lead' => 'One node: processor, memory, environment summary, and maintenance.',
                'icon' => 'bi-hdd-network',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon'],
                ['page' => HilosPageConstants::HILOS_DAEMON_NODE, 'label' => 'Node'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_DAEMON_NODE, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_DAEMON_WORKERS, 'label' => 'Workers'],
                ['page' => HilosPageConstants::HILOS_DAEMON_AGENTS, 'label' => 'Agents'],
                ['page' => HilosPageConstants::HILOS_DAEMON_CRON, 'label' => 'Cron'],
                ['page' => HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, 'label' => 'WebSockets'],
                ['page' => HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, 'label' => 'HTTP'],
                ['page' => HilosPageConstants::HILOS_DAEMON_ENV, 'label' => 'Environment'],
                ['page' => HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS, 'label' => 'Env orphans'],
            ],
            'pageChildren' => [],
        ];
        foreach ([
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageConstants::HILOS_DAEMON_WORKERS,
            HilosPageConstants::HILOS_DAEMON_AGENTS,
            HilosPageConstants::HILOS_DAEMON_CRON,
            HilosPageConstants::HILOS_DAEMON_WEBSOCKETS,
            HilosPageConstants::HILOS_DAEMON_HTTP_SERVER,
            HilosPageConstants::HILOS_DAEMON_ENV,
            HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS,
        ] as $page) {
            self::assertSame($expected, PageCatalogResolver::head($page, $params)?->toArray(), $page);
            self::assertSame(HilosPageConstants::HILOS_DAEMON_NODE, PageCatalogResolver::rowOwner($page));
        }
        foreach ([HilosPageConstants::HILOS_DAEMON, HilosPageConstants::HILOS_DAEMON_PLACEMENT,
            HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH] as $page) {
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon',
                    'lead' => 'Nodes and their processes, with agent placement across them.', 'icon' => 'bi-cpu',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon'],
                ],
                'pageTabs' => [
                    ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_DAEMON_PLACEMENT, 'label' => 'Agent placement'],
                    ['page' => HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, 'label' => 'Env mismatch'],
                ],
                'pageChildren' => [],
            ], PageCatalogResolver::head($page, $params)?->toArray());
        }
        self::assertSame(HilosPageConstants::HILOS_DAEMON, PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH));
    }

    /** Providers that have not adopted the user keep the legacy breadcrumb through each child. */
    public function testUserEntityAndItsChildrenStayLegacyWithoutAProjectOptIn(): void
    {
        UserPageHeadTestHilos::initBrowser();
        foreach ([
            HilosPageConstants::HILOS_USER,
            HilosPageConstants::HILOS_USER_ROLES,
            HilosPageConstants::HILOS_USER_ACCESS,
            HilosPageConstants::HILOS_USER_SESSIONS,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_USER, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, new PageRouteParams(['userId' => '42'])));
        }
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Users'],
            ['page' => HilosPageConstants::HILOS_USER, 'label' => 'User'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_USER));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Users'],
            ['page' => HilosPageConstants::HILOS_USER, 'label' => 'User'],
            ['page' => HilosPageConstants::HILOS_USER_ROLES, 'label' => 'User roles'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_USER_ROLES));
    }

    /** Both real Vue providers preserve the framework row and omit unserved future tabs. */
    public function testVueProvidersAdoptTheUserHeadWithOnlyOverviewServed(): void
    {
        foreach ([ChatUserPageHeadTestHilos::class, BinanceUserPageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USER],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_USER]);
            self::assertSame(HilosPageConstants::HILOS_USER, PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_USER));
            $head = PageCatalogResolver::head(HilosPageConstants::HILOS_USER, new PageRouteParams(['userId' => '42']));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_USER,
                    'label' => 'User',
                    'lead' => 'A single user: profile, presence, and account actions.',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Users'],
                    ['page' => HilosPageConstants::HILOS_USER, 'label' => 'User'],
                ],
                'pageTabs' => [['page' => HilosPageConstants::HILOS_USER, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_USER));
            self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_USERS, new PageRouteParams([])));
        }
    }

    /** Both real Vue providers adopt all served Logs tabs without changing the framework default. */
    public function testVueProvidersAdoptTheSameLogsHeadOnAllSixPages(): void
    {
        foreach ([ChatLogsPageHeadTestHilos::class, BinanceLogsPageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LOGS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_LOGS]);
            foreach ([
                HilosPageConstants::HILOS_LOGS,
                HilosPageConstants::HILOS_LOGS_KEYS,
                HilosPageConstants::HILOS_LOGS_WORKERS,
                HilosPageConstants::HILOS_LOGS_ROTATIONS,
                HilosPageConstants::HILOS_LOGS_SETTINGS,
                HilosPageConstants::HILOS_LOGS_VIEW,
            ] as $page) {
                self::assertSame(HilosPageConstants::HILOS_LOGS, PageCatalogResolver::rowOwner($page));
                $head = PageCatalogResolver::head($page, new PageRouteParams([
                    'state' => 'due', 'nodeId' => 'node-2', 'source' => 'live',
                    'stream' => 'worker-0.log', 'anchor' => '1792836000123',
                ]));
                self::assertNotNull($head);
                self::assertSame([
                    'pageHeadOwner' => [
                        'page' => HilosPageConstants::HILOS_LOGS,
                        'label' => 'Logs',
                        'lead' => 'Rotation stats, log keys, workers, and the viewer.',
                        'icon' => 'bi-journal-text',
                    ],
                    'pageBreadcrumb' => [
                        ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                        ['page' => HilosPageConstants::HILOS_LOGS, 'label' => 'Logs'],
                    ],
                    'pageTabs' => [
                        ['page' => HilosPageConstants::HILOS_LOGS, 'label' => 'Overview'],
                        ['page' => HilosPageConstants::HILOS_LOGS_KEYS, 'label' => 'By key'],
                        ['page' => HilosPageConstants::HILOS_LOGS_WORKERS, 'label' => 'By worker'],
                        ['page' => HilosPageConstants::HILOS_LOGS_ROTATIONS, 'label' => 'Rotations'],
                        ['page' => HilosPageConstants::HILOS_LOGS_SETTINGS, 'label' => 'Settings'],
                        ['page' => HilosPageConstants::HILOS_LOGS_VIEW, 'label' => 'Viewer'],
                    ],
                    'pageChildren' => [],
                ], $head->toArray());
                self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
            }
        }
    }

    /** A project serving only Logs Overview gets no links to absent child pages. */
    public function testVueLogsHeadFiltersUnservedTabs(): void
    {
        foreach ([ChatLogsOverviewHeadTestHilos::class, BinanceLogsOverviewHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            $head = PageCatalogResolver::head(HilosPageConstants::HILOS_LOGS, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_LOGS,
                    'label' => 'Logs',
                    'lead' => 'Rotation stats, log keys, workers, and the viewer.',
                    'icon' => 'bi-journal-text',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_LOGS, 'label' => 'Logs'],
                ],
                'pageTabs' => [['page' => HilosPageConstants::HILOS_LOGS, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $head->toArray());
        }
    }

    /** Without project adoption the framework keeps the tab in the legacy breadcrumb. */
    public function testLogsStayLegacyInTheDefaultProvider(): void
    {
        LogsPageHeadTestHilos::initBrowser();
        foreach ([
            HilosPageConstants::HILOS_LOGS,
            HilosPageConstants::HILOS_LOGS_KEYS,
            HilosPageConstants::HILOS_LOGS_WORKERS,
            HilosPageConstants::HILOS_LOGS_ROTATIONS,
            HilosPageConstants::HILOS_LOGS_SETTINGS,
            HilosPageConstants::HILOS_LOGS_VIEW,
        ] as $page) {
            self::assertNull(PageCatalogResolver::head($page, new PageRouteParams([])));
        }
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_LOGS, 'label' => 'Logs'],
            ['page' => HilosPageConstants::HILOS_LOGS_KEYS, 'label' => 'By key'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_LOGS_KEYS));
    }

    /** Chat and Binance adopt Appearance; the framework facade and the other four providers stay legacy. */
    public function testVueProvidersAdoptTheAppearanceHeadWithOnlyOverviewServed(): void
    {
        AppearancePageHeadTestHilos::initBrowser();
        self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_APPEARANCE, new PageRouteParams([])));
        foreach ([
            TasksPageCatalog::class,
            EcommercePageCatalog::class,
            PollsPageCatalog::class,
            OnlineTestingPageCatalog::class,
        ] as $provider) {
            self::assertArrayNotHasKey(HilosPageConstants::HILOS_APPEARANCE, $provider::pages());
        }

        foreach ([ChatAppearancePageHeadTestHilos::class, BinanceAppearancePageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_APPEARANCE],
                [
                    PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Appearance',
                    PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
                ],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_APPEARANCE]);
            self::assertSame(
                HilosPageConstants::HILOS_APPEARANCE,
                PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_APPEARANCE),
            );
            $head = PageCatalogResolver::head(HilosPageConstants::HILOS_APPEARANCE, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_APPEARANCE,
                    'label' => 'Appearance',
                    'lead' => 'Theme switching and the default theme.',
                    'icon' => 'bi-palette',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_APPEARANCE, 'label' => 'Appearance'],
                ],
                'pageTabs' => [['page' => HilosPageConstants::HILOS_APPEARANCE, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_APPEARANCE));
            self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_APPEARANCE));
        }
    }

    /** Chat and Binance adopt Backups; the framework facade and the other four providers stay legacy. */
    public function testVueProvidersAdoptTheBackupsHeadWithOnlyOverviewServed(): void
    {
        BackupsPageHeadTestHilos::initBrowser();
        self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_BACKUP, new PageRouteParams([])));
        foreach ([TasksPageCatalog::class, EcommercePageCatalog::class, PollsPageCatalog::class, OnlineTestingPageCatalog::class] as $provider) {
            self::assertArrayNotHasKey(HilosPageConstants::HILOS_BACKUP, $provider::pages());
        }

        foreach ([ChatBackupsPageHeadTestHilos::class, BinanceBackupsPageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_BACKUP],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[HilosPageConstants::HILOS_BACKUP]);
            self::assertSame(HilosPageConstants::HILOS_BACKUP,
                PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_BACKUP));
            $head = PageCatalogResolver::head(HilosPageConstants::HILOS_BACKUP, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_BACKUP,
                    'label' => 'Backups',
                    'lead' => 'Database and file backup jobs and their history.',
                    'icon' => 'bi-safe2',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_BACKUP, 'label' => 'Backups'],
                ],
                'pageTabs' => [['page' => HilosPageConstants::HILOS_BACKUP, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_BACKUP));
            self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_BACKUP));
        }
    }

    /** Future children share the user owner and follow catalog order regardless of registration order. */
    public function testServedUserTabsShareTheSameHeadInBothVueProviders(): void
    {
        foreach ([ChatUserTabsPageHeadTestHilos::class, BinanceUserTabsPageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            foreach ([
                HilosPageConstants::HILOS_USER,
                HilosPageConstants::HILOS_USER_ROLES,
                HilosPageConstants::HILOS_USER_ACCESS,
                HilosPageConstants::HILOS_USER_SESSIONS,
            ] as $page) {
                self::assertSame(HilosPageConstants::HILOS_USER, PageCatalogResolver::rowOwner($page));
                $head = PageCatalogResolver::head($page, new PageRouteParams(['userId' => '42']));
                self::assertNotNull($head);
                self::assertSame([
                    'pageHeadOwner' => [
                        'page' => HilosPageConstants::HILOS_USER,
                        'label' => 'User',
                        'lead' => 'A single user: profile, presence, and account actions.',
                    ],
                    'pageBreadcrumb' => [
                        ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                        ['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Users'],
                        ['page' => HilosPageConstants::HILOS_USER, 'label' => 'User'],
                    ],
                    'pageTabs' => [
                        ['page' => HilosPageConstants::HILOS_USER, 'label' => 'Overview'],
                        ['page' => HilosPageConstants::HILOS_USER_ROLES, 'label' => 'Roles'],
                        ['page' => HilosPageConstants::HILOS_USER_ACCESS, 'label' => 'Access'],
                        ['page' => HilosPageConstants::HILOS_USER_SESSIONS, 'label' => 'Sessions'],
                    ],
                    'pageChildren' => [],
                ], $head->toArray());
                self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
            }
        }
    }

    public function testOverviewAndCircleShareTheExactMaintenanceHead(): void
    {
        MaintenancePageHeadTestHilos::initBrowser();

        self::assertSame([
            'label' => 'Circle',
            'lead' => 'Who will check the system after an operation.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE));
        self::assertSame([
            'label' => 'Words',
            'lead' => 'The words used to announce maintenance.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_MAINTENANCE_WORDS));
        $heads = [];
        foreach ([
            HilosPageConstants::HILOS_MAINTENANCE,
            HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            HilosPageConstants::HILOS_MAINTENANCE_WORDS,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_MAINTENANCE, PageCatalogResolver::rowOwner($page));
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Maintenance'],
            ], PageCatalogResolver::breadcrumb($page));
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertNotNull($head);
            $heads[] = $head;
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_MAINTENANCE,
                    'label' => 'Maintenance',
                    'lead' => 'Node freeze under a destructive operation and manual maintenance: '
                        . 'its state, the verifier circle, closing the system to visitors, and the way out of the window.',
                    'icon' => 'bi-tools',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Maintenance'],
                ],
                'pageTabs' => [
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE, 'label' => 'Circle'],
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE_WORDS, 'label' => 'Words'],
                ],
                'pageChildren' => [],
            ], $head->toArray());
        }
        self::assertNotSame($heads[0], $heads[1]);
        self::assertNotSame($heads[1], $heads[2]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
                'label' => 'Circle', 'lead' => 'Who will check the system after an operation.'],
            ['page' => HilosPageConstants::HILOS_MAINTENANCE_WORDS,
                'label' => 'Words', 'lead' => 'The words used to announce maintenance.'],
            ['page' => HilosPageConstants::HILOS_MAINTENANCE_NODES,
                'label' => 'Nodes', 'lead' => 'Maintenance reports from the cluster nodes.'],
        ], PageCatalogResolver::children(HilosPageConstants::HILOS_MAINTENANCE));
        self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE));
        self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_MAINTENANCE_WORDS));
    }

    public function testNodesIsAClusterOnlyTabOnEveryMaintenancePage(): void
    {
        $previousCluster = Hilos::$cluster;
        $previousEnv = Hilos::$env;
        $previousEnabled = getenv('CLUSTER_ENABLED');
        try {
            Hilos::$env = new EnvAccessor();
            Hilos::$cluster = new ClusterContext();
            MaintenanceNodesHeadTestHilos::initBrowser();
            foreach ([false, true] as $cluster) {
                putenv('CLUSTER_ENABLED=' . ($cluster ? 'true' : 'false'));
                $tabs = [
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE, 'label' => 'Circle'],
                    ['page' => HilosPageConstants::HILOS_MAINTENANCE_WORDS, 'label' => 'Words'],
                ];
                if ($cluster) {
                    $tabs[] = ['page' => HilosPageConstants::HILOS_MAINTENANCE_NODES, 'label' => 'Nodes'];
                }
                foreach ([HilosPageConstants::HILOS_MAINTENANCE, HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
                    HilosPageConstants::HILOS_MAINTENANCE_WORDS, HilosPageConstants::HILOS_MAINTENANCE_NODES] as $page) {
                    self::assertSame(HilosPageConstants::HILOS_MAINTENANCE, PageCatalogResolver::rowOwner($page));
                    $head = PageCatalogResolver::head($page, new PageRouteParams([]));
                    self::assertNotNull($head);
                    self::assertSame($tabs, $head->pageTabs);
                    self::assertSame([
                        ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                        ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Maintenance'],
                    ], $head->pageBreadcrumb);
                }
            }
        } finally {
            Hilos::$cluster = $previousCluster;
            Hilos::$env = $previousEnv;
            putenv($previousEnabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $previousEnabled);
        }
    }

    public function testAnUnregisteredCircleDoesNotBecomeAMaintenanceTab(): void
    {
        MaintenanceOverviewHeadTestHilos::initBrowser();
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_MAINTENANCE, new PageRouteParams([]));

        self::assertNotNull($head);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MAINTENANCE, 'label' => 'Overview'],
        ], $head->pageTabs);
        self::assertSame([], $head->toArray()['pageChildren']);
    }

    /** Settings uses the framework section head with only its Overview tab. */
    public function testSettingsAdoptsOnlyTheOverviewFromTheFrameworkCatalog(): void
    {
        SettingsPageHeadTestHilos::initBrowser();
        self::assertSame(HilosPageConstants::HILOS_SETTINGS, PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_SETTINGS));
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_SETTINGS, new PageRouteParams([]));

        self::assertNotNull($head);
        self::assertSame([
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_SETTINGS,
                'label' => 'Settings',
                'lead' => 'Application setting catalog: catalog defaults, overrides, and references.',
                'icon' => 'bi-sliders',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_SETTINGS, 'label' => 'Settings'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_SETTINGS, 'label' => 'Overview'],
            ],
            'pageChildren' => [],
        ], $head->toArray());
        self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_SETTINGS));
    }

    public function testAnalyticsSharesItsListHeadsAndKeepsBothEntityHeadsLegacy(): void
    {
        AnalyticsPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams([]);
        self::assertSame([
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_ANALYTICS,
                'label' => 'Analytics',
                'lead' => 'What a browser session did, in order, and which sessions a person signed into.',
                'icon' => 'bi-bar-chart',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, 'label' => 'Browser sessions'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
            ],
            'pageChildren' => [],
        ], PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS, $params)?->toArray());
        self::assertSame(
            PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS, $params)?->toArray(),
            PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS_PEOPLE, $params)?->toArray(),
        );
        self::assertSame(
            PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS, $params)?->toArray(),
            PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, $params)?->toArray(),
        );
        foreach ([
            HilosPageConstants::HILOS_ANALYTICS_PERSON => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Person'],
            ],
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, 'label' => 'Browser sessions'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION, 'label' => 'Browser session'],
            ],
        ] as $page => $breadcrumb) {
            self::assertSame($page, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, $params));
            self::assertSame($breadcrumb, PageCatalogResolver::breadcrumb($page));
        }
    }

    /** A project serving only Overview keeps the list tabs out through the normal eligibility check. */
    public function testUnservedAnalyticsListsDoNotBecomeTabsOrCards(): void
    {
        AnalyticsOverviewOnlyHeadTestHilos::initBrowser();
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS, new PageRouteParams([]));
        self::assertNotNull($head);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Overview'],
        ], $head->pageTabs);
        self::assertSame([], $head->toArray()['pageChildren']);
    }

    /** Vue opts both Analytics entities into their own Overview-only heads. */
    public function testVueProvidersAdoptBothAnalyticsEntityHeadsWithOnlyOverview(): void
    {
        foreach ([ChatAnalyticsPageHeadTestHilos::class, BinanceAnalyticsPageHeadTestHilos::class] as $hilos) {
            $hilos::initBrowser();
            $page = HilosPageConstants::HILOS_ANALYTICS_PERSON;
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[$page],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[$page]);
            self::assertSame($page, PageCatalogResolver::rowOwner($page));
            $head = PageCatalogResolver::head($page, new PageRouteParams(['userId' => '42']));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_ANALYTICS_PERSON,
                    'label' => 'Person',
                    'lead' => 'Browser sessions where this person signed in last. '
                        . 'Other people may have performed actions in the same browser.',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                    ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
                    ['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Person'],
                ],
                'pageTabs' => [['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
            $sessionPage = HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION;
            self::assertSame(array_replace(
                HilosPageCatalog::CATALOG[$sessionPage],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ), PageCatalogResolver::catalog()[$sessionPage]);
            self::assertSame($sessionPage, PageCatalogResolver::rowOwner($sessionPage));
            $sessionHead = PageCatalogResolver::head(
                $sessionPage,
                new PageRouteParams(['browserSessionId' => '58']),
            );
            self::assertNotNull($sessionHead);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => $sessionPage,
                    'label' => 'Browser session',
                    'lead' => 'Actions recorded in one browser session, in source-time order.',
                    'icon' => 'bi-window',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                    ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, 'label' => 'Browser sessions'],
                    ['page' => $sessionPage, 'label' => 'Browser session'],
                ],
                'pageTabs' => [['page' => $sessionPage, 'label' => 'Overview']],
                'pageChildren' => [],
            ], $sessionHead->toArray());
            self::assertSame($sessionHead->pageBreadcrumb, PageCatalogResolver::breadcrumb($sessionPage));
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, 'label' => 'Browser sessions'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION, 'label' => 'Browser session'],
            ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION));
        }
    }

    public function testI18nRootAndListsShareTheHeadAndKeepServedFutureTabs(): void
    {
        I18nPageHeadTestHilos::initBrowser();
        foreach ([
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_I18N_LANGUAGES,
            HilosPageConstants::HILOS_I18N_COUNTRIES,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_I18N, PageCatalogResolver::rowOwner($page));
            $head = PageCatalogResolver::head($page, new PageRouteParams([]));
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_I18N,
                    'label' => 'Internationalization',
                    'lead' => 'Languages, countries, locales, and translation screens.',
                    'icon' => 'bi-translate',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
                ],
                'pageTabs' => [
                    ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_I18N_LANGUAGES, 'label' => 'Languages'],
                    ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
                    ['page' => HilosPageConstants::HILOS_I18N_GROUPS, 'label' => 'Groups'],
                ],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
        }
        self::assertSame([
            'label' => 'Languages',
            'lead' => 'Supported interface languages and their locales.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_LANGUAGES));
        self::assertSame([
            'label' => 'Countries',
            'lead' => 'Country catalog used for locale and formatting.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_COUNTRIES));
    }

    public function testLanguagePagesShareTheirEntityHeadAndOrderedTabs(): void
    {
        I18nPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['languageId' => '42']);
        $expected = [
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_I18N_LANGUAGE,
                'label' => 'Language',
                'lead' => 'A single language: code, native name, and writing direction.',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGES, 'label' => 'Languages'],
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE, 'label' => 'Language'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES, 'label' => 'Names'],
                ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES, 'label' => 'Locales'],
            ],
            'pageChildren' => [],
        ];

        foreach ([
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_I18N_LANGUAGE, PageCatalogResolver::rowOwner($page));
            $head = PageCatalogResolver::head($page, $params);
            self::assertNotNull($head);
            self::assertSame($expected, $head->toArray(), $page);
            self::assertSame($head->pageBreadcrumb, PageCatalogResolver::breadcrumb($page));
        }
        self::assertSame([
            'label' => 'Language names',
            'lead' => 'How this language is named in each of the other languages.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES));
        self::assertSame([
            'label' => 'Language locales',
            'lead' => 'Formats of this language, per country.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES));
    }

    public function testUnservedLanguageTabIsOmittedFromTheSharedHead(): void
    {
        I18nPageHeadTestHilos::initBrowser();
        Hilos::$sr = $this->createStub(SignalRouter::class);
        Hilos::$sr->method('servesPage')->willReturnCallback(
            static fn (string $page): bool => $page !== HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
        );

        $head = PageCatalogResolver::head(
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            new PageRouteParams(['languageId' => '42']),
        );

        self::assertNotNull($head);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES, 'label' => 'Names'],
        ], $head->pageTabs);
    }

    /** Rules is intentionally unserved in this fixture. */
    public function testModerationKindOwnsItsSingletonOverviewOutsideTheSectionRow(): void
    {
        ModerationKindPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['kind' => 'project_kind']);
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_MODERATION_KIND, $params);
        self::assertNotNull($head);
        self::assertSame([
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_MODERATION_KIND,
                'label' => 'Kind',
                'lead' => 'One declared check and the rules in force.',
                'icon' => 'bi-shield-check',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Moderation'],
                ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Kind'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Overview'],
            ],
            'pageChildren' => [],
        ], $head->toArray());
        self::assertSame(
            HilosPageConstants::HILOS_MODERATION_KIND,
            PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_MODERATION_KIND),
        );
        $root = PageCatalogResolver::head(HilosPageConstants::HILOS_MODERATION, $params);
        self::assertNotNull($root);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Overview'],
        ], $root->pageTabs);
        self::assertSame([], $root->toArray()['pageChildren']);
    }

    public function testModerationKindAndRulesShareTheOwnerBreadcrumbAndTwoTabs(): void
    {
        ModerationKindRulesPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['kind' => 'project_kind']);
        foreach ([HilosPageConstants::HILOS_MODERATION_KIND, HilosPageConstants::HILOS_MODERATION_KIND_RULES] as $page) {
            $head = PageCatalogResolver::head($page, $params);
            self::assertNotNull($head);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_MODERATION_KIND,
                    'label' => 'Kind',
                    'lead' => 'One declared check and the rules in force.',
                    'icon' => 'bi-shield-check',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Moderation'],
                    ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Kind'],
                ],
                'pageTabs' => [
                    ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Overview'],
                    ['page' => HilosPageConstants::HILOS_MODERATION_KIND_RULES, 'label' => 'Rules'],
                ],
                'pageChildren' => [],
            ], $head->toArray());
            self::assertSame(HilosPageConstants::HILOS_MODERATION_KIND, PageCatalogResolver::rowOwner($page));
        }
        $root = PageCatalogResolver::head(HilosPageConstants::HILOS_MODERATION, $params);
        self::assertNotNull($root);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Overview'],
        ], $root->pageTabs);
        self::assertSame([], $root->toArray()['pageChildren']);
    }

    public function testCountryAndNamesShareTheExactHeadWithoutChangingTheirIdentity(): void
    {
        CountryPageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['countryId' => '42']);
        $country = PageCatalogResolver::head(HilosPageConstants::HILOS_I18N_COUNTRY, $params);
        $names = PageCatalogResolver::head(HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, $params);

        self::assertNotNull($country);
        self::assertNotNull($names);
        self::assertNotSame($country, $names);
        self::assertSame([
            'pageHeadOwner' => [
                'page' => HilosPageConstants::HILOS_I18N_COUNTRY,
                'label' => 'Country',
                'lead' => 'A single country: currency, default locale, and state.',
            ],
            'pageBreadcrumb' => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
                ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
                ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Country'],
            ],
            'pageTabs' => [
                ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, 'label' => 'Names'],
            ],
            'pageChildren' => [],
        ], $country->toArray());
        self::assertSame($country->toArray(), $names->toArray());
        self::assertSame([
            'label' => 'Country',
            'lead' => 'A single country: currency, default locale, and state.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_COUNTRY));
        self::assertSame([
            'label' => 'Country names',
            'lead' => 'How this country is named in each language.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_I18N_COUNTRY_NAMES));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
                'label' => 'Country names', 'lead' => 'How this country is named in each language.'],
        ], PageCatalogResolver::children(HilosPageConstants::HILOS_I18N_COUNTRY));
        self::assertSame(
            HilosPageConstants::HILOS_I18N_COUNTRY,
            PageCatalogResolver::rowOwner(HilosPageConstants::HILOS_I18N_COUNTRY_NAMES),
        );
    }

    public function testCommunicationsTabsResolveToTheirEntityWithoutEnablingTheFrameworkHead(): void
    {
        Hilos::initBrowser();
        $params = new PageRouteParams(['channelId' => 'email']);

        foreach ([
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, $params));
        }
        self::assertNull(PageCatalogResolver::head(HilosPageConstants::HILOS_COMMUNICATIONS, $params));
        self::assertSame([
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ], array_column(PageCatalogResolver::children(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL), 'page'));
    }

    /** Adopted providers give the channel one row and leave the hub without child tabs. */
    public function testCommunicationsOptInSharesTheChannelHeadAcrossAllThreeTabs(): void
    {
        foreach ([
            CommunicationsPageHeadTestHilos::class,
            ChatCommunicationsPageHeadTestHilos::class,
            BinanceCommunicationsPageHeadTestHilos::class,
            TasksCommunicationsPageHeadTestHilos::class,
            EcommerceCommunicationsPageHeadTestHilos::class,
            PollsCommunicationsPageHeadTestHilos::class,
            OnlineTestingCommunicationsPageHeadTestHilos::class,
        ] as $hilos) {
            $hilos::initBrowser();
            $params = new PageRouteParams(['channelId' => 'email']);

            foreach ([
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
                HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
                HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
            ] as $page) {
                $head = PageCatalogResolver::head($page, $params);
                self::assertNotNull($head);
                self::assertSame([
                    'pageHeadOwner' => [
                        'page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                        'label' => 'Channel',
                        'lead' => 'A single communication channel and its configuration.',
                        'icon' => 'bi-envelope-paper',
                    ],
                    'pageBreadcrumb' => [
                        ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS, 'label' => 'Communications'],
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, 'label' => 'Channel'],
                    ],
                    'pageTabs' => [
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, 'label' => 'Overview'],
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, 'label' => 'Configuration'],
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES, 'label' => 'Deliveries'],
                        ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME, 'label' => 'Letter frame'],
                    ],
                    'pageChildren' => [],
                ], $head->toArray());
            }

            $section = PageCatalogResolver::head(HilosPageConstants::HILOS_COMMUNICATIONS, $params);
            self::assertNotNull($section);
            self::assertSame([
                'pageHeadOwner' => [
                    'page' => HilosPageConstants::HILOS_COMMUNICATIONS,
                    'label' => 'Communications',
                    'lead' => 'Email, SMS, push, and chat delivery channels.',
                    'icon' => 'bi-envelope-paper',
                ],
                'pageBreadcrumb' => [
                    ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                    ['page' => HilosPageConstants::HILOS_COMMUNICATIONS, 'label' => 'Communications'],
                ],
                'pageTabs' => [
                    ['page' => HilosPageConstants::HILOS_COMMUNICATIONS, 'label' => 'Overview'],
                ],
                'pageChildren' => [],
            ], $section->toArray());
        }
    }

    public function testLetterFrameTabIsOnlyServedForEmailAndRemainsOmittedWhenUnserved(): void
    {
        CommunicationsPageHeadTestHilos::initBrowser();
        foreach (['sms', 'push'] as $channel) {
            $head = PageCatalogResolver::head(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                new PageRouteParams(['channelId' => $channel]));
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, 'label' => 'Configuration'],
                ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES, 'label' => 'Deliveries'],
            ], $head->pageTabs);
        }
        Hilos::$sr = $this->createStub(SignalRouter::class);
        Hilos::$sr->method('servesPage')->willReturnCallback(
            static fn (string $page): bool => $page !== HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME);
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            new PageRouteParams(['channelId' => 'email']));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, 'label' => 'Configuration'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES, 'label' => 'Deliveries'],
        ], $head->pageTabs);
    }

    /** A provider that did not adopt Communications keeps its legacy breadcrumb and child cards. */
    public function testRegisteredCommunicationsPagesKeepTheLegacyHeadWithoutOptIn(): void
    {
        LegacyCommunicationsPageHeadTestHilos::initBrowser();

        self::assertNull(PageCatalogResolver::head(
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            new PageRouteParams(['channelId' => 'email']),
        ));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS, 'label' => 'Communications'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, 'label' => 'Channel'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, 'label' => 'Configuration'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
                'label' => 'Configuration', 'lead' => 'The fields of one channel.'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
                'label' => 'Deliveries', 'lead' => 'Delivery log for one channel.'],
            ['page' => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
                'label' => 'Letter frame', 'lead' => 'Header, footer and styles around every letter.'],
        ], PageCatalogResolver::children(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL));
    }

    public function testOwnerLookupUsesTheNamedProviderBeforeBindingIt(): void
    {
        Hilos::initBrowser();

        self::assertSame(
            HilosPageConstants::HILOS_ROLES,
            PageCatalogResolver::rowOwnerOf(PageHeadTestCatalog::ROLES_LIST, PageHeadTestCatalog::class),
        );
        self::assertSame(
            PageHeadTestCatalog::ROLE,
            PageCatalogResolver::rowOwnerOf(PageHeadTestCatalog::RIGHTS, PageHeadTestCatalog::class),
        );
        self::assertNull(PageCatalogResolver::rowOwner(PageHeadTestCatalog::ROLE));
    }

    public function testDepthFirstTabsStayInOneRowAndExcludeWholeEntityBranches(): void
    {
        PageHeadTestHilos::initBrowser();
        $params = new PageRouteParams(['variant' => 'enabled']);
        $head = PageCatalogResolver::head(PageHeadTestCatalog::MACHINE, $params);

        self::assertNotNull($head);
        self::assertSame([
            'page' => HilosPageConstants::HILOS_I18N,
            'label' => 'Translation workspace',
            'lead' => 'Translation workspace lead.',
            'icon' => 'bi-translate',
        ], $head->pageHeadOwner);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
        ], $head->pageBreadcrumb);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ['page' => PageHeadTestCatalog::GAPS, 'label' => 'Gaps'],
            ['page' => PageHeadTestCatalog::MACHINE, 'label' => 'Machine'],
            ['page' => PageHeadTestCatalog::FINDINGS, 'label' => 'Other findings'],
        ], $head->pageTabs);
        self::assertSame(HilosPageConstants::HILOS_I18N, PageCatalogResolver::rowOwner(PageHeadTestCatalog::GAPS));
        self::assertSame(
            $head->toArray(),
            PageCatalogResolver::head(HilosPageConstants::HILOS_I18N_COUNTRIES, $params)?->toArray(),
        );
    }

    public function testEmptyAncestorCrumbDoesNotBreakTheChainAndMissingCaptionUsesLabel(): void
    {
        PageHeadTestHilos::initBrowser();

        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Country'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_I18N_COUNTRY_NAMES));
    }

    public function testRoleChainKeepsDifferentKeysWithRepeatedCaptionsEvenWithoutServedAncestors(): void
    {
        PageHeadTestHilos::initBrowser();

        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_ROLES, 'label' => 'Roles'],
            ['page' => PageHeadTestCatalog::ROLES_LIST, 'label' => 'Roles'],
            ['page' => PageHeadTestCatalog::ROLE, 'label' => 'Role'],
        ], PageCatalogResolver::breadcrumb(PageHeadTestCatalog::RIGHTS));
        self::assertFalse(Hilos::$sr->servesPage(PageHeadTestCatalog::ROLES_LIST));
    }

    public function testUsersIsItsOwnListAndASingleTabIsStillReturned(): void
    {
        PageHeadTestHilos::initBrowser();
        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_USERS, new PageRouteParams([]));

        self::assertNotNull($head);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Users'],
        ], $head->pageBreadcrumb);
        self::assertSame([['page' => HilosPageConstants::HILOS_USERS, 'label' => 'Overview']], $head->pageTabs);
    }

    public function testDisabledNestedEntityStopsAdoptionAndKeepsItsLegacyChain(): void
    {
        PageHeadTestHilos::initBrowser();

        self::assertSame(
            PageHeadTestCatalog::REGION,
            PageCatalogResolver::rowOwner(PageHeadTestCatalog::REGION_HISTORY),
        );
        self::assertNull(PageCatalogResolver::head(PageHeadTestCatalog::REGION, new PageRouteParams([])));
        self::assertNull(PageCatalogResolver::head(PageHeadTestCatalog::REGION_HISTORY, new PageRouteParams([])));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Translation workspace'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Country'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, 'label' => 'Country names'],
            ['page' => PageHeadTestCatalog::REGION, 'label' => 'Region title'],
            ['page' => PageHeadTestCatalog::REGION_HISTORY, 'label' => 'Region history'],
        ], PageCatalogResolver::breadcrumb(PageHeadTestCatalog::REGION_HISTORY));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, 'label' => 'Names'],
        ], PageCatalogResolver::head(HilosPageConstants::HILOS_I18N_COUNTRY, new PageRouteParams([]))?->pageTabs);
    }

    public function testUnknownExternalDashboardAndDisabledOwnersHaveNoHead(): void
    {
        PageHeadTestHilos::initBrowser();
        foreach (['unknown', HilosPageConstants::HILOS_DASHBOARD, HilosPageConstants::HILOS_PROFILE_SIGN_IN] as $page) {
            self::assertNull(PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, new PageRouteParams([])));
        }
        self::assertSame([], PageCatalogResolver::breadcrumb('unknown'));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_PROFILE, 'label' => 'Profile'],
            ['page' => HilosPageConstants::HILOS_PROFILE_SIGN_IN, 'label' => 'Ways to sign in'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_PROFILE_SIGN_IN));

        PageCatalogResolverTestHilos::initBrowser();
        self::assertSame(
            PageCatalogResolverTestCatalog::MODERATION,
            PageCatalogResolver::rowOwner(PageCatalogResolverTestCatalog::MODERATION_QUEUE),
        );
        self::assertNull(PageCatalogResolver::head(PageCatalogResolverTestCatalog::MODERATION_QUEUE, new PageRouteParams([])));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => PageCatalogResolverTestCatalog::MODERATION, 'label' => 'Moderation'],
            ['page' => PageCatalogResolverTestCatalog::MODERATION_QUEUE, 'label' => 'Queue'],
        ], PageCatalogResolver::breadcrumb(PageCatalogResolverTestCatalog::MODERATION_QUEUE));
    }

    public function testTabApplicabilityIsRecomputedForEachSubscriptionAndPrunesDescendants(): void
    {
        PageHeadTestHilos::initBrowser();
        $enabled = PageCatalogResolver::head(HilosPageConstants::HILOS_I18N, new PageRouteParams(['variant' => 'enabled']));
        $disabled = PageCatalogResolver::head(HilosPageConstants::HILOS_I18N, new PageRouteParams(['variant' => 'disabled']));

        self::assertNotNull($enabled);
        self::assertNotNull($disabled);
        self::assertSame([
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_I18N_COUNTRIES,
            PageHeadTestCatalog::GAPS,
            PageHeadTestCatalog::MACHINE,
            PageHeadTestCatalog::FINDINGS,
        ], array_column($enabled->pageTabs, 'page'));
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ['page' => PageHeadTestCatalog::FINDINGS, 'label' => 'Other findings'],
        ], $disabled->pageTabs);
        self::assertSame($enabled->pageBreadcrumb, $disabled->pageBreadcrumb);
    }

    public function testAnUnregisteredPageIsOmittedEvenWhenTheRouterReportsItServed(): void
    {
        PageHeadTestHilos::initBrowser();
        Hilos::$sr = $this->createStub(SignalRouter::class);
        Hilos::$sr->method('servesPage')->willReturnCallback(
            static fn (string $page): bool => $page !== PageHeadTestCatalog::UNSERVED,
        );

        $head = PageCatalogResolver::head(HilosPageConstants::HILOS_I18N, new PageRouteParams(['variant' => 'enabled']));

        self::assertNotNull($head);
        self::assertNotContains(PageHeadTestCatalog::MISSING, array_column($head->pageTabs, 'page'));
        self::assertNotContains(PageHeadTestCatalog::MISSING_CHILD, array_column($head->pageTabs, 'page'));
    }

    public function testProjectSectionsComeAfterTheFrameworkOnes(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                'People',
                'Product',
                'Platform',
                'Observability',
                'Intelligence',
                'Money',
                'Moderation',
            ],
            array_column(PageCatalogResolver::dashboardSections(), PageCatalogConstants::SECTION_TITLE),
        );
    }

    /**
     * Without a project provider the framework catalog is the whole of it, which is what the stub
     * on the base facade buys: a project owning no admin pages declares nothing.
     */
    public function testTheStubAddsNothing(): void
    {
        Hilos::initBrowser();

        self::assertArrayNotHasKey(PageCatalogResolverTestCatalog::MODERATION, PageCatalogResolver::catalog());
        self::assertNotContains(
            'Moderation',
            array_column(PageCatalogResolver::dashboardSections(), PageCatalogConstants::SECTION_TITLE),
        );
    }
}

/**
 * Page catalog fixture: one section of its own, one page under it, one framework page renamed.
 */
final class PageCatalogResolverTestCatalog implements PageCatalogProviderInterface
{
    public const string MODERATION = 'moderation';

    public const string MODERATION_QUEUE = 'moderation_queue';

    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Pages of the fixture project
     */
    public static function pages(): array
    {
        return [
            self::MODERATION => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Moderation',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Reported messages and the operators who judge them.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-shield-exclamation',
            ],
            self::MODERATION_QUEUE => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Queue',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Reports waiting for a moderator.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => self::MODERATION,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Short queue',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            HilosPageConstants::HILOS_I18N => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Languages of the product',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Product copy in every language it ships in.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-translate',
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Workspace',
                PageCatalogConstants::CATALOG_ENTRY_ENTITY => false,
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => false,
            ],
        ];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> Sections of the fixture project
     */
    public static function dashboardSections(): array
    {
        return [
            [
                PageCatalogConstants::SECTION_TITLE => 'Moderation',
                PageCatalogConstants::SECTION_DESCRIPTION => 'What this product adds to the panel.',
                PageCatalogConstants::SECTION_ITEMS => [self::MODERATION],
            ],
        ];
    }
}

/**
 * Facade fixture naming a page catalog of its own.
 *
 * Abstract because the merge is answered from constants alone: no layer is built from this class,
 * and leaving createDb() unimplemented says so more plainly than a DB context no test would reach.
 */
abstract class PageCatalogResolverTestHilos extends Hilos
{
    protected const string PAGE_CATALOG = PageCatalogResolverTestCatalog::class;
}

/** Chat-like fixture: Security and its OAuth provider opt into the shared head locally. */
abstract class SecurityPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_SECURITY => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_2FA => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_STEP_UP => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_OAUTH => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_SECURITY_IMPERSONATION => PageHeadTestPage::class,
    ];

    protected const string PAGE_CATALOG = SecurityPageHeadTestCatalog::class;
}

/** Chat's complete section entry is used without the fixture's provider opt-in. */
abstract class ChatSecurityPageHeadTestHilos extends SecurityPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** Security head adoption stays in this fixture rather than the framework catalog. */
final class SecurityPageHeadTestCatalog implements PageCatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Complete Security and provider entries for the fixture */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_SECURITY => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional dashboard groups */
    public static function dashboardSections(): array
    {
        return [];
    }
}

/** All Communications routes registered against the unadopted framework catalog. */
abstract class LegacyCommunicationsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_COMMUNICATIONS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => PageHeadTestPage::class,
        HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION => PageHeadTestPage::class,
        HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME => CommunicationsFramePageHeadTestPage::class,
    ];
}

/** The Vue adoption changes catalog metadata without changing registration or subscription ownership. */
abstract class CommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = CommunicationsPageHeadTestCatalog::class;
}

/** Full framework rows are required because the resolver replaces catalog entries as a whole. */
final class CommunicationsPageHeadTestCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Both Communications owners opted in
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_COMMUNICATIONS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}

/** A legacy project serves the user overview without adopting its head. */
abstract class UserPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_USER => PageHeadTestPage::class,
    ];
}

/** The chat provider supplies the full user row with the shared head enabled. */
abstract class ChatUserPageHeadTestHilos extends UserPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** The Binance provider opts in independently of the chat provider. */
abstract class BinanceUserPageHeadTestHilos extends UserPageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** Test-only children are deliberately registered in reverse catalog order. */
abstract class ChatUserTabsPageHeadTestHilos extends ChatUserPageHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_USER_SESSIONS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USER_ACCESS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USER_ROLES => PageHeadTestPage::class,
    ];
}

/** The same future tabs use the Binance provider without adding production pages. */
abstract class BinanceUserTabsPageHeadTestHilos extends BinanceUserPageHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_USER_SESSIONS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USER_ACCESS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USER_ROLES => PageHeadTestPage::class,
    ];
}

/** All Logs pages, registered out of catalog order to exercise ordering independently. */
abstract class LogsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_LOGS_VIEW => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LOGS_SETTINGS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LOGS_ROTATIONS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LOGS_WORKERS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LOGS_KEYS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LOGS => PageHeadTestPage::class,
    ];
}

/** Chat's real provider supplies the complete Logs opt-in. */
abstract class ChatLogsPageHeadTestHilos extends LogsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** Binance's real provider supplies its own Logs opt-in. */
abstract class BinanceLogsPageHeadTestHilos extends LogsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** Only Overview is served; the other catalog tabs must be absent. */
abstract class ChatLogsOverviewHeadTestHilos extends ChatLogsPageHeadTestHilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_LOGS => PageHeadTestPage::class,
    ];
}

/** The Binance provider applies the same served-page filter. */
abstract class BinanceLogsOverviewHeadTestHilos extends BinanceLogsPageHeadTestHilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_LOGS => PageHeadTestPage::class,
    ];
}

/** A legacy project serves Appearance without adopting its head. */
abstract class AppearancePageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_APPEARANCE => PageHeadTestPage::class,
    ];
}

/** The chat provider supplies the full Appearance row with the shared head enabled. */
abstract class ChatAppearancePageHeadTestHilos extends AppearancePageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** The Binance provider opts Appearance in independently of the chat provider. */
abstract class BinanceAppearancePageHeadTestHilos extends AppearancePageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** A legacy project serves Backups without adopting its head. */
abstract class BackupsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_BACKUP => PageHeadTestPage::class,
    ];
}

/** The chat provider opts Backups into the shared head. */
abstract class ChatBackupsPageHeadTestHilos extends BackupsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** The Binance provider opts Backups into the shared head independently. */
abstract class BinanceBackupsPageHeadTestHilos extends BackupsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** Settings is served directly from the framework catalog without a project override. */
abstract class SettingsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_SETTINGS => PageHeadTestPage::class,
    ];
}

/** The Analytics overview and People share a row; its two entities never join it. */
abstract class AnalyticsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_ANALYTICS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_PEOPLE => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_PERSON => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => PageHeadTestPage::class,
    ];
}

/** The Analytics section is served without either optional list page. */
abstract class AnalyticsOverviewOnlyHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_ANALYTICS => PageHeadTestPage::class,
    ];
}

/** Chat's real catalog supplies the Vue Analytics entity opt-ins. */
abstract class ChatAnalyticsPageHeadTestHilos extends AnalyticsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** Binance's real catalog supplies the Vue Analytics entity opt-ins. */
abstract class BinanceAnalyticsPageHeadTestHilos extends AnalyticsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** The Maintenance owner is registered without its optional Circle view. */
abstract class MaintenanceOverviewHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MAINTENANCE => PageHeadTestPage::class,
    ];
}

/** The same owner with both Maintenance pages registered. */
abstract class MaintenancePageHeadTestHilos extends MaintenanceOverviewHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_MAINTENANCE_CIRCLE => PageHeadTestPage::class,
        HilosPageConstants::HILOS_MAINTENANCE_WORDS => PageHeadTestPage::class,
    ];
}

/** The framework catalog with both country pages registered. */
abstract class CountryPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_I18N_COUNTRY => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_COUNTRY_NAMES => PageHeadTestPage::class,
    ];
}

/** Real section metadata with entity branches and one served future frontend tab. */
abstract class I18nPageHeadTestHilos extends CountryPageHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_I18N => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_LANGUAGES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_LANGUAGE => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_COUNTRIES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_GROUPS => PageHeadTestPage::class,
    ];
}

/** Synthetic adoption boundaries and tabs; registration is separate from catalog membership. */
abstract class PageHeadTestHilos extends CountryPageHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_I18N => PageHeadTestPage::class,
        HilosPageConstants::HILOS_I18N_COUNTRIES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USERS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_USER => PageHeadTestPage::class,
        PageHeadTestCatalog::ROLE => PageHeadTestPage::class,
        PageHeadTestCatalog::RIGHTS => PageHeadTestPage::class,
        PageHeadTestCatalog::REGION => PageHeadTestPage::class,
        PageHeadTestCatalog::REGION_HISTORY => PageHeadTestPage::class,
        PageHeadTestCatalog::GAPS => PageHeadTestConditionalTab::class,
        PageHeadTestCatalog::MACHINE => PageHeadTestPage::class,
        PageHeadTestCatalog::FINDINGS => PageHeadTestPage::class,
        PageHeadTestCatalog::MISSING_CHILD => PageHeadTestPage::class,
        PageHeadTestCatalog::UNSERVED => PageHeadTestUnservedTab::class,
        PageHeadTestCatalog::UNSERVED_CHILD => PageHeadTestPage::class,
    ];

    protected const string PAGE_CATALOG = PageHeadTestCatalog::class;
}

/** No head calculation may instantiate a page or its agent. */
class PageHeadTestPage extends AbstractPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'catalog-test';

    /** @param PageAgentInterface $agent Agent whose construction the resolver must never require */
    final public function __construct(PageAgentInterface $agent)
    {
        throw new LogicException('Tab eligibility must not construct pages.');
    }
}

final class PageHeadTestConditionalTab extends PageHeadTestPage
{
    /**
     * @param PageRouteParams $ownerParams Current owner's params; no separate child-tab params are needed
     * @return bool Whether this owner variant has the Gaps branch
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        return $ownerParams->getString('variant') === 'enabled';
    }
}

final class PageHeadTestUnservedTab extends PageHeadTestPage
{
    /**
     * @param PageRouteParams $ownerParams Unused because serving must be checked first
     * @return bool Never returned; an unserved tab must not be asked
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        throw new LogicException('Serving must be checked before applicability.');
    }
}

final class PageHeadTestSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> Facade bound by this test */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }

    /**
     * @param string $page Candidate page key
     * @return bool Whether the registered page is served in this fixture
     */
    public function servesPage(string $page): bool
    {
        return $page !== PageHeadTestCatalog::UNSERVED && parent::servesPage($page);
    }
}

/** Catalog boundary fixture covering entity nesting, repeated captions and pruned tab branches. */
final class PageHeadTestCatalog implements PageCatalogProviderInterface
{
    public const string ROLES_LIST = 'roles_list';
    public const string ROLE = 'role';
    public const string RIGHTS = 'role_rights';
    public const string REGION = 'country_region';
    public const string REGION_HISTORY = 'country_region_history';
    public const string GAPS = 'i18n_gaps';
    public const string MACHINE = 'i18n_gaps_machine';
    public const string FINDINGS = 'i18n_findings';
    public const string MISSING = 'i18n_missing';
    public const string MISSING_CHILD = 'i18n_missing_child';
    public const string UNSERVED = 'i18n_unserved';
    public const string UNSERVED_CHILD = 'i18n_unserved_child';

    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Fixture catalog metadata
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_I18N => self::entry(
                'Translation workspace', HilosPageConstants::HILOS_DASHBOARD, breadcrumb: '', pageHead: true,
            ) + [PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-translate'],
            HilosPageConstants::HILOS_ROLES => self::entry('Roles', HilosPageConstants::HILOS_DASHBOARD, pageHead: true),
            self::ROLES_LIST => self::entry('Roles', HilosPageConstants::HILOS_ROLES),
            self::ROLE => self::entry('Role title', self::ROLES_LIST, breadcrumb: 'Role', entity: true, pageHead: true),
            self::RIGHTS => self::entry('Rights title', self::ROLE, breadcrumb: 'Rights'),
            HilosPageConstants::HILOS_USERS => self::entry('Users', HilosPageConstants::HILOS_DASHBOARD, pageHead: true),
            HilosPageConstants::HILOS_USER => self::entry('User', HilosPageConstants::HILOS_USERS, entity: true, pageHead: true),
            self::REGION => self::entry('Region title', HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, breadcrumb: 'Region', entity: true),
            self::REGION_HISTORY => self::entry('Region history', self::REGION),
            self::GAPS => self::entry('Gaps title', HilosPageConstants::HILOS_I18N, breadcrumb: 'Gaps', pageHead: true),
            self::FINDINGS => self::entry('Other findings', HilosPageConstants::HILOS_I18N),
            self::MACHINE => self::entry('Machine', self::GAPS),
            self::MISSING => self::entry('Missing', HilosPageConstants::HILOS_I18N),
            self::MISSING_CHILD => self::entry('Missing child', self::MISSING),
            self::UNSERVED => self::entry('Unserved', HilosPageConstants::HILOS_I18N),
            self::UNSERVED_CHILD => self::entry('Unserved child', self::UNSERVED),
            HilosPageConstants::HILOS_PROFILE_SIGN_IN => self::entry(
                'Ways to sign in', HilosPageConstants::HILOS_PROFILE, breadcrumb: 'Ignored', entity: true, pageHead: true,
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional dashboard groups */
    public static function dashboardSections(): array
    {
        return [];
    }

    /**
     * @param string $label Page title
     * @param string $parent Parent page key
     * @param ?string $breadcrumb Explicit caption, or null to omit the field
     * @param bool $entity Entity boundary
     * @param bool $pageHead Shared head switch
     * @return array{label: string, lead: string, parent: string, breadcrumb?: string, entity: bool, pageHead: bool} Catalog entry
     */
    private static function entry(
        string $label,
        string $parent,
        ?string $breadcrumb = null,
        bool $entity = false,
        bool $pageHead = false,
    ): array {
        $entry = [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => $label,
            PageCatalogConstants::CATALOG_ENTRY_LEAD => $label . ' lead.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => $parent,
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => $entity,
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => $pageHead,
        ];
        if ($breadcrumb !== null) {
            $entry[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB] = $breadcrumb;
        }

        return $entry;
    }
}

/** Register the whole Node owner while retaining the legacy Daemon root. */
abstract class DaemonPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_DAEMON => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_PLACEMENT => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_NODE => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_WORKERS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_AGENTS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_CRON => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_WEBSOCKETS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_HTTP_SERVER => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_ENV => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH => PageHeadTestPage::class,
    ];
}

abstract class StatusPageCatalogHilos extends Hilos
{
    public const array PAGES = [StatusPageCatalogPage::PAGE => StatusPageCatalogPage::class,
        StatusPageCatalogComponentsPage::PAGE => StatusPageCatalogComponentsPage::class,
        StatusPageCatalogSubscribersPage::PAGE => StatusPageCatalogSubscribersPage::class];
}

abstract class StatusPageCatalogOverviewOnlyHilos extends Hilos
{
    public const array PAGES = [StatusPageCatalogPage::PAGE => StatusPageCatalogPage::class];
}

final class StatusPageCatalogPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusPageCatalogComponentsPage extends AbstractHilosStatusComponentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

/** Communications head adoption from the Chat project's full provider. */
abstract class ChatCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** Communications head adoption from the Binance project's full provider. */
abstract class BinanceCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** Communications head adoption from the Tasks project's full provider. */
abstract class TasksCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = TasksPageCatalog::class;
}

/** Communications head adoption from the Ecommerce project's full provider. */
abstract class EcommerceCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = EcommercePageCatalog::class;
}

/** Communications head adoption from the Polls project's full provider. */
abstract class PollsCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = PollsPageCatalog::class;
}

/** Communications head adoption from the Online Testing project's full provider. */
abstract class OnlineTestingCommunicationsPageHeadTestHilos extends LegacyCommunicationsPageHeadTestHilos
{
    protected const string PAGE_CATALOG = OnlineTestingPageCatalog::class;
}

/** A served moderation section and kind overview with Rules intentionally unserved. */
abstract class ModerationKindPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MODERATION => PageHeadTestPage::class,
        HilosPageConstants::HILOS_MODERATION_KIND => PageHeadTestPage::class,
    ];
}

/** Both kind tabs served without making either one a tab of the section. */
abstract class ModerationKindRulesPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MODERATION => PageHeadTestPage::class,
        HilosPageConstants::HILOS_MODERATION_KIND => PageHeadTestPage::class,
        HilosPageConstants::HILOS_MODERATION_KIND_RULES => PageHeadTestPage::class,
    ];
}

final class CommunicationsFramePageHeadTestPage extends AbstractHilosCommunicationsChannelFramePage
{
}

/** Catalog-only adoption fixture; project adoption remains independent. */
abstract class ChangeLogHeadHilos extends Hilos
{
    protected const string PAGE_CATALOG = ChangeLogHeadCatalog::class;

    public const array PAGES = [
        HilosPageConstants::HILOS_CHANGE_LOG => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_FEED => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_NOISY => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS => ConditionalTableHeadPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY => ConditionalTableHeadPage::class,
    ];
}

abstract class ChangeLogOverviewOnlyHeadHilos extends ChangeLogHeadHilos
{
    public const array PAGES = [HilosPageConstants::HILOS_CHANGE_LOG => PageHeadTestPage::class];
}

final class ChangeLogHeadCatalog implements PageCatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Complete framework owner opted in for the fixture */
    public static function pages(): array
    {
        return [HilosPageConstants::HILOS_CHANGE_LOG => array_replace(
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG],
            [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
        )];
    }

    /** @return array Empty project sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}

/** All registered Change Log pages without project head adoption. */
abstract class ChangeLogDefaultHeadHilos extends Hilos
{
    public const array PAGES = ChangeLogHeadHilos::PAGES;
}

/** Chat's real complete owner entry. */
abstract class ChatChangeLogHeadHilos extends ChangeLogHeadHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

/** Binance's real complete owner entry. */
abstract class BinanceChangeLogHeadHilos extends ChangeLogHeadHilos
{
    protected const string PAGE_CATALOG = BinancePageCatalog::class;
}

/** Unserved Noisy and conditionally applicable Feed in Chat. */
abstract class ChatChangeLogPartialHeadHilos extends ChatChangeLogHeadHilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_CHANGE_LOG_TABLES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_FEED => PageHeadTestConditionalTab::class,
        HilosPageConstants::HILOS_CHANGE_LOG => PageHeadTestPage::class,
    ];
}

/** The same serving boundary in Binance, independently of registration order. */
abstract class BinanceChangeLogPartialHeadHilos extends BinanceChangeLogHeadHilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_CHANGE_LOG_TABLES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_CHANGE_LOG_FEED => PageHeadTestConditionalTab::class,
        HilosPageConstants::HILOS_CHANGE_LOG => PageHeadTestPage::class,
    ];
}

abstract class TableEntityHeadHilos extends ChangeLogHeadHilos
{
    protected const string PAGE_CATALOG = TableEntityHeadCatalog::class;
}

final class TableEntityHeadCatalog implements PageCatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Fixture adoption of the nested table owner */
    public static function pages(): array
    {
        return ChangeLogHeadCatalog::pages() + [HilosPageConstants::HILOS_CHANGE_LOG_TABLE => array_replace(
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
            [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
        )];
    }

    /** @return array Empty project sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}

final class ConditionalTableHeadPage extends AbstractPage
{
    /**
     * @param PageRouteParams $ownerParams Fixture table address
     * @return bool Whether this fixture table carries a journal label
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        return $ownerParams->getString('tableId') === 'tracked';
    }
}

final class StatusPageCatalogSubscribersPage extends AbstractHilosStatusSubscribersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

/** The installed Nodes page joins the existing owner only in cluster mode. */
abstract class MaintenanceNodesHeadTestHilos extends MaintenancePageHeadTestHilos
{
    public const array PAGES = [
        ...parent::PAGES,
        HilosPageConstants::HILOS_MAINTENANCE_NODES => MaintenanceNodesHeadTestPage::class,
    ];
}

final class MaintenanceNodesHeadTestPage extends AbstractHilosMaintenanceNodesPage
{
}

/** All Legal pages served with the default, unadopted framework catalog. */
abstract class LegacyLegalPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_LEGAL => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LEGAL_DOCUMENTS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LEGAL_DOCUMENT => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LEGAL_REVISION => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LEGAL_ACCEPTANCES => PageHeadTestPage::class,
        HilosPageConstants::HILOS_LEGAL_SETTINGS => PageHeadTestPage::class,
    ];
}

/** Future owner adoption exercised without enabling a project route or view. */
abstract class LegalPageHeadTestHilos extends LegacyLegalPageHeadTestHilos
{
    protected const string PAGE_CATALOG = LegalPageHeadTestCatalog::class;
}

/** Existing React provider remains unadopted. */
abstract class TasksLegalPageHeadTestHilos extends LegacyLegalPageHeadTestHilos
{
    protected const string PAGE_CATALOG = TasksPageCatalog::class;
}

/** Existing Angular provider remains unadopted. */
abstract class PollsLegalPageHeadTestHilos extends LegacyLegalPageHeadTestHilos
{
    protected const string PAGE_CATALOG = PollsPageCatalog::class;
}

/** Chat adopts the Legal section without adopting its nested entities. */
abstract class ChatLegalPageHeadTestHilos extends LegacyLegalPageHeadTestHilos
{
    protected const string PAGE_CATALOG = ChatPageCatalog::class;
}

final class LegalPageHeadTestCatalog implements PageCatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Complete Legal owner entry with local adoption */
    public static function pages(): array
    {
        return [HilosPageConstants::HILOS_LEGAL => array_replace(
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LEGAL],
            [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
        )];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional dashboard groups */
    public static function dashboardSections(): array
    {
        return [];
    }
}
