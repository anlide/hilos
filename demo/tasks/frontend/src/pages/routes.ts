// The tasks page routes: the cold-load route registry the navigator resolves a
// URL against. The route engine and the framework admin catalog come from
// @hilos/core, so this file only declares the tasks demo's own pages;
// `createAppPageRouter` mounts the `hilos_*` admin pages under them.
import { createAppPageRouter, type HilosRouteDeclaration } from '@hilos/core'

import { PAGE_MAIN } from './keys'

/**
 * The tasks demo's own pages keyed to the URL path template each answers and
 * whether that page is an administrative surface. The framework admin pages are
 * merged in by `createAppPageRouter` rather than restated here.
 */
const APP_ROUTES: Record<string, HilosRouteDeclaration> = {
  [PAGE_MAIN]: { path: '/', admin: false },
}

/**
 * The tasks page router: the application pages plus the framework admin catalog,
 * resolving a cold-load URL to its page key and captured route params. Unknown
 * paths fall back to the main page.
 */
export const router = createAppPageRouter(APP_ROUTES, { fallback: PAGE_MAIN })
