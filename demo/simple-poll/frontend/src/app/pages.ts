// The poll page configuration: the cold-load route registry and the per-signal
// schemas the core parse boundary validates. Config only — the page
// subscription behavior lives in pageScope.ts so configuration never mixes with
// signal handling, and the route engine plus the framework admin catalog come
// from @hilos/core so this file only declares the poll's own pages and merges.
import {
  createPageRouter,
  pageResponseSchema,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
} from '@hilos/core'

import { PAGE_MAIN } from './pageKeys'

/** Concrete poll page signal schemas for the core parse boundary. */
export const pageSignalSchemas = {
  [SIGNAL_TYPE_PAGE_RESPONSE]: pageResponseSchema,
}

/**
 * The poll's own pages keyed to the URL path template each answers. The
 * framework admin pages are merged in from `HILOS_PAGE_ROUTES` rather than
 * restated here.
 */
const APP_ROUTES: Record<string, string> = {
  [PAGE_MAIN]: '/',
}

/**
 * The poll page router: the application pages plus the framework admin catalog,
 * resolving a cold-load URL to its page key and captured route params. Unknown
 * paths fall back to the main page.
 */
export const router = createPageRouter(
  { ...HILOS_PAGE_ROUTES, ...APP_ROUTES },
  { fallback: PAGE_MAIN },
)
