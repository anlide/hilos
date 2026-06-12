// The chat page configuration: the URL-to-page route registry for cold-load
// routing and the per-signal schemas the core parse boundary validates. Config
// only — the page subscription behavior lives in pageScope.ts so configuration
// never mixes with signal handling, and the page keys themselves live in
// pageKeys.ts so this file reads as a registration of them, not their source.
import { pageResponseSchema } from '@hilos/core'

import {
  PAGE_MAIN,
  PAGE_PROFILE,
  PAGE_USER,
  PAGE_BOT,
  PAGE_ADMIN_USERS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_BOTS,
  PAGE_HILOS_DASHBOARD,
  PAGE_HILOS_SETTINGS,
  PAGE_HILOS_ANALYTICS,
  PAGE_HILOS_ROLES,
  PAGE_HILOS_OPERATIONS,
  PAGE_HILOS_BACKUP,
  PAGE_HILOS_GUARDIAN,
  PAGE_HILOS_GUARDIAN_AGENT,
  PAGE_HILOS_I18N,
  PAGE_HILOS_I18N_LANGUAGES,
  PAGE_HILOS_I18N_COUNTRIES,
  PAGE_HILOS_I18N_ENTITIES,
  PAGE_HILOS_I18N_UI_PAGES,
  PAGE_HILOS_I18N_GROUPS,
  PAGE_HILOS_I18N_ACTIONS,
  PAGE_HILOS_I18N_EMAILS,
  PAGE_HILOS_I18N_LANGUAGE,
  PAGE_HILOS_I18N_COUNTRY,
  PAGE_HILOS_I18N_UI_PAGE,
  PAGE_HILOS_I18N_GROUP,
  PAGE_HILOS_I18N_ACTION,
  PAGE_HILOS_I18N_TRANSLATE_ENTITY,
  PAGE_HILOS_I18N_TRANSLATE_UI_PAGE,
  PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM,
  PAGE_HILOS_I18N_TRANSLATE_GROUP,
  PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM,
  PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR,
  PAGE_HILOS_I18N_TRANSLATE_EMAIL,
  PAGE_HILOS_CHANGE_LOG,
  PAGE_HILOS_CHANGE_LOG_TABLES,
  PAGE_HILOS_CHANGE_LOG_TABLE,
  PAGE_HILOS_DAEMON,
  PAGE_HILOS_DAEMON_WORKERS,
  PAGE_HILOS_DAEMON_AGENTS,
  PAGE_HILOS_DAEMON_CRON,
  PAGE_HILOS_DAEMON_WEBSOCKETS,
  PAGE_HILOS_DAEMON_HTTP_SERVER,
  PAGE_HILOS_LOGS,
  PAGE_HILOS_LOGS_KEYS,
  PAGE_HILOS_LOGS_WORKERS,
  PAGE_HILOS_LOGS_ROTATIONS,
  PAGE_HILOS_LOGS_VIEW,
  PAGE_HILOS_USERS,
  PAGE_HILOS_USER,
  PAGE_HILOS_MCP_SKILLS,
  PAGE_HILOS_MCP_SKILLS_MCP,
  PAGE_HILOS_MCP_SKILLS_MCP_LOGS,
  PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW,
  PAGE_HILOS_SIL,
  PAGE_HILOS_SIL_REQUESTS,
  PAGE_HILOS_SIL_USER_HISTORY,
  PAGE_HILOS_COMMUNICATIONS,
  PAGE_HILOS_COMMUNICATIONS_CHANNEL,
  PAGE_HILOS_COMMUNICATIONS_DELIVERIES,
  PAGE_HILOS_SECURITY,
  PAGE_HILOS_SECURITY_2FA,
  PAGE_HILOS_SECURITY_OAUTH,
  PAGE_HILOS_SECURITY_OAUTH_PROVIDER,
  PAGE_HILOS_BILLING,
  PAGE_HILOS_BILLING_PROVIDER,
  PAGE_HILOS_BILLING_PAYMENTS,
  PAGE_HILOS_BILLING_REFUNDS,
} from './pageKeys'

/** Signal `type` of the backend page response (PHP `SignalTypeConstants::PAGE_RESPONSE`). */
export const SIGNAL_PAGE_RESPONSE = 'page_response'

/** Concrete chat page signal schemas for the core parse boundary. */
export const pageSignalSchemas = {
  [SIGNAL_PAGE_RESPONSE]: pageResponseSchema,
}

/**
 * Cold-load route registry: every chat page keyed to the URL path template it
 * answers, mirroring the legacy `ChatPageCatalog` so the frontend now owns the
 * routes the handshake catalog used to carry. A `{name}` segment is a route
 * param captured at match time and handed to the page subscription; a template
 * without one is a static path matched whole.
 */
export const PAGE_ROUTES: Record<string, string> = {
  [PAGE_MAIN]: '/',
  [PAGE_PROFILE]: '/profile',
  [PAGE_USER]: '/user/{id}',
  [PAGE_BOT]: '/bot/{id}',
  [PAGE_ADMIN_USERS]: '/hilos/admin_users',
  [PAGE_ADMIN_MODERATOR]: '/hilos/admin_moderator',
  [PAGE_ADMIN_BOTS]: '/hilos/admin_bots',
  [PAGE_HILOS_DASHBOARD]: '/hilos',
  [PAGE_HILOS_SETTINGS]: '/hilos/settings',
  [PAGE_HILOS_ANALYTICS]: '/hilos/analytics',
  [PAGE_HILOS_ROLES]: '/hilos/roles',
  [PAGE_HILOS_OPERATIONS]: '/hilos/operations',
  [PAGE_HILOS_BACKUP]: '/hilos/backup',
  [PAGE_HILOS_GUARDIAN]: '/hilos/guardian',
  [PAGE_HILOS_GUARDIAN_AGENT]: '/hilos/guardian/{agentId}',
  [PAGE_HILOS_I18N]: '/hilos/i18n',
  [PAGE_HILOS_I18N_LANGUAGES]: '/hilos/i18n/languages',
  [PAGE_HILOS_I18N_COUNTRIES]: '/hilos/i18n/countries',
  [PAGE_HILOS_I18N_ENTITIES]: '/hilos/i18n/entities',
  [PAGE_HILOS_I18N_UI_PAGES]: '/hilos/i18n/ui-pages',
  [PAGE_HILOS_I18N_GROUPS]: '/hilos/i18n/groups',
  [PAGE_HILOS_I18N_ACTIONS]: '/hilos/i18n/actions',
  [PAGE_HILOS_I18N_EMAILS]: '/hilos/i18n/emails',
  [PAGE_HILOS_I18N_LANGUAGE]: '/hilos/i18n/languages/{languageId}',
  [PAGE_HILOS_I18N_COUNTRY]: '/hilos/i18n/countries/{countryId}',
  [PAGE_HILOS_I18N_UI_PAGE]: '/hilos/i18n/ui-pages/{uiPageId}',
  [PAGE_HILOS_I18N_GROUP]: '/hilos/i18n/groups/{groupId}',
  [PAGE_HILOS_I18N_ACTION]: '/hilos/i18n/actions/{actionId}',
  [PAGE_HILOS_I18N_TRANSLATE_ENTITY]: '/hilos/i18n/translate/entity/{entityId}',
  [PAGE_HILOS_I18N_TRANSLATE_UI_PAGE]: '/hilos/i18n/translate/ui-page/{uiPageId}',
  [PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM]: '/hilos/i18n/translate/ui-page/{uiPageId}/item/{itemId}',
  [PAGE_HILOS_I18N_TRANSLATE_GROUP]: '/hilos/i18n/translate/group/{groupId}',
  [PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM]: '/hilos/i18n/translate/group/{groupId}/item/{itemId}',
  [PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR]: '/hilos/i18n/translate/action/{actionId}/error/{errorId}',
  [PAGE_HILOS_I18N_TRANSLATE_EMAIL]: '/hilos/i18n/translate/email/{emailId}',
  [PAGE_HILOS_CHANGE_LOG]: '/hilos/change-log',
  [PAGE_HILOS_CHANGE_LOG_TABLES]: '/hilos/change-log/tables',
  [PAGE_HILOS_CHANGE_LOG_TABLE]: '/hilos/change-log/tables/{tableId}',
  [PAGE_HILOS_DAEMON]: '/hilos/daemon',
  [PAGE_HILOS_DAEMON_WORKERS]: '/hilos/daemon/workers',
  [PAGE_HILOS_DAEMON_AGENTS]: '/hilos/daemon/agents',
  [PAGE_HILOS_DAEMON_CRON]: '/hilos/daemon/cron',
  [PAGE_HILOS_DAEMON_WEBSOCKETS]: '/hilos/daemon/websockets',
  [PAGE_HILOS_DAEMON_HTTP_SERVER]: '/hilos/daemon/http/{serverId}',
  [PAGE_HILOS_LOGS]: '/hilos/logs',
  [PAGE_HILOS_LOGS_KEYS]: '/hilos/logs/keys',
  [PAGE_HILOS_LOGS_WORKERS]: '/hilos/logs/workers',
  [PAGE_HILOS_LOGS_ROTATIONS]: '/hilos/logs/rotations',
  [PAGE_HILOS_LOGS_VIEW]: '/hilos/logs/view',
  [PAGE_HILOS_USERS]: '/hilos/users',
  [PAGE_HILOS_USER]: '/hilos/users/{userId}',
  [PAGE_HILOS_MCP_SKILLS]: '/hilos/mcp-skills',
  [PAGE_HILOS_MCP_SKILLS_MCP]: '/hilos/mcp-skills/{mcpId}',
  [PAGE_HILOS_MCP_SKILLS_MCP_LOGS]: '/hilos/mcp-skills/{mcpId}/logs',
  [PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW]: '/hilos/mcp-skills/{mcpId}/logs/view',
  [PAGE_HILOS_SIL]: '/hilos/sil',
  [PAGE_HILOS_SIL_REQUESTS]: '/hilos/sil/requests',
  [PAGE_HILOS_SIL_USER_HISTORY]: '/hilos/sil/users/{userId}',
  [PAGE_HILOS_COMMUNICATIONS]: '/hilos/communications',
  [PAGE_HILOS_COMMUNICATIONS_CHANNEL]: '/hilos/communications/{channelId}',
  [PAGE_HILOS_COMMUNICATIONS_DELIVERIES]: '/hilos/communications/{channelId}/deliveries',
  [PAGE_HILOS_SECURITY]: '/hilos/security',
  [PAGE_HILOS_SECURITY_2FA]: '/hilos/security/2fa',
  [PAGE_HILOS_SECURITY_OAUTH]: '/hilos/security/oauth',
  [PAGE_HILOS_SECURITY_OAUTH_PROVIDER]: '/hilos/security/oauth/{providerId}',
  [PAGE_HILOS_BILLING]: '/hilos/billing',
  [PAGE_HILOS_BILLING_PROVIDER]: '/hilos/billing/{providerId}',
  [PAGE_HILOS_BILLING_PAYMENTS]: '/hilos/billing/{providerId}/payments',
  [PAGE_HILOS_BILLING_REFUNDS]: '/hilos/billing/{providerId}/refunds',
}

/** A resolved cold-load route: the page key and its captured URL params. */
export interface PageRouteMatch {
  page: string
  params: Record<string, string>
}

/** A compiled templated route: its page key, segment regex, and param order. */
interface CompiledRoute {
  page: string
  regex: RegExp
  paramNames: string[]
}

// Templates split into two buckets so static paths win by exact lookup and
// never collide with a param route at the same depth: paths without `{` go to
// the literal map, the rest compile to anchored segment regexes.
const STATIC_ROUTES: Record<string, string> = {}
const PARAM_ROUTES: CompiledRoute[] = []

for (const [page, template] of Object.entries(PAGE_ROUTES)) {
  if (!template.includes('{')) {
    STATIC_ROUTES[template] = page
    continue
  }
  const paramNames: string[] = []
  const pattern = template
    .split('/')
    .map((segment) => {
      const param = segment.match(/^\{([^}]+)\}$/)
      if (param !== null) {
        paramNames.push(param[1])
        return '([^/]+)'
      }
      return segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    })
    .join('/')
  PARAM_ROUTES.push({ page, regex: new RegExp(`^${pattern}$`), paramNames })
}

/**
 * Resolve the page key and route params the URL names on cold load. A static
 * path matches whole; a templated path captures its `{name}` segments into
 * params. Unknown paths fall back to the main page like the legacy router's
 * catch-all, with no params.
 *
 * @param pathname The location pathname at load time.
 */
export function matchPage(pathname: string): PageRouteMatch {
  const staticPage = STATIC_ROUTES[pathname]
  if (staticPage !== undefined) {
    return { page: staticPage, params: {} }
  }
  for (const route of PARAM_ROUTES) {
    const captured = route.regex.exec(pathname)
    if (captured === null) {
      continue
    }
    const params: Record<string, string> = {}
    route.paramNames.forEach((name, index) => {
      params[name] = captured[index + 1]
    })
    return { page: route.page, params }
  }
  return { page: PAGE_MAIN, params: {} }
}
