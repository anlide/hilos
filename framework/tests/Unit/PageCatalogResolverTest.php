<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the page catalog reader: the merge, one page's identity, its breadcrumb.
 *
 * The project half is bound the way a running process binds it - a facade fixture naming its own
 * provider, captured by initBrowser() - because the merge rule is only interesting when there is
 * something to merge, and because a project entry under a framework key is the supported way to
 * rename a section rather than a collision.
 */
final class PageCatalogResolverTest extends TestCase
{
    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
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
     * A routed page the catalog does not carry is not an error: the public footer pages and the
     * profile live outside the admin tree, and their subscription answers without identity.
     */
    public function testAPageWithoutAnEntryHasNoIdentityAndNoBreadcrumb(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertNull(PageCatalogResolver::identity(HilosPageConstants::HILOS_PROFILE));
        self::assertSame([], PageCatalogResolver::breadcrumb(HilosPageConstants::HILOS_PROFILE));
    }

    public function testProjectSectionsComeAfterTheFrameworkOnes(): void
    {
        PageCatalogResolverTestHilos::initBrowser();

        self::assertSame(
            [
                'Access & identity',
                'Localization',
                'Product & integrations',
                'Platform operations',
                'Automation & intelligence',
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
     * @return array<string, array{label: string, lead: string, parent?: string, icon?: string}> Pages of the fixture project
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
            ],
            HilosPageConstants::HILOS_I18N => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Languages of the product',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Product copy in every language it ships in.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-translate',
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
