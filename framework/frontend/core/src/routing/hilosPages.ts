// The framework's own page map: the keys and cold-load route declarations of the
// Hilos admin section (dashboard, i18n, daemon, logs, users, MCP/skills, SIL,
// communications, security, billing, change log) plus the public content pages
// (about, terms, privacy, license) the application shell links in its footer
// (HILOS_FOOTER_LINKS). These pages are framework functionality, so the framework
// owns their URL layout — a project mounts them by merging
// HILOS_ROUTE_DECLARATIONS into its own route map rather than restating them, and
// supplies the content component mapped to each key. Keys mirror PHP
// `HilosPageConstants` and the paths mirror the framework rows of the page
// catalog; both must stay byte-identical to their backend counterparts (the page
// key is the subscription wire identity, the path is the canonical admin URL).
// Every declaration also states whether its page is an administrative surface,
// which is what keeps a framework page out of the public half of the app.
//
// What a page is CALLED is not here and cannot be: a heading is text in the
// visitor's language, and only the backend knows the language. The label, the
// lead, the icon and the place in the admin tree live in the PHP catalog
// (Database/Pages/HilosPageCatalog) and reach the page in its own page_response;
// the frontend reads them through admin/identity/hilosPageIdentity. A URL is not
// translated, and a cold load has to resolve a path to a page key before the
// socket is up, which is why the addresses stay here.

import { type HilosRouteDeclaration } from './PageRouter.js'

/** Hilos admin page keys, mirroring PHP `HilosPageConstants`. */
export const HilosPages = {
  DASHBOARD: 'hilos',
  PROFILE: 'hilos_profile',
  PROFILE_SIGN_IN: 'hilos_profile_sign_in',
  PROFILE_NOTIFICATIONS: 'hilos_profile_notifications',
  PROFILE_SESSIONS: 'hilos_profile_sessions',
  PROFILE_DEVICES: 'hilos_profile_devices',
  PROFILE_SECURITY: 'hilos_profile_security',
  PROFILE_AGREEMENTS: 'hilos_profile_agreements',
  PROFILE_AGREEMENTS_HISTORY: 'hilos_profile_agreements_history',
  PROFILE_DATA: 'hilos_profile_data',
  SETTINGS: 'hilos_settings',
  APPEARANCE: 'hilos_appearance',
  ANALYTICS: 'hilos_analytics',
  ROLES: 'hilos_roles',
  OPERATIONS: 'hilos_operations',
  BACKUP: 'hilos_backup',
  MAINTENANCE: 'hilos_maintenance',
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
  I18N_LANGUAGE_NAMES: 'hilos_i18n_language_names',
  I18N_LANGUAGE_LOCALES: 'hilos_i18n_language_locales',
  I18N_COUNTRY: 'hilos_i18n_country',
  I18N_COUNTRY_NAMES: 'hilos_i18n_country_names',
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
  DAEMON_ENV: 'hilos_daemon_env',
  DAEMON_ENV_MISMATCH: 'hilos_daemon_env_mismatch',
  LOGS: 'hilos_logs',
  LOGS_KEYS: 'hilos_logs_keys',
  LOGS_WORKERS: 'hilos_logs_workers',
  LOGS_ROTATIONS: 'hilos_logs_rotations',
  LOGS_SETTINGS: 'hilos_logs_settings',
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
  SECURITY_STEP_UP: 'hilos_security_step_up',
  SECURITY_OAUTH: 'hilos_security_oauth',
  SECURITY_OAUTH_PROVIDER: 'hilos_security_oauth_provider',
  SECURITY_SIGN_IN_METHODS: 'hilos_security_sign_in_methods',
  SECURITY_IMPERSONATION: 'hilos_security_impersonation',
  LEGAL: 'hilos_legal',
  LEGAL_DOCUMENT: 'hilos_legal_document',
  LEGAL_REVISION: 'hilos_legal_revision',
  LEGAL_ACCEPTANCES: 'hilos_legal_acceptances',
  LEGAL_SETTINGS: 'hilos_legal_settings',
  BILLING: 'hilos_billing',
  BILLING_PROVIDER: 'hilos_billing_provider',
  BILLING_PAYMENTS: 'hilos_billing_payments',
  BILLING_REFUNDS: 'hilos_billing_refunds',
  ABOUT: 'hilos_about',
  TERMS: 'hilos_terms',
  PRIVACY: 'hilos_privacy',
  LICENSE: 'hilos_license',
} as const

/** A framework page key: one of the values of {@link HilosPages}. */
export type HilosPageKey = (typeof HilosPages)[keyof typeof HilosPages]

/**
 * Hilos admin page key → route declaration, mirroring the framework rows of
 * the page catalog. `{name}` segments are route params captured at match time,
 * and `admin` states whether the page is an administrative surface — true for
 * the admin section, false for the profile and the public footer pages. A
 * project overlays this with its own declarations to mount the admin. The table
 * is declared total over {@link HilosPageKey}, so a page key that forgets its
 * route is a compile error.
 */
export const HILOS_ROUTE_DECLARATIONS: Record<
  HilosPageKey,
  HilosRouteDeclaration
> = {
  [HilosPages.DASHBOARD]: { path: '/hilos', admin: true },
  [HilosPages.PROFILE]: { path: '/profile', admin: false },
  [HilosPages.PROFILE_SIGN_IN]: { path: '/profile/sign-in', admin: false },
  [HilosPages.PROFILE_NOTIFICATIONS]: {
    path: '/profile/notifications',
    admin: false,
  },
  [HilosPages.PROFILE_SESSIONS]: { path: '/profile/sessions', admin: false },
  [HilosPages.PROFILE_DEVICES]: { path: '/profile/devices', admin: false },
  [HilosPages.PROFILE_SECURITY]: { path: '/profile/security', admin: false },
  [HilosPages.PROFILE_AGREEMENTS]: {
    path: '/profile/agreements',
    admin: false,
  },
  [HilosPages.PROFILE_AGREEMENTS_HISTORY]: {
    path: '/profile/agreements/history',
    admin: false,
  },
  [HilosPages.PROFILE_DATA]: { path: '/profile/data', admin: false },
  [HilosPages.SETTINGS]: { path: '/hilos/settings', admin: true },
  [HilosPages.APPEARANCE]: { path: '/hilos/appearance', admin: true },
  [HilosPages.ANALYTICS]: { path: '/hilos/analytics', admin: true },
  [HilosPages.ROLES]: { path: '/hilos/roles', admin: true },
  [HilosPages.OPERATIONS]: { path: '/hilos/operations', admin: true },
  [HilosPages.BACKUP]: { path: '/hilos/backup', admin: true },
  [HilosPages.MAINTENANCE]: { path: '/hilos/maintenance', admin: true },
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
    path: '/hilos/i18n/languages/{languageCode}',
    admin: true,
  },
  [HilosPages.I18N_LANGUAGE_NAMES]: {
    path: '/hilos/i18n/languages/{languageCode}/names',
    admin: true,
  },
  [HilosPages.I18N_LANGUAGE_LOCALES]: {
    path: '/hilos/i18n/languages/{languageCode}/locales',
    admin: true,
  },
  [HilosPages.I18N_COUNTRY]: {
    path: '/hilos/i18n/countries/{countryCode}',
    admin: true,
  },
  [HilosPages.I18N_COUNTRY_NAMES]: {
    path: '/hilos/i18n/countries/{countryCode}/names',
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
  [HilosPages.DAEMON_WORKERS]: {
    path: '/hilos/daemon/{nodeId}/workers',
    admin: true,
  },
  [HilosPages.DAEMON_AGENTS]: {
    path: '/hilos/daemon/{nodeId}/agents',
    admin: true,
  },
  [HilosPages.DAEMON_CRON]: {
    path: '/hilos/daemon/{nodeId}/cron',
    admin: true,
  },
  [HilosPages.DAEMON_WEBSOCKETS]: {
    path: '/hilos/daemon/{nodeId}/websockets',
    admin: true,
  },
  [HilosPages.DAEMON_HTTP_SERVER]: {
    path: '/hilos/daemon/{nodeId}/http',
    admin: true,
  },
  [HilosPages.DAEMON_ENV]: { path: '/hilos/daemon/{nodeId}/env', admin: true },
  [HilosPages.DAEMON_ENV_MISMATCH]: {
    path: '/hilos/daemon/env-mismatch',
    admin: true,
  },
  [HilosPages.LOGS]: { path: '/hilos/logs', admin: true },
  [HilosPages.LOGS_KEYS]: { path: '/hilos/logs/keys', admin: true },
  [HilosPages.LOGS_WORKERS]: { path: '/hilos/logs/workers', admin: true },
  [HilosPages.LOGS_ROTATIONS]: {
    path: '/hilos/logs/rotations/{state?}',
    admin: true,
  },
  [HilosPages.LOGS_SETTINGS]: { path: '/hilos/logs/settings', admin: true },
  [HilosPages.LOGS_VIEW]: {
    path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}/{anchor?}',
    admin: true,
  },
  // The optional tail narrows the list to the people past one legal document's
  // deadline (HIL-945): the legal section's root links its count here.
  [HilosPages.USERS]: { path: '/hilos/users/{lapsed?}', admin: true },
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
  [HilosPages.SECURITY_STEP_UP]: {
    path: '/hilos/security/2fa/step-up',
    admin: true,
  },
  [HilosPages.SECURITY_OAUTH]: { path: '/hilos/security/oauth', admin: true },
  [HilosPages.SECURITY_OAUTH_PROVIDER]: {
    path: '/hilos/security/oauth/{providerId}',
    admin: true,
  },
  [HilosPages.SECURITY_SIGN_IN_METHODS]: {
    path: '/hilos/security/sign-in-methods',
    admin: true,
  },
  [HilosPages.SECURITY_IMPERSONATION]: {
    path: '/hilos/security/impersonation',
    admin: true,
  },
  [HilosPages.LEGAL]: { path: '/hilos/legal', admin: true },
  [HilosPages.LEGAL_DOCUMENT]: {
    path: '/hilos/legal/{documentKey}',
    admin: true,
  },
  [HilosPages.LEGAL_REVISION]: {
    path: '/hilos/legal/{documentKey}/{revisionId}',
    admin: true,
  },
  [HilosPages.LEGAL_ACCEPTANCES]: {
    path: '/hilos/legal/acceptances',
    admin: true,
  },
  [HilosPages.LEGAL_SETTINGS]: { path: '/hilos/legal/settings', admin: true },
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
