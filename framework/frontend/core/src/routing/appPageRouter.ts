// The application page router: a project's own pages mounted alongside the
// Hilos admin catalog. createPageRouter is the pure matching engine and knows
// nothing about which pages exist; this overlays the framework admin pages
// (HILOS_ROUTE_DECLARATIONS) under the project's routes so every Hilos app
// mounts the admin section the same way instead of spreading the catalog in by
// hand.

import {
  createPageRouter,
  type HilosRouteDeclaration,
  type PageRouter,
} from './PageRouter.js'
import { HILOS_ROUTE_DECLARATIONS } from './hilosPages.js'

/**
 * Build the application's page router: the framework admin catalog overlaid
 * with the project's own route declarations — a project route wins when its key
 * collides with an admin one — resolving a cold-load path to its page key,
 * captured params, and surface type. An unmatched path resolves to no page.
 *
 * @param appRoutes The project's own page-key → route-declaration map.
 */
export function createAppPageRouter(
  appRoutes: Record<string, HilosRouteDeclaration>,
): PageRouter {
  return createPageRouter({ ...HILOS_ROUTE_DECLARATIONS, ...appRoutes })
}
