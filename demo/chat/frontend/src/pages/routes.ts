// The chat page routes: the cold-load route registry the navigator resolves a
// URL against. The route engine and the framework's own routes come from
// @hilos/core, so this file only declares the chat's own pages;
// `createAppPageRouter` mounts the `hilos_*` admin pages under them.
//
// The three admin screens answer under /hilos/app/: the `app` segment says
// "application screen, not framework screen", so a Hilos page added later cannot
// collide with one of them by construction. Their page keys do not change with
// the address — a key is identity on the wire, a path is not.
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
  [PAGE_ADMIN_USERS]: { path: '/hilos/app/users', admin: true },
  [PAGE_ADMIN_MODERATOR]: { path: '/hilos/app/moderator', admin: true },
  [PAGE_ADMIN_BOTS]: { path: '/hilos/app/bots', admin: true },
}

/**
 * The chat page router: the application pages plus the framework's own routes,
 * resolving a cold-load URL to its page key and captured route params, and a page
 * key back to its path for the breadcrumb and the dashboard cards. Unknown paths
 * fall back to the main page.
 */
export const router = createAppPageRouter(APP_ROUTES, { fallback: PAGE_MAIN })
