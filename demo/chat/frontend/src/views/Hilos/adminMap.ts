// The chat demo's stub map of the Hilos admin section (the `/hilos` area).
//
// The framework owns the admin page keys and their cold-load URLs
// (@hilos/core `HilosPages` / `HILOS_PAGE_ROUTES`); this file supplies the
// project-side content for the pages the demo stubs — a title, a one-line lead,
// the parent (for breadcrumbs) and the children (for in-page sub-navigation) of
// each page, plus a dashboard grouping. It is deliberately one data table rather
// than ~56 near-identical view files: every stub renders through the single
// `HilosAdminStub` component, so the whole admin tree lives here and a section
// graduates to a real page by mapping its key to a dedicated component instead.
//
// Out of scope on purpose: `settings`, `users`/`user`, and `guardian`/
// `guardian_agent` already have real backend implementations and get their own
// frontend pages, so they are neither stubbed here nor advertised on the
// dashboard. The static public pages (about/terms/privacy/licence) and the
// dashboard itself have their own components.
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'

/** A stubbed Hilos admin page's project-side content and place in the tree. */
export interface AdminPageMeta {
  /** Heading shown on the page and as its breadcrumb label. */
  title: string
  /** One-line description under the heading. */
  lead: string
  /** Parent page key; absent for a top-level section (the dashboard is root). */
  parent?: string
  /** Child page keys rendered as sub-navigation cards. */
  children?: string[]
  /** Bootstrap icon (`bi-*`) for the dashboard card; top-level sections only. */
  icon?: string
}

/** A labelled group of top-level sections on the admin dashboard. */
export interface AdminDashboardSection {
  /** Group heading. */
  title: string
  /** One-line group description. */
  description: string
  /** Top-level page keys shown as cards in this group. */
  items: string[]
}

/** A resolved breadcrumb entry: a label and the path it navigates to. */
export interface AdminCrumb {
  label: string
  to: string
}

/** A resolved sub-navigation card: a child page's label, lead, and path. */
export interface AdminChildLink {
  page: string
  title: string
  lead: string
  to: string
}

/**
 * Example route params for the parametrized admin pages, used to build a
 * concrete link when the current route does not already carry the param (e.g.
 * the link from the providers hub to a sample provider). The real pages resolve
 * these from data; a stub only needs a reachable example.
 */
export const ADMIN_SAMPLE_PARAMS: Record<string, Record<string, string>> = {
  [HilosPages.I18N_LANGUAGE]: { languageId: 'en' },
  [HilosPages.I18N_COUNTRY]: { countryId: 'us' },
  [HilosPages.I18N_UI_PAGE]: { uiPageId: 'home' },
  [HilosPages.I18N_GROUP]: { groupId: 'common' },
  [HilosPages.I18N_ACTION]: { actionId: 'login' },
  [HilosPages.I18N_TRANSLATE_ENTITY]: { entityId: 'product' },
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: { uiPageId: 'home' },
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: { uiPageId: 'home', itemId: 'title' },
  [HilosPages.I18N_TRANSLATE_GROUP]: { groupId: 'common' },
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: { groupId: 'common', itemId: 'save' },
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: {
    actionId: 'login',
    errorId: 'invalid',
  },
  [HilosPages.I18N_TRANSLATE_EMAIL]: { emailId: 'welcome' },
  [HilosPages.CHANGE_LOG_TABLE]: { tableId: 'users' },
  [HilosPages.DAEMON_HTTP_SERVER]: { serverId: 'web' },
  [HilosPages.MCP_SKILLS_MCP]: { mcpId: 'filesystem' },
  [HilosPages.MCP_SKILLS_MCP_LOGS]: { mcpId: 'filesystem' },
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: { mcpId: 'filesystem' },
  [HilosPages.SIL_USER_HISTORY]: { userId: '1' },
  [HilosPages.COMMUNICATIONS_CHANNEL]: { channelId: 'email' },
  [HilosPages.COMMUNICATIONS_DELIVERIES]: { channelId: 'email' },
  [HilosPages.SECURITY_OAUTH_PROVIDER]: { providerId: 'google' },
  [HilosPages.BILLING_PROVIDER]: { providerId: 'stripe' },
  [HilosPages.BILLING_PAYMENTS]: { providerId: 'stripe' },
  [HilosPages.BILLING_REFUNDS]: { providerId: 'stripe' },
}

/**
 * Every stubbed Hilos admin page: its content and its place in the tree. The
 * keys are the framework `HilosPages` values, so `HILOS_PAGE_ROUTES` resolves
 * each one's URL. Top-level sections set `parent` to the dashboard and carry an
 * `icon`; deeper pages point at their parent and list their children.
 */
export const ADMIN_PAGES: Record<string, AdminPageMeta> = {
  // — Configuration & localization —
  [HilosPages.ROLES]: {
    title: 'Roles',
    lead: 'Role catalog and the permission groups assigned to users.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-person-badge',
  },
  [HilosPages.I18N]: {
    title: 'Internationalization',
    lead: 'Languages, countries, locales, and translation screens.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-translate',
    children: [
      HilosPages.I18N_LANGUAGES,
      HilosPages.I18N_COUNTRIES,
      HilosPages.I18N_ENTITIES,
      HilosPages.I18N_UI_PAGES,
      HilosPages.I18N_GROUPS,
      HilosPages.I18N_ACTIONS,
      HilosPages.I18N_EMAILS,
    ],
  },
  [HilosPages.I18N_LANGUAGES]: {
    title: 'Languages',
    lead: 'Supported interface languages and their locales.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_LANGUAGE],
  },
  [HilosPages.I18N_LANGUAGE]: {
    title: 'Language',
    lead: 'A single language: locale settings and status.',
    parent: HilosPages.I18N_LANGUAGES,
  },
  [HilosPages.I18N_COUNTRIES]: {
    title: 'Countries',
    lead: 'Country catalog used for locale and formatting.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_COUNTRY],
  },
  [HilosPages.I18N_COUNTRY]: {
    title: 'Country',
    lead: 'A single country: locale, currency, and formatting.',
    parent: HilosPages.I18N_COUNTRIES,
  },
  [HilosPages.I18N_ENTITIES]: {
    title: 'Entities',
    lead: 'Translatable domain entities and their fields.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_TRANSLATE_ENTITY],
  },
  [HilosPages.I18N_TRANSLATE_ENTITY]: {
    title: 'Translate entity',
    lead: "Per-language values for one entity's fields.",
    parent: HilosPages.I18N_ENTITIES,
  },
  [HilosPages.I18N_UI_PAGES]: {
    title: 'UI pages',
    lead: 'Front-end pages whose strings are translated.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_UI_PAGE, HilosPages.I18N_TRANSLATE_UI_PAGE],
  },
  [HilosPages.I18N_UI_PAGE]: {
    title: 'UI page',
    lead: 'A single UI page and its translatable strings.',
    parent: HilosPages.I18N_UI_PAGES,
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: {
    title: 'Translate UI page',
    lead: 'Per-language strings for one UI page.',
    parent: HilosPages.I18N_UI_PAGES,
    children: [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM],
  },
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: {
    title: 'Translate UI page item',
    lead: 'A single UI-page string across languages.',
    parent: HilosPages.I18N_TRANSLATE_UI_PAGE,
  },
  [HilosPages.I18N_GROUPS]: {
    title: 'Groups',
    lead: 'String groups shared across pages (catalogs).',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_GROUP, HilosPages.I18N_TRANSLATE_GROUP],
  },
  [HilosPages.I18N_GROUP]: {
    title: 'Group',
    lead: 'A single string group and its items.',
    parent: HilosPages.I18N_GROUPS,
  },
  [HilosPages.I18N_TRANSLATE_GROUP]: {
    title: 'Translate group',
    lead: 'Per-language values for one string group.',
    parent: HilosPages.I18N_GROUPS,
    children: [HilosPages.I18N_TRANSLATE_GROUP_ITEM],
  },
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: {
    title: 'Translate group item',
    lead: 'A single group string across languages.',
    parent: HilosPages.I18N_TRANSLATE_GROUP,
  },
  [HilosPages.I18N_ACTIONS]: {
    title: 'Actions',
    lead: 'Action error messages exposed to users.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_ACTION, HilosPages.I18N_TRANSLATE_ACTION_ERROR],
  },
  [HilosPages.I18N_ACTION]: {
    title: 'Action',
    lead: 'A single action and its error catalog.',
    parent: HilosPages.I18N_ACTIONS,
  },
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: {
    title: 'Translate action error',
    lead: 'Per-language text for one action error.',
    parent: HilosPages.I18N_ACTIONS,
  },
  [HilosPages.I18N_EMAILS]: {
    title: 'Emails',
    lead: 'Transactional email templates by language.',
    parent: HilosPages.I18N,
    children: [HilosPages.I18N_TRANSLATE_EMAIL],
  },
  [HilosPages.I18N_TRANSLATE_EMAIL]: {
    title: 'Translate email',
    lead: 'Per-language subject and body for one email.',
    parent: HilosPages.I18N_EMAILS,
  },

  // — Product & integrations —
  [HilosPages.COMMUNICATIONS]: {
    title: 'Communications',
    lead: 'Email, SMS, push, and chat delivery channels.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-envelope-paper',
    children: [HilosPages.COMMUNICATIONS_CHANNEL],
  },
  [HilosPages.COMMUNICATIONS_CHANNEL]: {
    title: 'Channel',
    lead: 'A single communication channel and its configuration.',
    parent: HilosPages.COMMUNICATIONS,
    children: [HilosPages.COMMUNICATIONS_DELIVERIES],
  },
  [HilosPages.COMMUNICATIONS_DELIVERIES]: {
    title: 'Deliveries',
    lead: 'Delivery log for one channel.',
    parent: HilosPages.COMMUNICATIONS_CHANNEL,
  },
  [HilosPages.SECURITY]: {
    title: 'Security Center',
    lead: 'Two-factor authentication and OAuth login providers.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-shield-lock',
    children: [HilosPages.SECURITY_2FA, HilosPages.SECURITY_OAUTH],
  },
  [HilosPages.SECURITY_2FA]: {
    title: 'Two-factor',
    lead: 'Two-factor authentication policy and enrollment.',
    parent: HilosPages.SECURITY,
  },
  [HilosPages.SECURITY_OAUTH]: {
    title: 'OAuth providers',
    lead: 'External OAuth login providers.',
    parent: HilosPages.SECURITY,
    children: [HilosPages.SECURITY_OAUTH_PROVIDER],
  },
  [HilosPages.SECURITY_OAUTH_PROVIDER]: {
    title: 'OAuth provider',
    lead: 'A single OAuth provider configuration.',
    parent: HilosPages.SECURITY_OAUTH,
  },
  [HilosPages.BILLING]: {
    title: 'Billing',
    lead: 'Payment providers, payments, and refunds.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-credit-card',
    children: [HilosPages.BILLING_PROVIDER],
  },
  [HilosPages.BILLING_PROVIDER]: {
    title: 'Provider',
    lead: 'A single payment provider configuration.',
    parent: HilosPages.BILLING,
    children: [HilosPages.BILLING_PAYMENTS, HilosPages.BILLING_REFUNDS],
  },
  [HilosPages.BILLING_PAYMENTS]: {
    title: 'Payments',
    lead: 'Payments processed by a provider.',
    parent: HilosPages.BILLING_PROVIDER,
  },
  [HilosPages.BILLING_REFUNDS]: {
    title: 'Refunds',
    lead: 'Refunds issued by a provider.',
    parent: HilosPages.BILLING_PROVIDER,
  },

  // — Platform operations —
  [HilosPages.OPERATIONS]: {
    title: 'Operations',
    lead: 'Sitemap generation, static HTML builds, and maintenance tasks.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-diagram-3',
  },
  [HilosPages.DAEMON]: {
    title: 'Daemon',
    lead: 'Process metrics, workers, cron, websockets, and HTTP servers.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-cpu',
    children: [
      HilosPages.DAEMON_WORKERS,
      HilosPages.DAEMON_AGENTS,
      HilosPages.DAEMON_CRON,
      HilosPages.DAEMON_WEBSOCKETS,
      HilosPages.DAEMON_HTTP_SERVER,
    ],
  },
  [HilosPages.DAEMON_WORKERS]: {
    title: 'Workers',
    lead: 'Worker processes and their current load.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_AGENTS]: {
    title: 'Agents',
    lead: 'Registered agents and their assignment to workers.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_CRON]: {
    title: 'Cron',
    lead: 'Scheduled cron entries and their last run.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_WEBSOCKETS]: {
    title: 'WebSockets',
    lead: 'Live websocket connections and subscriptions.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.DAEMON_HTTP_SERVER]: {
    title: 'HTTP server',
    lead: 'Request metrics for a single HTTP server.',
    parent: HilosPages.DAEMON,
  },
  [HilosPages.LOGS]: {
    title: 'Logs',
    lead: 'Rotation stats, log keys, workers, and the viewer.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-journal-text',
    children: [
      HilosPages.LOGS_KEYS,
      HilosPages.LOGS_WORKERS,
      HilosPages.LOGS_ROTATIONS,
      HilosPages.LOGS_VIEW,
    ],
  },
  [HilosPages.LOGS_KEYS]: {
    title: 'By key',
    lead: 'Log volume grouped by log key.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_WORKERS]: {
    title: 'By worker',
    lead: 'Log volume grouped by worker.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_ROTATIONS]: {
    title: 'Rotations',
    lead: 'Log rotation history and retention.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.LOGS_VIEW]: {
    title: 'Viewer',
    lead: 'Stream and filter log lines.',
    parent: HilosPages.LOGS,
  },
  [HilosPages.CHANGE_LOG]: {
    title: 'Change Log',
    lead: 'Audit triggers and change-tracking configuration.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-clock-history',
    children: [HilosPages.CHANGE_LOG_TABLES],
  },
  [HilosPages.CHANGE_LOG_TABLES]: {
    title: 'Tracked tables',
    lead: 'Tables with change tracking enabled.',
    parent: HilosPages.CHANGE_LOG,
    children: [HilosPages.CHANGE_LOG_TABLE],
  },
  [HilosPages.CHANGE_LOG_TABLE]: {
    title: 'Tracked table',
    lead: 'Change history for a single table.',
    parent: HilosPages.CHANGE_LOG_TABLES,
  },
  [HilosPages.BACKUP]: {
    title: 'Backups',
    lead: 'Database and file backup jobs and their history.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-safe2',
  },

  // — Automation & intelligence —
  [HilosPages.ANALYTICS]: {
    title: 'Analytics',
    lead: 'Visit statistics, traffic sources, and engagement metrics.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-bar-chart',
  },
  [HilosPages.MCP_SKILLS]: {
    title: 'MCP & Skills',
    lead: 'MCP servers, skills, and their usage logs.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-boxes',
    children: [HilosPages.MCP_SKILLS_MCP],
  },
  [HilosPages.MCP_SKILLS_MCP]: {
    title: 'MCP server',
    lead: 'A single MCP server: tools and configuration.',
    parent: HilosPages.MCP_SKILLS,
    children: [HilosPages.MCP_SKILLS_MCP_LOGS],
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS]: {
    title: 'Usage logs',
    lead: 'Invocation history for one MCP server.',
    parent: HilosPages.MCP_SKILLS_MCP,
    children: [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW],
  },
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: {
    title: 'Log entry',
    lead: 'A single MCP invocation in detail.',
    parent: HilosPages.MCP_SKILLS_MCP_LOGS,
  },
  [HilosPages.SIL]: {
    title: 'System Intelligence Layer',
    lead: 'SIL dashboard, requests, and elevated automation.',
    parent: HilosPages.DASHBOARD,
    icon: 'bi-lightning-charge',
    children: [HilosPages.SIL_REQUESTS, HilosPages.SIL_USER_HISTORY],
  },
  [HilosPages.SIL_REQUESTS]: {
    title: 'Requests',
    lead: 'Queued and completed SIL requests.',
    parent: HilosPages.SIL,
  },
  [HilosPages.SIL_USER_HISTORY]: {
    title: 'User history',
    lead: 'SIL activity for a single user.',
    parent: HilosPages.SIL,
  },
}

/** The top-level sections grouped for the admin dashboard, in display order. */
export const ADMIN_DASHBOARD_SECTIONS: AdminDashboardSection[] = [
  {
    title: 'Configuration & localization',
    description: 'Roles and internationalization for the project.',
    items: [HilosPages.ROLES, HilosPages.I18N],
  },
  {
    title: 'Product & integrations',
    description: 'Messaging, identity, and commerce configuration.',
    items: [HilosPages.COMMUNICATIONS, HilosPages.SECURITY, HilosPages.BILLING],
  },
  {
    title: 'Platform operations',
    description: 'Runtime, maintenance, backups, and observability.',
    items: [
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

/** The page keys every stub page renders through `HilosAdminStub` under. */
export const ADMIN_STUB_KEYS: string[] = Object.keys(ADMIN_PAGES)

/** The breadcrumb root: the Hilos dashboard, ancestor of every admin page. */
const ADMIN_ROOT_CRUMB: AdminCrumb = {
  label: 'Hilos',
  to: HILOS_PAGE_ROUTES[HilosPages.DASHBOARD],
}

/**
 * Resolve an admin page's URL, filling each `{param}` from the current route
 * params first, then the page's sample params, then a generic placeholder — so
 * a link is always concrete even before real data exists.
 *
 * @param page The admin page key to resolve.
 * @param params The current route params, used to keep links in context.
 */
export function resolveAdminPath(
  page: string,
  params: Record<string, string> = {},
): string {
  const template = HILOS_PAGE_ROUTES[page] ?? HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  return template.replace(
    /\{(\w+)}/g,
    (_match, name: string) =>
      params[name] ?? ADMIN_SAMPLE_PARAMS[page]?.[name] ?? 'example',
  )
}

/**
 * Build the breadcrumb from the dashboard down to `page`: the root Hilos crumb
 * followed by each ancestor and the page itself, every path resolved against the
 * current route params so a deep link's crumbs stay in context.
 *
 * @param page The current admin page key.
 * @param params The current route params.
 */
export function adminBreadcrumb(
  page: string,
  params: Record<string, string>,
): AdminCrumb[] {
  const chain: string[] = []
  for (let key: string | undefined = page; key && ADMIN_PAGES[key]; ) {
    chain.unshift(key)
    key = ADMIN_PAGES[key].parent
  }
  return [
    ADMIN_ROOT_CRUMB,
    ...chain.map((key) => ({
      label: ADMIN_PAGES[key].title,
      to: resolveAdminPath(key, params),
    })),
  ]
}

/**
 * Resolve a page's children into sub-navigation cards, each link kept in the
 * current route's context. Empty for a leaf page.
 *
 * @param page The current admin page key.
 * @param params The current route params.
 */
export function adminChildLinks(
  page: string,
  params: Record<string, string>,
): AdminChildLink[] {
  const children = ADMIN_PAGES[page]?.children
  if (!children) {
    return []
  }
  return children.map((key) => ({
    page: key,
    title: ADMIN_PAGES[key].title,
    lead: ADMIN_PAGES[key].lead,
    to: resolveAdminPath(key, params),
  }))
}
