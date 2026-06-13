// The chat page routes: the cold-load route registry the navigator resolves a
// URL against, mirroring the application rows of the legacy `ChatPageCatalog`.
// The route engine and the framework admin catalog come from @hilos/core, so
// this file only declares the chat's own pages; `createAppPageRouter` mounts the
// `hilos_*` admin pages under them.
import { createAppPageRouter } from '@hilos/core'

import {
  PAGE_MAIN,
  PAGE_PROFILE,
  PAGE_USER,
  PAGE_BOT,
  PAGE_ADMIN_USERS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_BOTS,
} from './keys'

/**
 * The chat's own pages keyed to the URL path template each answers. A `{name}`
 * segment is a route param captured at match time; the framework admin pages
 * are merged in by `createAppPageRouter` rather than restated here.
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
export const router = createAppPageRouter(APP_ROUTES, { fallback: PAGE_MAIN })
