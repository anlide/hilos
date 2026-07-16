// Resolve the framework's public footer pages (HILOS_FOOTER_LINKS) to their
// routes and the project-supplied content components. A declared footer link
// with no route or no component is a build error, not a silent skip — shipping a
// footer link that leads nowhere is a misconfiguration (HIL-211 Flow Q3).

import { HILOS_FOOTER_LINKS, HILOS_PAGE_ROUTES } from '@hilos/core'

import type { ResolvedPublicPage } from './types.js'

/**
 * Resolve every public footer page to its route and content component.
 *
 * @param components Page key → content component supplied by the project.
 * @returns The resolved public pages, in `HILOS_FOOTER_LINKS` order.
 * @throws Error if a footer link has no route in `HILOS_PAGE_ROUTES` or no
 *   component in `components` — a declared public page must be shippable.
 */
export function discoverPublicRoutes<TComponent>(
  components: Record<string, TComponent>,
): ResolvedPublicPage<TComponent>[] {
  return HILOS_FOOTER_LINKS.map(({ page }) => {
    const route = HILOS_PAGE_ROUTES[page]
    if (route === undefined) {
      throw new Error(
        `Public footer page "${page}" has no route in HILOS_PAGE_ROUTES.`,
      )
    }

    const component = components[page]
    if (component === undefined) {
      throw new Error(
        `Public footer page "${page}" (${route}) has no content component.`,
      )
    }

    return { page, route, component }
  })
}
