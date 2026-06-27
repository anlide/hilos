// The cold-load page router: turns a project's page-key → URL-template map into
// a matcher that resolves a pathname to its page key and captured route params.
// Framework-agnostic and project-agnostic — it knows nothing about which pages
// exist, only how to compile `{name}` templates and match a path against them,
// so every Hilos project and view adapter shares one routing engine instead of
// reimplementing it.

/** A resolved cold-load route: the page key and its captured URL params. */
export interface PageRouteMatch {
  page: string
  params: Record<string, string>
}

/** Matches a pathname to the page it names. */
export interface PageRouter {
  /**
   * Resolve the page key and route params a pathname names. A static template
   * matches whole; a templated one captures its `{name}` segments into params.
   * An unmatched path resolves to the configured fallback page with no params.
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

/** A compiled templated route: its page key, segment regex, and param order. */
interface CompiledRoute {
  page: string
  regex: RegExp
  paramNames: string[]
}

/** Escape a literal path segment so its characters carry no regex meaning. */
function escapeSegment(segment: string): string {
  return segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Build a page router over a page-key → URL-template map. A template is static
 * when it has no `{name}` segment and matches by exact lookup; otherwise each
 * `{name}` becomes a single-segment capture. Static paths are kept apart from
 * templated ones so they win and never collide with a param route at the same
 * depth.
 *
 * @param routes Page key to URL path template; `{name}` marks a route param.
 * @param options Router options, including the fallback page for no match.
 */
export function createPageRouter(
  routes: Record<string, string>,
  options: PageRouterOptions,
): PageRouter {
  const staticRoutes: Record<string, string> = {}
  const paramRoutes: CompiledRoute[] = []

  for (const [page, template] of Object.entries(routes)) {
    if (!template.includes('{')) {
      staticRoutes[template] = page
      continue
    }
    const paramNames: string[] = []
    const pattern = template
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
    paramRoutes.push({ page, regex: new RegExp(`^${pattern}$`), paramNames })
  }

  return {
    match(pathname: string): PageRouteMatch {
      const staticPage = staticRoutes[pathname]
      if (staticPage !== undefined) {
        return { page: staticPage, params: {} }
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
        return { page: route.page, params }
      }
      return { page: options.fallback, params: {} }
    },
  }
}
