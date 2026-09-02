<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

use Hilos\Constants\HilosPageConstants;

/**
 * HilosPageCatalog - Identity of the framework's own admin pages.
 *
 * The admin section is framework functionality, so the framework owns its naming and its tree:
 * every Hilos project gets the same admin pages under the same headings, and a project supplies
 * only the view mapped to each key. A project adds its own pages - or renames one of these for
 * the language of its product - through {@see PageCatalogProviderInterface}.
 *
 * Where the identity of a page is the same value in every language, this constant is the whole
 * source. Once i18n lands, a translation is laid over it and this table stays as the fallback,
 * which is why it lives beside the database layer rather than in `Constants/`.
 *
 * Seven routed pages carry no entry on purpose: `hilos_about`, `hilos_terms`, `hilos_privacy`
 * and `hilos_license` are public footer pages rather than admin ones, `hilos_profile` belongs to
 * the signed-in user rather than to the admin tree, and `hilos_guardian` with
 * `hilos_guardian_agent` are the mirror gap HIL-345 answers. A page without an entry is not an
 * error - its subscription simply answers without identity.
 *
 * @see PageCatalogResolver
 */
final class HilosPageCatalog
{
    /**
     * Admin page identity, keyed by page key.
     *
     * The dashboard is the root of the tree and declares no parent; every other entry names one,
     * and the chain from any page up to the dashboard is what the breadcrumb walks. `icon` marks
     * a top-level section, which is the only place a card is drawn.
     *
     * @var array<string, array{label: string, lead: string, parent?: string, icon?: string}> Page identity per page key
     */
    public const array CATALOG = [
        HilosPageConstants::HILOS_DASHBOARD => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Hilos',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Administrative sections for the project.',
        ],

        // — Access & identity —
        HilosPageConstants::HILOS_USERS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Users',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Application users and panel operators: presence, roles, and access.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-people',
        ],
        HilosPageConstants::HILOS_USER => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single user: profile, presence, and account actions.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_USERS,
        ],

        // — Configuration & localization —
        HilosPageConstants::HILOS_ROLES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Roles',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Role catalog and the permission groups assigned to users.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-person-badge',
        ],
        HilosPageConstants::HILOS_I18N => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Internationalization',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Languages, countries, locales, and translation screens.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-translate',
        ],
        HilosPageConstants::HILOS_I18N_LANGUAGES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Languages',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Supported interface languages and their locales.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_LANGUAGE => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Language',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single language: locale settings and status.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_LANGUAGES,
        ],
        HilosPageConstants::HILOS_I18N_COUNTRIES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Countries',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Country catalog used for locale and formatting.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_COUNTRY => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Country',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single country: locale, currency, and formatting.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_COUNTRIES,
        ],
        HilosPageConstants::HILOS_I18N_ENTITIES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Entities',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Translatable domain entities and their fields.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_ENTITY => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate entity',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => "Per-language values for one entity's fields.",
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_ENTITIES,
        ],
        HilosPageConstants::HILOS_I18N_UI_PAGES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'UI pages',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Front-end pages whose strings are translated.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_UI_PAGE => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'UI page',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single UI page and its translatable strings.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_UI_PAGES,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate UI page',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Per-language strings for one UI page.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_UI_PAGES,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate UI page item',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single UI-page string across languages.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE,
        ],
        HilosPageConstants::HILOS_I18N_GROUPS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Groups',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'String groups shared across pages (catalogs).',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_GROUP => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Group',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single string group and its items.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_GROUPS,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate group',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Per-language values for one string group.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_GROUPS,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate group item',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single group string across languages.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP,
        ],
        HilosPageConstants::HILOS_I18N_ACTIONS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Actions',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Action error messages exposed to users.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_ACTION => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Action',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single action and its error catalog.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_ACTIONS,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate action error',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Per-language text for one action error.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_ACTIONS,
        ],
        HilosPageConstants::HILOS_I18N_EMAILS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Emails',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Transactional email templates by language.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N,
        ],
        HilosPageConstants::HILOS_I18N_TRANSLATE_EMAIL => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate email',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Per-language subject and body for one email.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_I18N_EMAILS,
        ],

        // — Product & integrations —
        HilosPageConstants::HILOS_COMMUNICATIONS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Communications',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Email, SMS, push, and chat delivery channels.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-envelope-paper',
        ],
        HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Channel',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single communication channel and its configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_COMMUNICATIONS,
        ],
        HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Deliveries',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Delivery log for one channel.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
        ],
        HilosPageConstants::HILOS_SECURITY => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Security Center',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Two-factor authentication and OAuth login providers.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-shield-lock',
        ],
        HilosPageConstants::HILOS_SECURITY_2FA => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Two-factor',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Two-factor authentication policy and enrollment.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_SECURITY,
        ],
        HilosPageConstants::HILOS_SECURITY_OAUTH => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'OAuth providers',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'External OAuth login providers.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_SECURITY,
        ],
        HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'OAuth provider',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single OAuth provider configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_SECURITY_OAUTH,
        ],
        HilosPageConstants::HILOS_BILLING => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Billing',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Payment providers, payments, and refunds.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-credit-card',
        ],
        HilosPageConstants::HILOS_BILLING_PROVIDER => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Provider',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single payment provider configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_BILLING,
        ],
        HilosPageConstants::HILOS_BILLING_PAYMENTS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Payments',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Payments processed by a provider.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_BILLING_PROVIDER,
        ],
        HilosPageConstants::HILOS_BILLING_REFUNDS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Refunds',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Refunds issued by a provider.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_BILLING_PROVIDER,
        ],

        // — Platform operations —
        HilosPageConstants::HILOS_SETTINGS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Application setting catalog: catalog defaults, overrides, and references.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-sliders',
        ],
        HilosPageConstants::HILOS_OPERATIONS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Operations',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Sitemap generation, static HTML builds, and maintenance tasks.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-diagram-3',
        ],
        HilosPageConstants::HILOS_DAEMON => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Daemon',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Process metrics, workers, cron, websockets, and HTTP servers.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-cpu',
        ],
        HilosPageConstants::HILOS_DAEMON_WORKERS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Workers',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Worker processes and their current load.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DAEMON,
        ],
        HilosPageConstants::HILOS_DAEMON_AGENTS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Agents',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Registered agents and their assignment to workers.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DAEMON,
        ],
        HilosPageConstants::HILOS_DAEMON_CRON => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Cron',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Scheduled cron entries and their last run.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DAEMON,
        ],
        HilosPageConstants::HILOS_DAEMON_WEBSOCKETS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'WebSockets',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Live websocket connections and subscriptions.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DAEMON,
        ],
        HilosPageConstants::HILOS_DAEMON_HTTP_SERVER => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'HTTP server',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Request metrics for a single HTTP server.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DAEMON,
        ],
        HilosPageConstants::HILOS_LOGS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Logs',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Rotation stats, log keys, workers, and the viewer.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-journal-text',
        ],
        HilosPageConstants::HILOS_LOGS_KEYS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By key',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by log key.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_LOGS,
        ],
        HilosPageConstants::HILOS_LOGS_WORKERS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By worker',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by worker.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_LOGS,
        ],
        HilosPageConstants::HILOS_LOGS_ROTATIONS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Rotations',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log rotation history and retention.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_LOGS,
        ],
        HilosPageConstants::HILOS_LOGS_VIEW => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Viewer',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Stream and filter log lines.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_LOGS,
        ],
        HilosPageConstants::HILOS_LOGS_SETTINGS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Logging modes and what differs from the chosen one.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_LOGS,
        ],
        HilosPageConstants::HILOS_CHANGE_LOG => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Change Log',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Audit triggers and change-tracking configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-clock-history',
        ],
        HilosPageConstants::HILOS_CHANGE_LOG_TABLES => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Tracked tables',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Tables with change tracking enabled.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_CHANGE_LOG,
        ],
        HilosPageConstants::HILOS_CHANGE_LOG_TABLE => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Tracked table',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Change history for a single table.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_CHANGE_LOG_TABLES,
        ],
        HilosPageConstants::HILOS_BACKUP => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Backups',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Database and file backup jobs and their history.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-safe2',
        ],

        // — Automation & intelligence —
        HilosPageConstants::HILOS_ANALYTICS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Analytics',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Visit statistics, traffic sources, and engagement metrics.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-bar-chart',
        ],
        HilosPageConstants::HILOS_MCP_SKILLS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP & Skills',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'MCP servers, skills, and their usage logs.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-boxes',
        ],
        HilosPageConstants::HILOS_MCP_SKILLS_MCP => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP server',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single MCP server: tools and configuration.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MCP_SKILLS,
        ],
        HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Usage logs',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Invocation history for one MCP server.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MCP_SKILLS_MCP,
        ],
        HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Log entry',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'A single MCP invocation in detail.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS,
        ],
        HilosPageConstants::HILOS_SIL => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'System Intelligence Layer',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'SIL dashboard, requests, and elevated automation.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-lightning-charge',
        ],
        HilosPageConstants::HILOS_SIL_REQUESTS => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Requests',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Queued and completed SIL requests.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_SIL,
        ],
        HilosPageConstants::HILOS_SIL_USER_HISTORY => [
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User history',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'SIL activity for a single user.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_SIL,
        ],
    ];

    /**
     * Top-level admin sections grouped for the dashboard, in display order.
     *
     * Only a top-level page appears here - the one kind of entry that carries an icon - and the
     * order of the sections and of the items inside them is the order they are drawn in. A
     * project's own sections are appended after these, never woven into them.
     *
     * @var list<array{title: string, description: string, items: list<string>}> Dashboard sections in display order
     */
    public const array DASHBOARD_SECTIONS = [
        [
            PageCatalogConstants::SECTION_TITLE => 'Access & identity',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Users and the roles that grant them panel access.',
            PageCatalogConstants::SECTION_ITEMS => [
                HilosPageConstants::HILOS_USERS,
                HilosPageConstants::HILOS_ROLES,
            ],
        ],
        [
            PageCatalogConstants::SECTION_TITLE => 'Localization',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Languages, countries, and translation screens.',
            PageCatalogConstants::SECTION_ITEMS => [
                HilosPageConstants::HILOS_I18N,
            ],
        ],
        [
            PageCatalogConstants::SECTION_TITLE => 'Product & integrations',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Messaging, identity, and commerce configuration.',
            PageCatalogConstants::SECTION_ITEMS => [
                HilosPageConstants::HILOS_COMMUNICATIONS,
                HilosPageConstants::HILOS_SECURITY,
                HilosPageConstants::HILOS_BILLING,
            ],
        ],
        [
            PageCatalogConstants::SECTION_TITLE => 'Platform operations',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Runtime, maintenance, backups, and observability.',
            PageCatalogConstants::SECTION_ITEMS => [
                HilosPageConstants::HILOS_SETTINGS,
                HilosPageConstants::HILOS_OPERATIONS,
                HilosPageConstants::HILOS_DAEMON,
                HilosPageConstants::HILOS_LOGS,
                HilosPageConstants::HILOS_CHANGE_LOG,
                HilosPageConstants::HILOS_BACKUP,
            ],
        ],
        [
            PageCatalogConstants::SECTION_TITLE => 'Automation & intelligence',
            PageCatalogConstants::SECTION_DESCRIPTION => 'Analytics, AI tooling, and elevated automation.',
            PageCatalogConstants::SECTION_ITEMS => [
                HilosPageConstants::HILOS_ANALYTICS,
                HilosPageConstants::HILOS_MCP_SKILLS,
                HilosPageConstants::HILOS_SIL,
            ],
        ],
    ];
}
