// The application page router: a project's own pages mounted alongside the
// Hilos admin catalog. createPageRouter is the pure matching engine and knows
// nothing about which pages exist; this overlays the framework admin pages
// (HILOS_ROUTE_DECLARATIONS) under the project's routes so every Hilos app
// mounts the admin section the same way instead of spreading the catalog in by
// hand.

import {
  containsRouteSlot,
  createPageRouter,
  type HilosRouteDeclaration,
  type PageRouter,
} from './PageRouter.js'
import { HILOS_ROUTE_DECLARATIONS } from './hilosPages.js'

const HILOS_ROOT = '/hilos'
const HILOS_BRANCH_PREFIX = `${HILOS_ROOT}/`

// Names held for future Hilos sections, in addition to the live catalog below.
const RESERVED_HILOS_SEGMENTS = new Set([
  'ai-usage',
  'authority-request',
  'authority-requests',
  'automations',
  'content',
  'developers',
  'error',
  'errors',
  'files',
  'findability',
  'integrations',
  'llm-profile',
  'mcp',
  'performance',
  'privacy',
  'releases',
  'role',
  'search',
  'subscriptions',
])

for (const { path } of Object.values(HILOS_ROUTE_DECLARATIONS)) {
  if (!path.startsWith(HILOS_BRANCH_PREFIX)) {
    continue
  }
  const segment = path
    .slice(HILOS_BRANCH_PREFIX.length)
    .split('/')
    .find((part) => part !== '')
  if (segment !== undefined) {
    RESERVED_HILOS_SEGMENTS.add(segment)
  }
}

/**
 * Check project route declarations against the Hilos route reserve. Accepts a
 * route map or a path-only projection and can run without building a router.
 *
 * @param appRoutes The project's own page-key → route-declaration map.
 * @throws {Error} If a route claims the Hilos root or lacks a free literal
 * first segment under `/hilos`.
 * @example
 * `/hilos/users/export` and `/hilos/{section}` are rejected.
 * `/hilos/users-extra/{exportId}` is accepted.
 */
export function validateAppPageRoutes(
  appRoutes: Readonly<Record<string, Pick<HilosRouteDeclaration, 'path'>>>,
): void {
  for (const [page, { path }] of Object.entries(appRoutes)) {
    if (path !== HILOS_ROOT && !path.startsWith(HILOS_BRANCH_PREFIX)) {
      continue
    }

    const route = `Project route "${page}" ("${path}")`
    if (path === HILOS_ROOT || path === HILOS_BRANCH_PREFIX) {
      throw new Error(`${route} uses reserved Hilos segment "${HILOS_ROOT}".`)
    }

    const [segment] = path.slice(HILOS_BRANCH_PREFIX.length).split('/', 1)
    if (segment === '') {
      throw new Error(
        `${route} has an empty first segment under "${HILOS_ROOT}". A free literal segment is required.`,
      )
    }
    if (containsRouteSlot(segment)) {
      throw new Error(
        `${route} has a route parameter in its first segment "${segment}". A free literal segment is required.`,
      )
    }
    if (RESERVED_HILOS_SEGMENTS.has(segment)) {
      throw new Error(`${route} uses reserved Hilos segment "${segment}".`)
    }
  }
}

/**
 * Build the application's page router: the framework admin catalog overlaid
 * with the project's own route declarations — a project route wins when its key
 * collides with an admin one — resolving a cold-load path to its page key,
 * captured params, and surface type. An unmatched path resolves to no page.
 *
 * @param appRoutes The project's own page-key → route-declaration map.
 * @throws {Error} If a project route claims the Hilos root or lacks a free
 * literal first segment under `/hilos`.
 * @example
 * `/hilos/users/export` and `/hilos/{section}` are rejected.
 * `/hilos/users-extra/{exportId}` is accepted.
 */
export function createAppPageRouter(
  appRoutes: Record<string, HilosRouteDeclaration>,
): PageRouter {
  validateAppPageRoutes(appRoutes)
  return createPageRouter({ ...HILOS_ROUTE_DECLARATIONS, ...appRoutes })
}
