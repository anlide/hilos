// Navigation over the Hilos admin tree: turning a page key plus the current
// route params into the concrete links the admin chrome renders — the
// breadcrumb up to the dashboard root, and the resolvable sub-section children
// of a page. The tree itself (labels, leads, parents) is the catalog in
// hilosPages.ts (HILOS_ADMIN_PAGES); this module is the read-only logic over it,
// so the Vue/React/Angular breadcrumb and admin-page-shell components stay thin
// and per-framework. No page's content or render logic lives here — those are
// one-per-file under each project's views/ (page-module-structure rule).

import {
  HILOS_ADMIN_PAGES,
  HILOS_PAGE_ROUTES,
  HilosPages,
} from './hilosPages.js'

/** A resolved breadcrumb or child entry: a label and the path it navigates to. */
export interface HilosCrumb {
  /** The visible label. */
  label: string
  /** The cold-load path the entry navigates to. */
  to: string
}

/** A resolved admin child: its page key, label, lead, and path. */
export interface HilosAdminChild extends HilosCrumb {
  /** The child page key. */
  page: string
  /** The child's one-line description. */
  lead: string
}

/** A route-template slot: `{name}` required, `{name?}` one the path may omit. */
const ROUTE_SLOT_PATTERN = /\{(\w+)(\??)}/g

/** An optional `{name?}` slot together with the slash that precedes it. */
const OPTIONAL_ROUTE_SLOT_PATTERN = /\/\{(\w+)\?}/g

/**
 * The required `{param}` tokens a route template captures, in order of
 * appearance. An optional `{param?}` token is not one of them: the path is a
 * valid address without it.
 */
function requiredRouteParamNames(page: string): string[] {
  const template = HILOS_PAGE_ROUTES[page] ?? ''
  return [...template.matchAll(ROUTE_SLOT_PATTERN)]
    .filter((match) => match[2] === '')
    .map((match) => match[1])
}

/**
 * Resolve a Hilos page's cold-load path, substituting each `{param}` from the
 * given route params. An unfilled `{param?}` token is cut together with the
 * slash in front of it, so a page entered with none of its optional tail
 * resolves to the bare path rather than to one with empty segments. A required
 * token with no matching param resolves to the empty string; callers that build
 * links (the breadcrumb, the children) only do so for pages whose required
 * params are covered, so a half-filled path is never emitted.
 *
 * @param page The Hilos page key to resolve.
 * @param params The route params to substitute, defaulting to none.
 */
export function resolveHilosPath(
  page: string,
  params: Record<string, string> = {},
): string {
  const template =
    HILOS_PAGE_ROUTES[page] ?? HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  return template
    .replace(OPTIONAL_ROUTE_SLOT_PATTERN, (_match, name: string) => {
      const value = params[name]
      return value === undefined ? '' : `/${value}`
    })
    .replace(ROUTE_SLOT_PATTERN, (_match, name: string) => params[name] ?? '')
}

/**
 * Whether every `{param}` a page's route needs is present in `params`. An
 * optional `{param?}` slot counts as covered always — a page reachable without
 * it belongs in the section tree even when nothing has been chosen yet.
 */
function isResolvable(page: string, params: Record<string, string>): boolean {
  return requiredRouteParamNames(page).every((name) => name in params)
}

/**
 * The breadcrumb from the dashboard root down to `page`: each ancestor and the
 * page itself, every path resolved against the current route params so a deep
 * link's crumbs stay in context. The last entry is the current page; a presenter
 * renders it as the active, non-link crumb.
 *
 * @param page The current admin page key.
 * @param params The current route params.
 */
export function hilosAdminBreadcrumb(
  page: string,
  params: Record<string, string> = {},
): HilosCrumb[] {
  const chain: string[] = []
  for (let key: string | undefined = page; key && HILOS_ADMIN_PAGES[key]; ) {
    chain.unshift(key)
    key = HILOS_ADMIN_PAGES[key].parent
  }
  return chain.map((key) => ({
    label: HILOS_ADMIN_PAGES[key].label,
    to: resolveHilosPath(key, params),
  }))
}

/**
 * A page's sub-section children as navigation cards: the admin pages whose
 * parent is `page`, each kept in the current route's context. A child whose
 * route needs a param the current route does not carry (a per-item detail
 * reached from a list, not a static sub-section) is omitted — a stub cannot
 * invent the id. Empty for a leaf page.
 *
 * @param page The current admin page key.
 * @param params The current route params.
 */
export function hilosAdminChildren(
  page: string,
  params: Record<string, string> = {},
): HilosAdminChild[] {
  return Object.keys(HILOS_ADMIN_PAGES)
    .filter((key) => HILOS_ADMIN_PAGES[key].parent === page)
    .filter((key) => isResolvable(key, params))
    .map((key) => ({
      page: key,
      label: HILOS_ADMIN_PAGES[key].label,
      lead: HILOS_ADMIN_PAGES[key].lead,
      to: resolveHilosPath(key, params),
    }))
}
