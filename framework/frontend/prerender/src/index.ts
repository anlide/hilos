// The @hilos/prerender public surface: the SSG prerender pipeline core. The
// build-time orchestration (prerenderPublicRoutes) and its pieces — public-route
// discovery, template injection, atomic write, and the site-origin env — plus
// the render-primitive contract each view layer implements (HIL-212/213).

export { discoverPublicRoutes } from './discovery.js'
export { prerenderPublicRoutes } from './orchestrate.js'
export { injectIntoTemplate } from './template.js'
export { writeFileAtomic } from './writeFileAtomic.js'
export { resolveSiteOrigin } from './env.js'
export type {
  RenderResult,
  RenderRoute,
  ResolvedPublicPage,
  PrerenderConfig,
} from './types.js'
