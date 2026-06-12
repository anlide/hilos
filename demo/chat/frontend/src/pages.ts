// The chat page configuration: the cold-load route registry and the per-signal
// schemas the core parse boundary validates. Config only — the page
// subscription behavior lives in pageScope.ts so configuration never mixes with
// signal handling, and the route engine plus the framework admin catalog come
// from @hilos/core so this file only declares the chat's own pages and merges.
import {
  createPageRouter,
  pageResponseSchema,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
} from '@hilos/core'

import {
  PAGE_MAIN,
  PAGE_PROFILE,
  PAGE_USER,
  PAGE_BOT,
  PAGE_ADMIN_USERS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_BOTS,
} from './pageKeys'

/** Concrete chat page signal schemas for the core parse boundary. */
export const pageSignalSchemas = {
  [SIGNAL_TYPE_PAGE_RESPONSE]: pageResponseSchema,
}

/**
 * Entity-slot types for the page payload: which payload slots (here, inside
 * list items) are entities and under what canonical type. Frontend config, not
 * emitted on the wire — keep it in sync with the backend browser sources
 * (rules-and-violations.md). The `users` slot is `MainUsersBrowserList`'s DB
 * user source (wire slot key = its collection name, `ChatDbContext::users`),
 * so a user dedupes against the same entity wherever it appears.
 */
export const pageEntityTypes: Record<string, string> = {
  users: 'user',
}

/**
 * The chat's own pages keyed to the URL path template each answers, mirroring
 * the application rows of the legacy `ChatPageCatalog`. A `{name}` segment is a
 * route param captured at match time; the framework admin pages are merged in
 * from `HILOS_PAGE_ROUTES` rather than restated here.
 */
const APP_ROUTES: Record<string, string> = {
  [PAGE_MAIN]: '/',
  [PAGE_PROFILE]: '/profile',
  [PAGE_USER]: '/user/{id}',
  [PAGE_BOT]: '/bot/{id}',
  [PAGE_ADMIN_USERS]: '/hilos/admin_users',
  [PAGE_ADMIN_MODERATOR]: '/hilos/admin_moderator',
  [PAGE_ADMIN_BOTS]: '/hilos/admin_bots',
}

/**
 * The chat page router: the application pages plus the framework admin catalog,
 * resolving a cold-load URL to its page key and captured route params. Unknown
 * paths fall back to the main page like the legacy router's catch-all.
 */
export const router = createPageRouter(
  { ...HILOS_PAGE_ROUTES, ...APP_ROUTES },
  { fallback: PAGE_MAIN },
)
