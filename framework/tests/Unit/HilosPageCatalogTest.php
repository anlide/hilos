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
    private const int CATALOG_ENTRIES = 111;

    /** Number of dashboard sections: the six admin groups from the design system mockup. */
    private const int TRANSFERRED_SECTIONS = 6;

    public function testTableEntityOwnsItsThreeChildrenWithoutDefaultHeadAdoption(): void
    {
        $owner = HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE];
        self::assertTrue($owner[PageCatalogConstants::CATALOG_ENTRY_ENTITY]);
        self::assertSame('bi-table', $owner[PageCatalogConstants::CATALOG_ENTRY_ICON]);
        self::assertSame('One table: its journal label, columns and triggers.', $owner[PageCatalogConstants::CATALOG_ENTRY_LEAD]);
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, $owner);
        $children = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if (($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) === HilosPageConstants::HILOS_CHANGE_LOG_TABLE) {
                $children[$page] = $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL];
                self::assertSame($entry[PageCatalogConstants::CATALOG_ENTRY_LABEL], $entry[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB]);
                self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_ENTITY, $entry);
                self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, $entry);
            }
        }
        self::assertSame([
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE_COLUMNS => 'Columns',
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE_TRIGGERS => 'Triggers',
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE_HISTORY => 'History',
        ], $children);
    }

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

    /** The operations that ask for confirmation are a page of the security hub (HIL-1669). */
    public function testTheStepUpOperationsAreAChildOfTheSecurityHub(): void
    {
        self::assertSame(
            HilosPageConstants::HILOS_SECURITY,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_STEP_UP][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
    }

    public function testOAuthProviderIsAnEntityWithoutGlobalHeadAdoption(): void
    {
        $provider = HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER];

        self::assertTrue($provider[PageCatalogConstants::CATALOG_ENTRY_ENTITY]);
        self::assertSame('OAuth provider', $provider[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB]);
        self::assertArrayNotHasKey(
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SECURITY],
        );
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, $provider);
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

    public function testCommunicationsChannelIsAnEntityWithConfigurationBeforeDeliveries(): void
    {
        $channel = HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL];
        self::assertSame(HilosPageConstants::HILOS_COMMUNICATIONS, $channel[PageCatalogConstants::CATALOG_ENTRY_PARENT]);
        self::assertTrue($channel[PageCatalogConstants::CATALOG_ENTRY_ENTITY]);
        self::assertSame('Channel', $channel[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB]);
        self::assertSame('bi-envelope-paper', $channel[PageCatalogConstants::CATALOG_ENTRY_ICON]);
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD, $channel);
        self::assertArrayNotHasKey(
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS],
        );
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Configuration',
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Configuration',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'The fields of one channel.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION]);
        $children = [];
        foreach (HilosPageCatalog::CATALOG as $page => $entry) {
            if (($entry[PageCatalogConstants::CATALOG_ENTRY_PARENT] ?? null) === HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL) {
                $children[] = $page;
            }
        }
        self::assertSame([
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ], $children);
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
                    HilosPageConstants::HILOS_MODERATION,
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
        self::assertCount(19, $allItems);
        self::assertSame($allItems, array_values(array_unique($allItems)));
    }

    public function testModerationBelongsToDashboardAndDeclaresPageHead(): void
    {
        $entry = HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MODERATION];
        self::assertSame('Moderation', $entry[PageCatalogConstants::CATALOG_ENTRY_LABEL]);
        self::assertSame('What is checked before it is shown, and by which rules.', $entry[PageCatalogConstants::CATALOG_ENTRY_LEAD]);
        self::assertSame(HilosPageConstants::HILOS_DASHBOARD, $entry[PageCatalogConstants::CATALOG_ENTRY_PARENT]);
        self::assertSame('bi-shield-check', $entry[PageCatalogConstants::CATALOG_ENTRY_ICON]);
        self::assertSame('Moderation', $entry[PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB]);
        self::assertTrue($entry[PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD]);
        self::assertArrayNotHasKey(PageCatalogConstants::CATALOG_ENTRY_ENTITY, $entry);
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
        self::assertSame(
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS][PageCatalogConstants::CATALOG_ENTRY_PARENT],
        );
        self::assertSame(
            'Env orphans',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS][PageCatalogConstants::CATALOG_ENTRY_LABEL],
        );
        self::assertSame(
            'Lines in .env that are not declared in the catalog.',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_DAEMON_ENV_ORPHANS][PageCatalogConstants::CATALOG_ENTRY_LEAD],
        );
    }

    /** Browser sessions is a sibling of People and the breadcrumb parent of a session. */
    public function testAnalyticsBrowserSessionsTabOwnsTheSessionPage(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Browser sessions',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Browser sessions seen in the selected period.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Browser session',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Actions recorded in one browser session, in source-time order.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-window',
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION]);
        self::assertArrayNotHasKey(
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD,
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION],
        );
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            self::assertNotContains(HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, $section[PageCatalogConstants::SECTION_ITEMS]);
        }
    }

    /** People is an Analytics tab; the person's sessions page is its entity child, without a dashboard card. */
    public function testAnalyticsPeopleTabOwnsThePersonPage(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'People',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'People who last signed into browser sessions seen in the selected period.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PEOPLE]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Person',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Browser sessions where this person signed in last. '
                . 'Other people may have performed actions in the same browser.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_ANALYTICS_PEOPLE,
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PERSON]);
        foreach (HilosPageCatalog::DASHBOARD_SECTIONS as $section) {
            self::assertNotContains(HilosPageConstants::HILOS_ANALYTICS_PEOPLE, $section[PageCatalogConstants::SECTION_ITEMS]);
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

    public function testWorksIsAStatusChildWithNoSeparateHead(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Scheduled maintenance',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Announced maintenance windows and subscriber mail handoffs.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_STATUS,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Scheduled maintenance',
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_STATUS_WORKS]);
    }

    public function testSubscribersIsAStatusChildWithNoSeparateHead(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Subscribers',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Who receives email about outages.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_STATUS,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Subscribers',
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_STATUS_SUBSCRIBERS]);
    }

    public function testCircleSharesTheAdoptedMaintenanceHead(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Circle',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Who will check the system after an operation.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MAINTENANCE,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MAINTENANCE_CIRCLE]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Words',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'The words used to announce maintenance.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MAINTENANCE,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MAINTENANCE_WORDS]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Nodes',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Maintenance reports from the cluster nodes.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MAINTENANCE,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MAINTENANCE_NODES]);
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

    public function testModerationRulesIsAChildTabWithoutItsOwnHeadOrEntity(): void
    {
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Rules',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Your own rules for this kind.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MODERATION_KIND,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Rules',
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_MODERATION_KIND_RULES]);
    }

    /** Keeps the adopted owners and entity boundaries explicit in catalog order. */
    public function testOnlyDeclaredOwnersAdoptTheSharedHead(): void
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
            HilosPageConstants::HILOS_MODERATION,
            HilosPageConstants::HILOS_MODERATION_KIND,
            HilosPageConstants::HILOS_I18N,
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_SETTINGS,
            HilosPageConstants::HILOS_DAEMON,
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageConstants::HILOS_MAINTENANCE,
            HilosPageConstants::HILOS_STATUS,
            HilosPageConstants::HILOS_ANALYTICS,
        ], $adopted);
        self::assertSame([
            HilosPageConstants::HILOS_USER,
            HilosPageConstants::HILOS_MODERATION_KIND,
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
            HilosPageConstants::HILOS_DAEMON_NODE,
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE,
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
            'Language',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_LANGUAGE][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Names',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Locales',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Country',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_COUNTRY][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame(
            'Names',
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_I18N_COUNTRY_NAMES][PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB],
        );
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Settings',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Application setting catalog: catalog defaults, overrides, and references.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-sliders',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_SETTINGS]);
    }
}
