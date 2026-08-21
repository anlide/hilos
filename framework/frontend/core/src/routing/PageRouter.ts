// The cold-load page router: turns a project's page-key → route-declaration map
// into a matcher that resolves a pathname to its page key, captured route
// params, and surface type. Framework-agnostic and project-agnostic — it knows
// nothing about which pages exist, only how to compile `{name}` templates and
// match a path against them, so every Hilos project and view adapter shares one
// routing engine instead of reimplementing it.

/**
 * A declared route: the URL template a page answers on and whether that surface
 * is administrative. Both fields are required on every row — an optional flag
 * would turn a forgotten row into a silent "not administrative", which is a
 * defect that hides rather than shows itself.
 */
export interface HilosRouteDeclaration {
  /** URL path template; `{name}` marks a route param captured at match time. */
  path: string
  /**
   * Whether the route names an administrative surface. It states the surface
   * *type*, not any one feature's use of it, so later readers share the marker
   * instead of adding a row of their own.
   */
  admin: boolean
}

/**
 * A resolved cold-load route: the page key, its captured URL params, and the
 * surface type declared for it.
 */
export interface PageRouteMatch {
  page: string
  params: Record<string, string>
  admin: boolean
}

/** Matches a pathname to the page it names. */
export interface PageRouter {
  /**
   * Resolve the page key, route params, and surface type a pathname names. A
   * static template matches whole; a templated one captures its `{name}`
   * segments into params. An unmatched path resolves to the configured fallback
   * page with no params, carrying that page's own declared surface type.
   *
   * @param pathname The location pathname to resolve.
   */
  match(pathname: string): PageRouteMatch
}

/** Router construction options. */
export interface PageRouterOptions {
  /** Page key returned for a pathname no template matches. */
  fallback: string
}

/** A compiled static route: the page key it names and its surface type. */
interface StaticRoute {
  page: string
  admin: boolean
}

/** A compiled templated route: its page key, surface type, regex, and params. */
interface CompiledRoute {
  page: string
  admin: boolean
  regex: RegExp
  paramNames: string[]
}

/** Escape a literal path segment so its characters carry no regex meaning. */
function escapeSegment(segment: string): string {
  return segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Build a page router over a page-key → route-declaration map. A template is
 * static when it has no `{name}` segment and matches by exact lookup; otherwise
 * each `{name}` becomes a single-segment capture. Static paths are kept apart
 * from templated ones so they win and never collide with a param route at the
 * same depth. The declared `admin` flag is carried onto the match, never
 * interpreted here — the engine routes, the surface decides what the type means.
 *
 * @param routes Page key to its route declaration; `{name}` marks a route param.
 * @param options Router options, including the fallback page for no match.
 */
export function createPageRouter(
  routes: Record<string, HilosRouteDeclaration>,
  options: PageRouterOptions,
): PageRouter {
  const staticRoutes: Record<string, StaticRoute> = {}
  const paramRoutes: CompiledRoute[] = []

  for (const [page, declaration] of Object.entries(routes)) {
    const { path, admin } = declaration
    if (!path.includes('{')) {
      staticRoutes[path] = { page, admin }
      continue
    }
    const paramNames: string[] = []
    const pattern = path
      .split('/')
      .map((segment) => {
        const param = segment.match(/^\{([^}]+)}$/)
        if (param !== null) {
          paramNames.push(param[1])
          return '([^/]+)'
        }
        return escapeSegment(segment)
      })
      .join('/')
    paramRoutes.push({
      page,
      admin,
      regex: new RegExp(`^${pattern}$`),
      paramNames,
    })
  }

  // The fallback answers paths no row claims, so its surface type is its own
  // declaration's; a fallback key with no row is not an administrative surface.
  const fallbackAdmin = routes[options.fallback]?.admin ?? false

  return {
    match(pathname: string): PageRouteMatch {
      const staticRoute = staticRoutes[pathname]
      if (staticRoute !== undefined) {
        return { page: staticRoute.page, params: {}, admin: staticRoute.admin }
      }
      for (const route of paramRoutes) {
        const captured = route.regex.exec(pathname)
        if (captured === null) {
          continue
        }
        const params: Record<string, string> = {}
        route.paramNames.forEach((name, index) => {
          params[name] = captured[index + 1]
        })
        return { page: route.page, params, admin: route.admin }
      }
      return { page: options.fallback, params: {}, admin: fallbackAdmin }
    },
  }
}
