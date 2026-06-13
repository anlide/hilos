// The application page router: a project's own pages mounted alongside the
// Hilos admin catalog. createPageRouter is the pure matching engine and knows
// nothing about which pages exist; this overlays the framework admin pages
// (HILOS_PAGE_ROUTES) under the project's routes so every Hilos app mounts the
// admin section the same way instead of spreading the catalog in by hand.

import {
  createPageRouter,
  type PageRouter,
  type PageRouterOptions,
} from './PageRouter.js'
import { HILOS_PAGE_ROUTES } from './hilosPages.js'

/**
 * Build the application's page router: the framework admin catalog overlaid
 * with the project's own routes — a project route wins when its key collides
 * with an admin one — resolving a cold-load path to its page key and captured
 * params, with the project's fallback page for an unmatched path.
 *
 * @param appRoutes The project's own page-key → URL-template map.
 * @param options Router options, including the fallback page for no match.
 */
export function createAppPageRouter(
  appRoutes: Record<string, string>,
  options: PageRouterOptions,
): PageRouter {
  return createPageRouter({ ...HILOS_PAGE_ROUTES, ...appRoutes }, options)
}
