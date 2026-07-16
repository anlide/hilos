// The framework-agnostic contract for the SSG prerender pipeline: the render
// primitive a view layer supplies, the result it returns, a resolved public
// page, and the orchestration config. The build-time orchestration
// (orchestrate.ts) and the per-framework renderers (HIL-212/213) share these
// types so the loop over HILOS_FOOTER_LINKS lives once in the framework and each
// view layer contributes only a renderRoute. Design lives in
// docs/agents/frontend/build-and-docker.md.

/**
 * The outcome of rendering one public route. A render failure is a value, not a
 * thrown error, so a caller can keep the previously published `<route>.html` and
 * surface the message — build fail-fast, runtime keep-old (HIL-211 Flow Q1).
 */
export type RenderResult =
  | { ok: true; html: string }
  | { ok: false; error: string }

/**
 * Renders one public page to a full HTML document. Each view layer supplies its
 * own: Vue and React render a body fragment and inject it into the client
 * template via {@link injectIntoTemplate}, while Angular's `renderApplication`
 * templates itself — so the grain is a complete document, not a body fragment.
 *
 * @typeParam TComponent The view layer's component type (a Vue, React, or
 *   Angular component); the framework never inspects it.
 */
export type RenderRoute<TComponent> = (input: {
  /** The public route path, e.g. `/about`. */
  route: string
  /** The content component for this page. */
  component: TComponent
  /** The client build's `index.html`, used as the document shell. */
  template: string
  /** The resolved document title for this page. */
  title: string
}) => Promise<RenderResult>

/** A public footer page resolved to its route and content component. */
export interface ResolvedPublicPage<TComponent> {
  /** The page key, a `HilosPages` value from `HILOS_FOOTER_LINKS`. */
  page: string
  /** The route path from `HILOS_PAGE_ROUTES`, e.g. `/about`. */
  route: string
  /** The project-supplied content component for the page. */
  component: TComponent
}

/**
 * Everything the build-time orchestration needs to prerender the public surface.
 * The project supplies the content components and its page titles, the view
 * layer supplies the renderRoute, and the framework owns the loop and the
 * `<route>.html` output contract.
 *
 * @typeParam TComponent The view layer's component type.
 */
export interface PrerenderConfig<TComponent> {
  /** Page key → content component, for the public footer pages. */
  components: Record<string, TComponent>
  /** The view layer's render primitive. */
  renderRoute: RenderRoute<TComponent>
  /**
   * The client build directory: the `index.html` template is read from here and
   * each `<route>.html` is written back into it.
   */
  distDir: string
  /** Project page key → title, forwarded to `resolvePageTitle`. */
  pageTitles: Record<string, string>
  /** The application name composed into every title. */
  appName: string
}
