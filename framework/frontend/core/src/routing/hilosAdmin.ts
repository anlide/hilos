// Turning page keys into the links the admin chrome renders: the breadcrumb
// chain and the subsection cards a page answered with, each entry resolved to a
// path in the context of the current route params, plus the framework's own
// key→path resolver. The chain and the cards themselves arrive from the backend
// catalog (admin/identity/hilosPageIdentity); this module holds only the mapping
// to a target, so the Vue/React/Angular breadcrumb and admin-page-shell
// components stay thin. No page's content or render logic lives here — those are
// one-per-file under each project's views/ (page-module-structure rule).
//
// The mappers take the path resolver as an argument rather than reading a
// constant: a card or a crumb may name a PROJECT page, and only the
// application's merged route map knows those (HilosRouter.resolvePath).

import {
  type HilosPageChild,
  type HilosPageCrumb,
} from '../admin/identity/hilosPageIdentity.js'
import { createPageRouter } from './PageRouter.js'
import {
  HILOS_PAGE_ROUTES,
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
} from './hilosPages.js'

/**
 * The framework's own routes as a resolver. It is the same engine cold-load
 * matching runs on, built over the same declarations, so a path produced here
 * and a path matched at startup cannot drift apart.
 */
const frameworkRoutes = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
  fallback: HilosPages.DASHBOARD,
})

/**
 * Resolve a FRAMEWORK page's cold-load path, substituting each `{param}` from
 * the given route params. An unfilled `{param?}` token is cut together with the
 * slash in front of it, so a page entered with none of its optional tail
 * resolves to the bare path.
 *
 * It knows the framework's pages and no others, and answers the dashboard for
 * anything else. Use it only where the key is a `HilosPages` value the caller
 * writes itself; anything that may name a project page — a breadcrumb link, a
 * navigation card — goes through `HilosRouter.resolvePath`, which reads the
 * application's merged map and says so plainly when there is no address.
 *
 * @param page The Hilos page key to resolve.
 * @param params The route params to substitute, defaulting to none.
 */
export function resolveHilosPath(
  page: string,
  params: Record<string, string> = {},
): string {
  return (
    frameworkRoutes.path(page, params) ??
    HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  )
}

/** Resolves a page key and route params to a path, or to nothing. */
export type HilosPathResolver = (
  page: string,
  params?: Record<string, string>,
) => string | undefined

/** A resolved breadcrumb link: the page it names, its caption, and its target. */
export interface HilosCrumb {
  /** The page key the crumb names; also the list key a port renders it under. */
  page: string
  /** The visible label. */
  label: string
  /**
   * The path the crumb navigates to, absent when the route map has no address
   * for the page. A port draws such a link as plain text: the chain has to stay
   * whole, and a link that goes nowhere is worse than none.
   */
  to?: string
}

/** A resolved subsection card: a crumb plus the lead and icon the card shows. */
export interface HilosAdminChild extends HilosCrumb {
  /** The card's one-line description. */
  lead: string
  /** Bootstrap icon name (`bi-*`), or null when the catalog gives the card none. */
  icon: string | null
  /** The path the card navigates to; a card without one is not rendered at all. */
  to: string
}

/**
 * Resolve a breadcrumb chain to links. Every link the chain carries is kept,
 * with or without a target — dropping one would renumber the ancestry and put
 * the visitor somewhere they are not.
 *
 * @param crumbs The chain the page answered with, root first.
 * @param params The current route params, so a deep link's crumbs stay in context.
 * @param resolvePath Resolves a page key to its path.
 */
export function hilosCrumbLinks(
  crumbs: readonly HilosPageCrumb[],
  params: Record<string, string>,
  resolvePath: HilosPathResolver,
): HilosCrumb[] {
  return crumbs.map((crumb) => {
    const to = resolvePath(crumb.page, params)

    return to === undefined
      ? { page: crumb.page, label: crumb.label }
      : { page: crumb.page, label: crumb.label, to }
  })
}

/**
 * Resolve subsection cards to links, dropping every card the route map has no
 * address for. A card is a target and nothing else, so one without a path is not
 * a card — this is where a child whose route needs a param the current route
 * does not carry falls out, the per-item detail page reached from a list.
 *
 * @param children The cards the page answered with, in catalog order.
 * @param params The current route params, so a card stays in the page's context.
 * @param resolvePath Resolves a page key to its path.
 */
export function hilosChildLinks(
  children: readonly HilosPageChild[],
  params: Record<string, string>,
  resolvePath: HilosPathResolver,
): HilosAdminChild[] {
  return children.flatMap((child) => {
    const to = resolvePath(child.page, params)

    return to === undefined
      ? []
      : [
          {
            page: child.page,
            label: child.label,
            lead: child.lead,
            icon: child.icon,
            to,
          },
        ]
  })
}
