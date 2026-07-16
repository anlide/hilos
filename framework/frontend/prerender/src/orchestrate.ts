// The framework-owned, build-time orchestration of the SSG prerender: read the
// client template, resolve the public footer pages, render each through the view
// layer's renderRoute, and atomically write `<route>.html`. Build fail-fast — the
// first render failure rejects and nothing partial is written past it (HIL-211
// Flow Q1). Returns the written routes for the SEO step (HIL-214).

import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { resolvePageTitle } from '@hilos/core'

import { discoverPublicRoutes } from './discovery.js'
import type { PrerenderConfig } from './types.js'
import { writeFileAtomic } from './writeFileAtomic.js'

/** The client build's SPA shell, the template every `<route>.html` extends. */
const CLIENT_TEMPLATE = 'index.html'

/**
 * Prerender every public footer page to a static `<route>.html` under
 * `config.distDir`.
 *
 * @param config The components, render primitive, dist directory, and titles.
 * @returns The routes written, in footer order, for robots.txt / sitemap.xml.
 * @throws Error if a footer link is unresolved ({@link discoverPublicRoutes}) or
 *   a page fails to render — the build fails before writing anything further.
 */
export async function prerenderPublicRoutes<TComponent>(
  config: PrerenderConfig<TComponent>,
): Promise<string[]> {
  const template = readFileSync(join(config.distDir, CLIENT_TEMPLATE), 'utf8')
  const pages = discoverPublicRoutes(config.components)

  const written: string[] = []
  for (const { page, route, component } of pages) {
    const title = resolvePageTitle(page, config.pageTitles, config.appName)
    const result = await config.renderRoute({
      route,
      component,
      template,
      title,
    })
    if (!result.ok) {
      throw new Error(`Prerender failed for ${route}: ${result.error}`)
    }

    writeFileAtomic(join(config.distDir, routeToFileName(route)), result.html)
    written.push(route)
  }

  return written
}

/** Map a route path to its output file name: `/about` → `about.html`. */
function routeToFileName(route: string): string {
  return `${route.replace(/^\//, '')}.html`
}
