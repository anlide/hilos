// The chat page routes: the cold-load route registry the navigator resolves a
// URL against, mirroring the application rows of the legacy `ChatPageCatalog`.
// The route engine and the framework admin catalog come from @hilos/core, so
// this file only declares the chat's own pages; `createAppPageRouter` mounts the
// `hilos_*` admin pages under them.
import { createAppPageRouter, type HilosRouteDeclaration } from '@hilos/core'

import {
  PAGE_MAIN,
  PAGE_USER,
  PAGE_BOT,
  PAGE_ADMIN_USERS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_BOTS,
} from './keys'

/**
 * The chat's own pages keyed to the URL path template each answers and whether
 * that page is an administrative surface. A `{name}` segment is a route param
 * captured at match time; the framework admin pages are merged in by
 * `createAppPageRouter` rather than restated here.
 */
const APP_ROUTES: Record<string, HilosRouteDeclaration> = {
  [PAGE_MAIN]: { path: '/', admin: false },
  [PAGE_USER]: { path: '/user/{id}', admin: false },
  [PAGE_BOT]: { path: '/bot/{id}', admin: false },
  [PAGE_ADMIN_USERS]: { path: '/hilos/admin_users', admin: true },
  [PAGE_ADMIN_MODERATOR]: { path: '/hilos/admin_moderator', admin: true },
  [PAGE_ADMIN_BOTS]: { path: '/hilos/admin_bots', admin: true },
}

/**
 * The chat page router: the application pages plus the framework admin catalog,
 * resolving a cold-load URL to its page key and captured route params. Unknown
 * paths fall back to the main page like the legacy router's catch-all.
 */
export const router = createAppPageRouter(APP_ROUTES, { fallback: PAGE_MAIN })
