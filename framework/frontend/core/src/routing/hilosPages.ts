// The framework's own page catalog: the keys and cold-load route declarations of
// the Hilos admin section (dashboard, i18n, daemon, logs, users, MCP/skills, SIL,
// communications, security, billing, change log) plus the public content pages
// (about, terms, privacy, license) the application shell links in its footer
// (HILOS_FOOTER_LINKS). These pages are framework functionality, so the
// framework owns their identity and URL layout — a project mounts them by
// merging HILOS_ROUTE_DECLARATIONS into its own route map rather than restating
// them, and supplies the content component mapped to each key. Keys mirror PHP
// `HilosPageConstants` and the paths mirror the framework rows of the page
// catalog; both must stay byte-identical to their backend counterparts (the page
// key is the subscription wire identity, the path is the canonical admin URL).
// Every declaration also states whether its page is an administrative surface,
// which is what keeps a framework page out of the public half of the app.

import { type HilosRouteDeclaration } from './PageRouter.js'

/** Hilos admin page keys, mirroring PHP `HilosPageConstants`. */
export const HilosPages = {
  DASHBOARD: 'hilos',
  PROFILE: 'hilos_profile',
  SETTINGS: 'hilos_settings',
  ANALYTICS: 'hilos_analytics',
  ROLES: 'hilos_roles',
  OPERATIONS: 'hilos_operations',
  BACKUP: 'hilos_backup',
  GUARDIAN: 'hilos_guardian',
  GUARDIAN_AGENT: 'hilos_guardian_agent',
  I18N: 'hilos_i18n',
  I18N_LANGUAGES: 'hilos_i18n_languages',
  I18N_COUNTRIES: 'hilos_i18n_countries',
  I18N_ENTITIES: 'hilos_i18n_entities',
  I18N_UI_PAGES: 'hilos_i18n_ui_pages',
  I18N_GROUPS: 'hilos_i18n_groups',
  I18N_ACTIONS: 'hilos_i18n_actions',
  I18N_EMAILS: 'hilos_i18n_emails',
  I18N_LANGUAGE: 'hilos_i18n_language',
  I18N_COUNTRY: 'hilos_i18n_country',
  I18N_UI_PAGE: 'hilos_i18n_ui_page',
  I18N_GROUP: 'hilos_i18n_group',
  I18N_ACTION: 'hilos_i18n_action',
  I18N_TRANSLATE_ENTITY: 'hilos_i18n_translate_entity',
  I18N_TRANSLATE_UI_PAGE: 'hilos_i18n_translate_ui_page',
  I18N_TRANSLATE_UI_PAGE_ITEM: 'hilos_i18n_translate_ui_page_item',
  I18N_TRANSLATE_GROUP: 'hilos_i18n_translate_group',
  I18N_TRANSLATE_GROUP_ITEM: 'hilos_i18n_translate_group_item',
  I18N_TRANSLATE_ACTION_ERROR: 'hilos_i18n_translate_action_error',
  I18N_TRANSLATE_EMAIL: 'hilos_i18n_translate_email',
  CHANGE_LOG: 'hilos_change_log',
  CHANGE_LOG_TABLES: 'hilos_change_log_tables',
  CHANGE_LOG_TABLE: 'hilos_change_log_table',
  DAEMON: 'hilos_daemon',
  DAEMON_WORKERS: 'hilos_daemon_workers',
  DAEMON_AGENTS: 'hilos_daemon_agents',
  DAEMON_CRON: 'hilos_daemon_cron',
  DAEMON_WEBSOCKETS: 'hilos_daemon_websockets',
  DAEMON_HTTP_SERVER: 'hilos_daemon_http_server',
  LOGS: 'hilos_logs',
  LOGS_KEYS: 'hilos_logs_keys',
  LOGS_WORKERS: 'hilos_logs_workers',
  LOGS_ROTATIONS: 'hilos_logs_rotations',
  LOGS_VIEW: 'hilos_logs_view',
  USERS: 'hilos_users',
  USER: 'hilos_user',
  MCP_SKILLS: 'hilos_mcp_skills',
  MCP_SKILLS_MCP: 'hilos_mcp_skills_mcp',
  MCP_SKILLS_MCP_LOGS: 'hilos_mcp_skills_mcp_logs',
  MCP_SKILLS_MCP_LOGS_VIEW: 'hilos_mcp_skills_mcp_logs_view',
  SIL: 'hilos_sil',
  SIL_REQUESTS: 'hilos_sil_requests',
  SIL_USER_HISTORY: 'hilos_sil_user_history',
  COMMUNICATIONS: 'hilos_communications',
  COMMUNICATIONS_CHANNEL: 'hilos_communications_channel',
  COMMUNICATIONS_DELIVERIES: 'hilos_communications_deliveries',
  SECURITY: 'hilos_security',
  SECURITY_2FA: 'hilos_security_2fa',
  SECURITY_OAUTH: 'hilos_security_oauth',
  SECURITY_OAUTH_PROVIDER: 'hilos_security_oauth_provider',
  BILLING: 'hilos_billing',
  BILLING_PROVIDER: 'hilos_billing_provider',
  BILLING_PAYMENTS: 'hilos_billing_payments',
  BILLING_REFUNDS: 'hilos_billing_refunds',
  ABOUT: 'hilos_about',
  TERMS: 'hilos_terms',
  PRIVACY: 'hilos_privacy',
  LICENSE: 'hilos_license',
} as const

/**
 * Hilos admin page key → route declaration, mirroring the framework rows of
 * the page catalog. `{name}` segments are route params captured at match time,
 * and `admin` states whether the page is an administrative surface — true for
 * the admin section, false for the profile and the public footer pages. A
 * project overlays this with its own declarations to mount the admin.
 */
export const HILOS_ROUTE_DECLARATIONS: Record<string, HilosRouteDeclaration> = {
  [HilosPages.DASHBOARD]: { path: '/hilos', admin: true },
  [HilosPages.PROFILE]: { path: '/profile', admin: false },
  [HilosPages.SETTINGS]: { path: '/hilos/settings', admin: true },
  [HilosPages.ANALYTICS]: { path: '/hilos/analytics', admin: true },
  [HilosPages.ROLES]: { path: '/hilos/roles', admin: true },
  [HilosPages.OPERATIONS]: { path: '/hilos/operations', admin: true },
  [HilosPages.BACKUP]: { path: '/hilos/backup', admin: true },
  [HilosPages.GUARDIAN]: { path: '/hilos/guardian', admin: true },
  [HilosPages.GUARDIAN_AGENT]: {
    path: '/hilos/guardian/{agentId}',
    admin: true,
  },
  [HilosPages.I18N]: { path: '/hilos/i18n', admin: true },
  [HilosPages.I18N_LANGUAGES]: { path: '/hilos/i18n/languages', admin: true },
  [HilosPages.I18N_COUNTRIES]: { path: '/hilos/i18n/countries', admin: true },
  [HilosPages.I18N_ENTITIES]: { path: '/hilos/i18n/entities', admin: true },
  [HilosPages.I18N_UI_PAGES]: { path: '/hilos/i18n/ui-pages', admin: true },
  [HilosPages.I18N_GROUPS]: { path: '/hilos/i18n/groups', admin: true },
  [HilosPages.I18N_ACTIONS]: { path: '/hilos/i18n/actions', admin: true },
  [HilosPages.I18N_EMAILS]: { path: '/hilos/i18n/emails', admin: true },
  [HilosPages.I18N_LANGUAGE]: {
    path: '/hilos/i18n/languages/{languageId}',
    admin: true,
  },
  [HilosPages.I18N_COUNTRY]: {
    path: '/hilos/i18n/countries/{countryId}',
    admin: true,
  },
  [HilosPages.I18N_UI_PAGE]: {
    path: '/hilos/i18n/ui-pages/{uiPageId}',
    admin: true,
  },
  [HilosPages.I18N_GROUP]: {
    path: '/hilos/i18n/groups/{groupId}',
    admin: true,
  },
  [HilosPages.I18N_ACTION]: {
    path: '/hilos/i18n/actions/{actionId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_ENTITY]: {
    path: '/hilos/i18n/translate/entity/{entityId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: {
    path: '/hilos/i18n/translate/ui-page/{uiPageId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: {
    path: '/hilos/i18n/translate/ui-page/{uiPageId}/item/{itemId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_GROUP]: {
    path: '/hilos/i18n/translate/group/{groupId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: {
    path: '/hilos/i18n/translate/group/{groupId}/item/{itemId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: {
    path: '/hilos/i18n/translate/action/{actionId}/error/{errorId}',
    admin: true,
  },
  [HilosPages.I18N_TRANSLATE_EMAIL]: {
    path: '/hilos/i18n/translate/email/{emailId}',
    admin: true,
  },
  [HilosPages.CHANGE_LOG]: { path: '/hilos/change-log', admin: true },
  [HilosPages.CHANGE_LOG_TABLES]: {
    path: '/hilos/change-log/tables',
    admin: true,
  },
  [HilosPages.CHANGE_LOG_TABLE]: {
    path: '/hilos/change-log/tables/{tableId}',
    admin: true,
  },
  [HilosPages.DAEMON]: { path: '/hilos/daemon', admin: true },
  [HilosPages.DAEMON_WORKERS]: { path: '/hilos/daemon/workers', admin: true },
  [HilosPages.DAEMON_AGENTS]: { path: '/hilos/daemon/agents', admin: true },
  [HilosPages.DAEMON_CRON]: { path: '/hilos/daemon/cron', admin: true },
  [HilosPages.DAEMON_WEBSOCKETS]: {
    path: '/hilos/daemon/websockets',
    admin: true,
  },
  [HilosPages.DAEMON_HTTP_SERVER]: {
    path: '/hilos/daemon/http/{serverId}',
    admin: true,
  },
  [HilosPages.LOGS]: { path: '/hilos/logs', admin: true },
  [HilosPages.LOGS_KEYS]: { path: '/hilos/logs/keys', admin: true },
  [HilosPages.LOGS_WORKERS]: { path: '/hilos/logs/workers', admin: true },
  [HilosPages.LOGS_ROTATIONS]: { path: '/hilos/logs/rotations', admin: true },
  [HilosPages.LOGS_VIEW]: {
    path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
    admin: true,
  },
  [HilosPages.USERS]: { path: '/hilos/users', admin: true },
  [HilosPages.USER]: { path: '/hilos/user/{userId}', admin: true },
  [HilosPages.MCP_SKILLS]: { path: '/hilos/mcp-skills', admin: true },
  [HilosPages.MCP_SKILLS_MCP]: {
    path: '/hilos/mcp-skills/{mcpId}',
    admin: true,
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS]: {
    path: '/hilos/mcp-skills/{mcpId}/logs',
    admin: true,
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: {
    path: '/hilos/mcp-skills/{mcpId}/logs/view',
    admin: true,
  },
  [HilosPages.SIL]: { path: '/hilos/sil', admin: true },
  [HilosPages.SIL_REQUESTS]: { path: '/hilos/sil/requests', admin: true },
  [HilosPages.SIL_USER_HISTORY]: {
    path: '/hilos/sil/users/{userId}',
    admin: true,
  },
  [HilosPages.COMMUNICATIONS]: { path: '/hilos/communications', admin: true },
  [HilosPages.COMMUNICATIONS_CHANNEL]: {
    path: '/hilos/communications/{channelId}',
    admin: true,
  },
  [HilosPages.COMMUNICATIONS_DELIVERIES]: {
    path: '/hilos/communications/{channelId}/deliveries',
    admin: true,
  },
  [HilosPages.SECURITY]: { path: '/hilos/security', admin: true },
  [HilosPages.SECURITY_2FA]: { path: '/hilos/security/2fa', admin: true },
  [HilosPages.SECURITY_OAUTH]: { path: '/hilos/security/oauth', admin: true },
  [HilosPages.SECURITY_OAUTH_PROVIDER]: {
    path: '/hilos/security/oauth/{providerId}',
    admin: true,
  },
  [HilosPages.BILLING]: { path: '/hilos/billing', admin: true },
  [HilosPages.BILLING_PROVIDER]: {
    path: '/hilos/billing/{providerId}',
    admin: true,
  },
  [HilosPages.BILLING_PAYMENTS]: {
    path: '/hilos/billing/{providerId}/payments',
    admin: true,
  },
  [HilosPages.BILLING_REFUNDS]: {
    path: '/hilos/billing/{providerId}/refunds',
    admin: true,
  },
  [HilosPages.ABOUT]: { path: '/about', admin: false },
  [HilosPages.TERMS]: { path: '/terms', admin: false },
  [HilosPages.PRIVACY]: { path: '/privacy', admin: false },
  [HilosPages.LICENSE]: { path: '/license', admin: false },
}

/**
 * Hilos admin page key → cold-load URL template, derived from
 * `HILOS_ROUTE_DECLARATIONS`. Its readers — the shell's admin gear and footer
 * hrefs, breadcrumb path resolution, the demos' user-detail links, the
 * prerender entries — resolve a page to its URL and ask nothing about the
 * surface type, so the paths stay a map of their own rather than making every
 * one of those call sites read a field it does not use.
 */
export const HILOS_PAGE_ROUTES: Record<string, string> = Object.fromEntries(
  Object.entries(HILOS_ROUTE_DECLARATIONS).map(([page, declaration]) => [
    page,
    declaration.path,
  ]),
)

/** A public framework page surfaced in the application footer. */
export interface HilosFooterLink {
  /** The page key, a `HilosPages` value resolved through `HILOS_PAGE_ROUTES`. */
  page: string
  /** The visible link text. */
  label: string
}

/**
 * The public framework pages shown in the application footer, in order. The
 * framework owns this set so every project's footer offers the same legal and
 * informational links; a project supplies only each page's content component
 * (mapped under the matching `HilosPages` key). Each page is static and routes
 * to its `HILOS_PAGE_ROUTES` template with no params.
 */
export const HILOS_FOOTER_LINKS: readonly HilosFooterLink[] = [
  { page: HilosPages.ABOUT, label: 'About' },
  { page: HilosPages.TERMS, label: 'Terms' },
  { page: HilosPages.PRIVACY, label: 'Privacy' },
  { page: HilosPages.LICENSE, label: 'License' },
]

/** A Hilos admin page's label and its place in the admin navigation tree. */
export interface HilosAdminPageMeta {
  /** Heading shown on the page and as its breadcrumb label. */
  label: string
  /** One-line description under the heading and on the dashboard card. */
  lead: string
  /**
   * Parent page key in the admin tree; absent for a top-level section (whose
   * parent is the dashboard implicitly — the dashboard is the tree root).
   */
  parent?: string
  /** Bootstrap icon (`bi-*`) for the dashboard card; top-level sections only. */
  icon?: string
}

/**
 * The admin section's navigation tree: each Hilos admin page's label, lead, and
 * parent. This is framework functionality, so the framework owns the section's
 * shape and naming — every Hilos project gets the same admin tree, and a project
 * supplies only the content component mapped to each key (the same ownership
 * split as `HILOS_FOOTER_LINKS`). It is a catalog of page *identity*, the
 * companion of `HILOS_PAGE_ROUTES`, not a place for any page's render logic —
 * those live one-per-file in the project's `views/` (see the frontend
 * page-module-structure rule). Children are derived by reverse lookup, the
 * breadcrumb by walking `parent` (see `hilosAdmin.ts`).
 */
export const HILOS_ADMIN_PAGES: Record<string, HilosAdminPageMeta> = {
  [HilosPages.DASHBOARD]: {
    label: 'Hilos',
    lead: 'Administrative sections for the project.',
  },

  // — Access & identity —
  [HilosPages.USERS]: {
    label: 'Users',
    lead: 'Application users and panel operators: presence, roles, and access.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-people',
  },
  [HilosPages.USER]: {
    label: 'User',
    lead: 'A single user: profile, presence, and account actions.',
    parent: HilosPages.USERS,
  },

  // — Configuration & localization —
  [HilosPages.ROLES]: {
    label: 'Roles',
    lead: 'Role catalog and the permission groups assigned to users.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-person-badge',
  },
  [HilosPages.I18N]: {
    label: 'Internationalization',
    lead: 'Languages, countries, locales, and translation screens.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-translate',
  },
  [HilosPages.I18N_LANGUAGES]: {
    label: 'Languages',
    lead: 'Supported interface languages and their locales.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_LANGUAGE]: {
    label: 'Language',
    lead: 'A single language: locale settings and status.',
    parent: HilosPages.I18N_LANGUAGES,
  },
  [HilosPages.I18N_COUNTRIES]: {
    label: 'Countries',
    lead: 'Country catalog used for locale and formatting.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_COUNTRY]: {
    label: 'Country',
    lead: 'A single country: locale, currency, and formatting.',
    parent: HilosPages.I18N_COUNTRIES,
  },
  [HilosPages.I18N_ENTITIES]: {
    label: 'Entities',
    lead: 'Translatable domain entities and their fields.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_TRANSLATE_ENTITY]: {
    label: 'Translate entity',
    lead: "Per-language values for one entity's fields.",
    parent: HilosPages.I18N_ENTITIES,
  },
  [HilosPages.I18N_UI_PAGES]: {
    label: 'UI pages',
    lead: 'Front-end pages whose strings are translated.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_UI_PAGE]: {
    label: 'UI page',
    lead: 'A single UI page and its translatable strings.',
    parent: HilosPages.I18N_UI_PAGES,
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: {
    label: 'Translate UI page',
    lead: 'Per-language strings for one UI page.',
    parent: HilosPages.I18N_UI_PAGES,
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: {
    label: 'Translate UI page item',
    lead: 'A single UI-page string across languages.',
    parent: HilosPages.I18N_TRANSLATE_UI_PAGE,
  },
  [HilosPages.I18N_GROUPS]: {
    label: 'Groups',
    lead: 'String groups shared across pages (catalogs).',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_GROUP]: {
    label: 'Group',
    lead: 'A single string group and its items.',
    parent: HilosPages.I18N_GROUPS,
  },
  [HilosPages.I18N_TRANSLATE_GROUP]: {
    label: 'Translate group',
    lead: 'Per-language values for one string group.',
    parent: HilosPages.I18N_GROUPS,
  },
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: {
    label: 'Translate group item',
    lead: 'A single group string across languages.',
    parent: HilosPages.I18N_TRANSLATE_GROUP,
  },
  [HilosPages.I18N_ACTIONS]: {
    label: 'Actions',
    lead: 'Action error messages exposed to users.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_ACTION]: {
    label: 'Action',
    lead: 'A single action and its error catalog.',
    parent: HilosPages.I18N_ACTIONS,
  },
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: {
    label: 'Translate action error',
    lead: 'Per-language text for one action error.',
    parent: HilosPages.I18N_ACTIONS,
  },
  [HilosPages.I18N_EMAILS]: {
    label: 'Emails',
    lead: 'Transactional email templates by language.',
    parent: HilosPages.I18N,
  },
  [HilosPages.I18N_TRANSLATE_EMAIL]: {
    label: 'Translate email',
    lead: 'Per-language subject and body for one email.',
    parent: HilosPages.I18N_EMAILS,
  },

  // — Product & integrations —
  [HilosPages.COMMUNICATIONS]: {
    label: 'Communications',
    lead: 'Email, SMS, push, and chat delivery channels.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-envelope-paper',
  },
  [HilosPages.COMMUNICATIONS_CHANNEL]: {
    label: 'Channel',
    lead: 'A single communication channel and its configuration.',
    parent: HilosPages.COMMUNICATIONS,
  },
  [HilosPages.COMMUNICATIONS_DELIVERIES]: {
    label: 'Deliveries',
    lead: 'Delivery log for one channel.',
    parent: HilosPages.COMMUNICATIONS_CHANNEL,
  },
  [HilosPages.SECURITY]: {
    label: 'Security Center',
    lead: 'Two-factor authentication and OAuth login providers.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-shield-lock',
  },
  [HilosPages.SECURITY_2FA]: {
    label: 'Two-factor',
    lead: 'Two-factor authentication policy and enrollment.',
    parent: HilosPages.SECURITY,
  },
  [HilosPages.SECURITY_OAUTH]: {
    label: 'OAuth providers',
    lead: 'External OAuth login providers.',
    parent: HilosPages.SECURITY,
  },
  [HilosPages.SECURITY_OAUTH_PROVIDER]: {
    label: 'OAuth provider',
    lead: 'A single OAuth provider configuration.',
    parent: HilosPages.SECURITY_OAUTH,
  },
  [HilosPages.BILLING]: {
    label: 'Billing',
    lead: 'Payment providers, payments, and refunds.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-credit-card',
  },
  [HilosPages.BILLING_PROVIDER]: {
    label: 'Provider',
    lead: 'A single payment provider configuration.',
    parent: HilosPages.BILLING,
  },
  [HilosPages.BILLING_PAYMENTS]: {
    label: 'Payments',
    lead: 'Payments processed by a provider.',
    parent: HilosPages.BILLING_PROVIDER,
  },
  [HilosPages.BILLING_REFUNDS]: {
    label: 'Refunds',
    lead: 'Refunds issued by a provider.',
    parent: HilosPages.BILLING_PROVIDER,
  },

  // — Platform operations —
  [HilosPages.SETTINGS]: {
    label: 'Settings',
    lead: 'Application setting catalog: catalog defaults, overrides, and references.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-sliders',
  },
  [HilosPages.OPERATIONS]: {
    label: 'Operations',
    lead: 'Sitemap generation, static HTML builds, and maintenance tasks.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-diagram-3',
  },
  [HilosPages.DAEMON]: {
    label: 'Daemon',
    lead: 'Process metrics, workers, cron, websockets, and HTTP servers.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-cpu',
  },
  [HilosPages.DAEMON_WORKERS]: {
    label: 'Workers',
    lead: 'Worker processes and their current load.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_AGENTS]: {
    label: 'Agents',
    lead: 'Registered agents and their assignment to workers.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_CRON]: {
    label: 'Cron',
    lead: 'Scheduled cron entries and their last run.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_WEBSOCKETS]: {
    label: 'WebSockets',
    lead: 'Live websocket connections and subscriptions.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_HTTP_SERVER]: {
    label: 'HTTP server',
    lead: 'Request metrics for a single HTTP server.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.LOGS]: {
    label: 'Logs',
    lead: 'Rotation stats, log keys, workers, and the viewer.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-journal-text',
  },
  [HilosPages.LOGS_KEYS]: {
    label: 'By key',
    lead: 'Log volume grouped by log key.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_WORKERS]: {
    label: 'By worker',
    lead: 'Log volume grouped by worker.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_ROTATIONS]: {
    label: 'Rotations',
    lead: 'Log rotation history and retention.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_VIEW]: {
    label: 'Viewer',
    lead: 'Stream and filter log lines.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.CHANGE_LOG]: {
    label: 'Change Log',
    lead: 'Audit triggers and change-tracking configuration.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-clock-history',
  },
  [HilosPages.CHANGE_LOG_TABLES]: {
    label: 'Tracked tables',
    lead: 'Tables with change tracking enabled.',
    parent: HilosPages.CHANGE_LOG,
  },
  [HilosPages.CHANGE_LOG_TABLE]: {
    label: 'Tracked table',
    lead: 'Change history for a single table.',
    parent: HilosPages.CHANGE_LOG_TABLES,
  },
  [HilosPages.BACKUP]: {
    label: 'Backups',
    lead: 'Database and file backup jobs and their history.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-safe2',
  },

  // — Automation & intelligence —
  [HilosPages.ANALYTICS]: {
    label: 'Analytics',
    lead: 'Visit statistics, traffic sources, and engagement metrics.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-bar-chart',
  },
  [HilosPages.MCP_SKILLS]: {
    label: 'MCP & Skills',
    lead: 'MCP servers, skills, and their usage logs.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-boxes',
  },
  [HilosPages.MCP_SKILLS_MCP]: {
    label: 'MCP server',
    lead: 'A single MCP server: tools and configuration.',
    parent: HilosPages.MCP_SKILLS,
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS]: {
    label: 'Usage logs',
    lead: 'Invocation history for one MCP server.',
    parent: HilosPages.MCP_SKILLS_MCP,
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: {
    label: 'Log entry',
    lead: 'A single MCP invocation in detail.',
    parent: HilosPages.MCP_SKILLS_MCP_LOGS,
  },
  [HilosPages.SIL]: {
    label: 'System Intelligence Layer',
    lead: 'SIL dashboard, requests, and elevated automation.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-lightning-charge',
  },
  [HilosPages.SIL_REQUESTS]: {
    label: 'Requests',
    lead: 'Queued and completed SIL requests.',
    parent: HilosPages.SIL,
  },
  [HilosPages.SIL_USER_HISTORY]: {
    label: 'User history',
    lead: 'SIL activity for a single user.',
    parent: HilosPages.SIL,
  },
}

/** A labelled group of top-level admin sections on the dashboard. */
export interface HilosAdminDashboardSection {
  /** Group heading. */
  title: string
  /** One-line group description. */
  description: string
  /** Top-level page keys shown as cards in this group, in order. */
  items: string[]
}

/**
 * The top-level admin sections grouped for the dashboard, in display order. Like
 * `HILOS_ADMIN_PAGES`, this is framework-owned admin structure (every Hilos
 * project's dashboard groups the same way); a project's dashboard view reads it
 * and resolves each item through `HILOS_ADMIN_PAGES` / `HILOS_PAGE_ROUTES`.
 */
export const HILOS_ADMIN_DASHBOARD_SECTIONS: readonly HilosAdminDashboardSection[] =
  [
    {
      title: 'Access & identity',
      description: 'Users and the roles that grant them panel access.',
      items: [HilosPages.USERS, HilosPages.ROLES],
    },
    {
      title: 'Localization',
      description: 'Languages, countries, and translation screens.',
      items: [HilosPages.I18N],
    },
    {
      title: 'Product & integrations',
      description: 'Messaging, identity, and commerce configuration.',
      items: [
        HilosPages.COMMUNICATIONS,
        HilosPages.SECURITY,
        HilosPages.BILLING,
      ],
    },
    {
      title: 'Platform operations',
      description: 'Runtime, maintenance, backups, and observability.',
      items: [
        HilosPages.SETTINGS,
        HilosPages.OPERATIONS,
        HilosPages.DAEMON,
        HilosPages.LOGS,
        HilosPages.CHANGE_LOG,
        HilosPages.BACKUP,
      ],
    },
    {
      title: 'Automation & intelligence',
      description: 'Analytics, AI tooling, and elevated automation.',
      items: [HilosPages.ANALYTICS, HilosPages.MCP_SKILLS, HilosPages.SIL],
    },
  ]
