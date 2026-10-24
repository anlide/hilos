<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use Hilos\Constants\HilosAgentType;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use LogicException;
use PHPUnit\Framework\TestCase;

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

    public function testStatusSharesItsCatalogHeadAndOmitsAnUnservedChild(): void
    {
        StatusPageCatalogHilos::initBrowser();
        foreach ([HilosPageConstants::HILOS_STATUS, HilosPageConstants::HILOS_STATUS_COMPONENTS] as $page) {
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

    public function testOverviewAndCircleShareTheExactMaintenanceHead(): void
    {
        MaintenancePageHeadTestHilos::initBrowser();

        self::assertSame([
            'label' => 'Circle',
            'lead' => 'Who will check the system after an operation.',
        ], PageCatalogResolver::identity(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE));
        $heads = [];
        foreach ([HilosPageConstants::HILOS_MAINTENANCE, HilosPageConstants::HILOS_MAINTENANCE_CIRCLE] as $page) {
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
                ],
                'pageChildren' => [],
            ], $head->toArray());
        }
        self::assertNotSame($heads[0], $heads[1]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
                'label' => 'Circle', 'lead' => 'Who will check the system after an operation.'],
        ], PageCatalogResolver::children(HilosPageConstants::HILOS_MAINTENANCE));
        self::assertSame([], PageCatalogResolver::children(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE));
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

    public function testAnalyticsAdoptsOnlyTheOverviewAndKeepsBothEntityHeadsLegacy(): void
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
            'pageTabs' => [['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Overview']],
            'pageChildren' => [],
        ], PageCatalogResolver::head(HilosPageConstants::HILOS_ANALYTICS, $params)?->toArray());
        foreach ([
            HilosPageConstants::HILOS_ANALYTICS_PERSON => 'Person',
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => 'Browser session',
        ] as $page => $label) {
            self::assertSame($page, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, $params));
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
                ['page' => $page, 'label' => $label],
            ], PageCatalogResolver::breadcrumb($page));
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

    public function testLanguageBoundaryKeepsItsWholeBranchOnTheLegacyHead(): void
    {
        I18nPageHeadTestHilos::initBrowser();
        foreach ([
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
        ] as $page) {
            self::assertSame(HilosPageConstants::HILOS_I18N_LANGUAGE, PageCatalogResolver::rowOwner($page));
            self::assertNull(PageCatalogResolver::head($page, new PageRouteParams([])));
        }
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
            ['page' => HilosPageConstants::HILOS_I18N_LANGUAGES, 'label' => 'Languages'],
            ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE, 'label' => 'Language'],
            ['page' => HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES, 'label' => 'Language names'],
        ], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES));
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

/** All three Analytics pages are served; its entities must never join the overview's row. */
abstract class AnalyticsPageHeadTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_ANALYTICS => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_PERSON => PageHeadTestPage::class,
        HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => PageHeadTestPage::class,
    ];
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

abstract class StatusPageCatalogHilos extends Hilos
{
    public const array PAGES = [StatusPageCatalogPage::PAGE => StatusPageCatalogPage::class,
        StatusPageCatalogComponentsPage::PAGE => StatusPageCatalogComponentsPage::class];
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
