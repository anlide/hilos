<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Consistency of the framework's own admin catalog with itself and with the page keys.
 *
 * Topology validation makes the same judgement at daemon start, over the merged catalog. This
 * pins the framework half on its own, where a typo is a compile-time-ish fact rather than a
 * project's boot failure: the table is 60 hand-written entries, and a key that names no page
 * would otherwise surface as a section that silently never gets identity.
 */
final class HilosPageCatalogTest extends TestCase
{
    /**
     * Number of entries in the catalog. Sixty of them came over from the frontend when the
     * identity moved to the backend (HIL-624); the profile tree joined in HIL-288 and gained
     * sign-in, notification and security sections in HIL-493, then legal agreements and
     * their revision history in HIL-498 and the Legal admin section in HIL-941, while six
     * routed keys still carry no entry on purpose - four public footer pages and the guardian pair - and
     * every page declared since is one more, the logging modes of HIL-762 being the first.
     *
     * It is a deliberate-addition guard rather than a count worth knowing: a page that reaches the
     * catalog is a name and a lead an administrator will read, so it arrives by somebody writing
     * it down here as well.
     */
    private const int CATALOG_ENTRIES = 93;

    /** Number of dashboard sections: the six admin groups from the design system mockup. */
    private const int TRANSFERRED_SECTIONS = 6;

    public function testEveryKeyNamesADeclaredPage(): void
    {
        // Which page keys are declared, asked of the declaration itself: PHP has no plain reader
        // for the constants of a class, and a second hand-kept list would be the very drift the
        // test is here to catch.
        $declared = (new ReflectionClass(HilosPageConstants::class))->getConstants();

        foreach (array_keys(HilosPageCatalog::CATALOG) as $page) {
            self::assertContains($page, $declared, "Catalog entry {$page} names no page constant");
        }
    }

    public function testEveryParentIsItselfAnEntry(): void
    {
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            $parent = $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null;
            if ($parent === null) {
                continue;
            }

            self::assertArrayHasKey(
                $parent,
                HilosPageCatalog::CATALOG,
                "Catalog entry {$page} names parent {$parent}, which has no entry",
            );
        }
    }

    /**
     * The admin dashboard and the current-user profile are separate catalog roots.
     */
    public function testTheDashboardIsTheOnlyRoot(): void
    {
        $roots = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if (!isset($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT])) {
                $roots[] = $page;
            }
        }

        self::assertSame([HilosPageConstants::HILOS_DASHBOARD, HilosPageConstants::HILOS_PROFILE], $roots);
    }

    public function testEverySectionItemIsAnEntry(): void
    {
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            foreach ($section[PageCatalogConstants::SECTION_ITEMS] as $page) {
                self::assertArrayHasKey(
                    $page,
                    HilosPageCatalog::CATALOG,
                    "Dashboard section {$section[PageCatalogConstants::SECTION_TITLE]} lists {$page}, which has no entry",
                );
            }
        }
    }

    /**
     * The operations that ask for confirmation live under two-factor, not beside it (HIL-1204).
     */
    public function testTheStepUpOperationsAreAChildOfTwoFactor(): void
    {
        self::assertSame(
            HilosPageConstants::HILOS_SECURITY_2FA,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_STEP_UP][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
    }

    /**
     * Impersonation is a page of the security hub, not a section of two-factor (HIL-1204, HIL-1170).
     */
    public function testImpersonationIsAChildOfTheSecurityHub(): void
    {
        self::assertSame(
            HilosPageConstants::HILOS_SECURITY,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_IMPERSONATION][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
    }

    public function testTheTransferIsComplete(): void
    {
        self::assertCount(self::CATALOG_ENTRIES, HilosPageCatalog::CATALOG);
        self::assertCount(self::TRANSFERRED_SECTIONS, HilosPageCatalog::DASHBOARD_SECTIONS);
    }

    /**
     * Dashboard sections match the six groups and 17 cards specified by the admin mockup (HIL-1648).
     */
    public function testDashboardSectionsMatchMockupSpecification(): void
    {
        $expected = [
            [
                PageCatalogConstants::SECTION_TITLE => 'People',
                PageCatalogConstants::SECTION_DESCRIPTION => 'Who uses the project, what they may do, and what they agreed to.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_USERS,
                    HilosPageConstants::HILOS_ROLES,
                    HilosPageConstants::HILOS_LEGAL,
                ],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => 'Product',
                PageCatalogConstants::SECTION_DESCRIPTION =>
                    'What the project offers outward: content, search, messaging, sign-in, languages, and integrations.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_COMMUNICATIONS,
                    HilosPageConstants::HILOS_SECURITY,
                    HilosPageConstants::HILOS_I18N,
                ],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => 'Platform',
                PageCatalogConstants::SECTION_DESCRIPTION => 'Settings, releases, files, backups, and keeping the system up.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_SETTINGS,
                    HilosPageConstants::HILOS_APPEARANCE,
                    HilosPageConstants::HILOS_BACKUP,
                    HilosPageConstants::HILOS_MAINTENANCE,
                    HilosPageConstants::HILOS_STATUS,
                ],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => 'Observability',
                PageCatalogConstants::SECTION_DESCRIPTION => 'What the system and its visitors are doing, now and over time.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_DAEMON,
                    HilosPageConstants::HILOS_LOGS,
                    HilosPageConstants::HILOS_CHANGE_LOG,
                    HilosPageConstants::HILOS_ANALYTICS,
                ],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => 'Intelligence',
                PageCatalogConstants::SECTION_DESCRIPTION => 'AI tooling, watchdogs, and automation.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_MCP_SKILLS,
                    HilosPageConstants::HILOS_SIL,
                ],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => 'Money',
                PageCatalogConstants::SECTION_DESCRIPTION => 'What people pay the project, and what its AI costs.',
                PageCatalogConstants::SECTION_ITEMS => [
                    HilosPageConstants::HILOS_BILLING,
                ],
            ],
        ];

        self::assertSame($expected, HilosPageCatalog::DASHBOARD_SECTIONS);

        $allItems = array_merge(
            ...array_column(HilosPageCatalog::DASHBOARD_SECTIONS, PageCatalogConstants::SECTION_ITEMS),
        );
        self::assertCount(18, $allItems);
        self::assertSame($allItems, array_values(array_unique($allItems)));
    }

    public function testDaemonEnvironmentBelongsToNodeAndMismatchToSection(): void
    {
        self::assertSame(
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
        self::assertSame(
            HilosPageConstants::HILOS_DAEMON,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
        self::assertSame(
            'Environment',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV][PageCatalogConstants::CATALOG_ENTRY_LABEL],
        );
        self::assertSame(
            'Env mismatch',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH][PageCatalogConstants::CATALOG_ENTRY_LABEL],
        );
    }

    /** The person's sessions page is a child of Analytics and, needing a person, no dashboard card (HIL-1420). */
    public function testAnalyticsPersonIsAChildOfAnalytics(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Person',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Browser sessions where this person signed in last. '
                . 'Other people may have performed actions in the same browser.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS,
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PERSON]);
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            self::assertNotContains(HilosPageConstants::HILOS_ANALYTICS_PERSON, $section[PageCatalogConstants::SECTION_ITEMS]);
        }
    }

    public function testI18nDetailPagesFollowTheirParent(): void
    {
        foreach ([
            HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES => HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_COUNTRY_NAMES => HilosPageConstants::HILOS_I18N_COUNTRY,
        ] as $page => $parent) {
            self::assertSame($parent, HilosPageCatalog::CATALOG[$page][PageCatalogConstants::CATALOG_ENTRY_PARENT]);
        }
    }

    public function testCircleSharesTheAdoptedMaintenanceHead(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Circle',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Who will check the system after an operation.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MAINTENANCE,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MAINTENANCE_CIRCLE]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Maintenance',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Node freeze under a destructive operation and manual maintenance: '
                . 'its state, the verifier circle, closing the system to visitors, and the way out of the window.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-tools',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MAINTENANCE]);
    }

    /** The user is an entity; its future pages are ordered tabs with their own captions. */
    public function testUserMetadataKeepsItsChildrenInRolesAccessSessionsOrder(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single user: profile, presence, and account actions.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_USERS,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'User',
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USER]);
        $children = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if (($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) === HilosPageConstants::HILOS_USER) {
                $children[$page] = $entry;
            }
        }
        self::assertSame([
            HilosPageConstants::HILOS_USER_ROLES => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User roles',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Roles granted to this user and the scope of each grant.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_USER,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Roles',
            ],
            HilosPageConstants::HILOS_USER_ACCESS => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User access',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'What this user may do and which roles grant that access.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_USER,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Access',
            ],
            HilosPageConstants::HILOS_USER_SESSIONS => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User sessions',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'This user\'s sessions and their activity.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_USER,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Sessions',
            ],
        ], $children);
    }

    public function testI18nCountryAndMaintenanceAdoptTheSharedHead(): void
    {
        $adopted = [];
        $entities = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if ($entry[PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD] ?? false) {
                $adopted[] = $page;
            }
            if ($entry[PageCatalogConstants::CATALOG_ENTRY_ENTITY] ?? false) {
                $entities[] = $page;
            }
        }

        self::assertSame([
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageConstants::HILOS_MAINTENANCE,
            HilosPageConstants::HILOS_STATUS,
            HilosPageConstants::HILOS_ANALYTICS,
        ], $adopted);
        self::assertSame([
            HilosPageConstants::HILOS_USER,
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION,
            HilosPageConstants::HILOS_ANALYTICS_PERSON,
        ], $entities);
        self::assertSame(
            'Internationalization',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Languages',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_LANGUAGES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Countries',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_COUNTRIES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Country',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_COUNTRY][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Names',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_COUNTRY_NAMES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
    }
}
