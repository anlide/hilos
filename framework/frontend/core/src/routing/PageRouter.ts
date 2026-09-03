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
  /**
   * URL path template; `{name}` marks a route param captured at match time, and
   * `{name?}` marks one the path may end without.
   */
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

/** Matches a pathname to the page it names, and names the path of a page. */
export interface PageRouter {
  /**
   * Resolve the page key, route params, and surface type a pathname names. A
   * static template matches whole; a templated one captures its `{name}`
   * segments into params, and leaves a `{name?}` segment the path stopped short
   * of out of params entirely. An unmatched path resolves to the configured
   * fallback page with no params, carrying that page's own declared surface
   * type.
   *
   * @param pathname The location pathname to resolve.
   */
  match(pathname: string): PageRouteMatch
  /**
   * The path a page key names, with each `{name}` slot filled from `params`. An
   * unfilled `{name?}` slot is cut together with the slash in front of it, so a
   * page entered with none of its optional tail resolves to the bare path rather
   * than to one with empty segments.
   *
   * `undefined` for a page the router does not carry, and for one whose required
   * slot `params` does not cover: a link with no target is drawn as no link,
   * whereas a half-filled path would be an address that silently names something
   * else.
   *
   * @param page The page key to resolve.
   * @param params The route params to fill the slots from, defaulting to none.
   */
  path(page: string, params?: Record<string, string>): string | undefined
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

/** A template segment that is a route param: `{name}`, or `{name?}` optional. */
const SEGMENT_PARAM_PATTERN = /^\{(\w+)(\??)}$/

/** A route-template slot anywhere in a template: `{name}`, or `{name?}` optional. */
const ROUTE_SLOT_PATTERN = /\{(\w+)(\??)}/g

/** An optional `{name?}` slot together with the slash that precedes it. */
const OPTIONAL_ROUTE_SLOT_PATTERN = /\/\{(\w+)\?}/g

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
 * @param routes Page key to its route declaration; `{name}` marks a route param
 *   and `{name?}` one the path may end without.
 * @param options Router options, including the fallback page for no match.
 */
export function createPageRouter(
  routes: Record<string, HilosRouteDeclaration>,
  options: PageRouterOptions,
): PageRouter {
  const staticRoutes: Record<string, StaticRoute> = {}
  const paramRoutes: CompiledRoute[] = []
  // The templates are kept as written beside their compiled form: matching reads
  // the regex, naming a page's path reads the template back, and the two answers
  // have to agree or a link would lead where nothing matches.
  const templates: Record<string, string> = {}

  for (const [page, declaration] of Object.entries(routes)) {
    const { path, admin } = declaration
    templates[page] = path
    if (!path.includes('{')) {
      staticRoutes[path] = { page, admin }
      continue
    }
    const paramNames: string[] = []
    // The separator is emitted per segment rather than by joining, because an
    // optional slot has to swallow the slash in front of it: the whole `/value`
    // is what the path may end without, and a slash left outside the group
    // would demand a trailing one.
    let pattern = ''
    path.split('/').forEach((segment, index) => {
      const separator = index === 0 ? '' : '/'
      const param = segment.match(SEGMENT_PARAM_PATTERN)
      if (param === null) {
        pattern += separator + escapeSegment(segment)
        return
      }
      paramNames.push(param[1])
      pattern += param[2] === '?' ? '(?:/([^/]+))?' : `${separator}([^/]+)`
    })
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
          // An optional slot the path stopped short of captures nothing, and is
          // left out of params entirely rather than added as an empty string —
          // the page reads it as absent, not as present and blank.
          const value = captured[index + 1]
          if (value !== undefined) {
            params[name] = value
          }
        })
        return { page: route.page, params, admin: route.admin }
      }
      return { page: options.fallback, params: {}, admin: fallbackAdmin }
    },
    path(
      page: string,
      params: Record<string, string> = {},
    ): string | undefined {
      const template = templates[page]
      if (template === undefined) {
        return undefined
      }
      // A required slot with nothing to put in it is noticed while substituting,
      // not by reading the result back: the result of dropping one is an address
      // that still looks like an address and names another page's row.
      let covered = true
      const resolved = template
        .replace(OPTIONAL_ROUTE_SLOT_PATTERN, (_match, name: string) => {
          const value = params[name]

          return value === undefined ? '' : `/${value}`
        })
        .replace(
          ROUTE_SLOT_PATTERN,
          (_match, name: string, optional: string) => {
            const value = params[name]
            if (value !== undefined) {
              return value
            }
            if (optional !== '?') {
              covered = false
            }

            return ''
          },
        )

      return covered ? resolved : undefined
    },
  }
}
