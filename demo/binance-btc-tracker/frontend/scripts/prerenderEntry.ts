// Prerender the public footer pages (About / Terms / Privacy / License) to
// static HTML — the SSG half of the hybrid build (docs/agents/frontend/build-and-docker.md).
// Each is a framework-declared static page whose content needs no socket, so it
// renders through Vue's native server renderer with no live connection. This
// module is built for Node by Vite (`vite build --ssr`) and then executed: it
// reads the client build's index.html and writes one <route>.html per public
// page, plus robots.txt and a sitemap of those routes. The authenticated,
// real-time area is never prerendered — nginx serves these files for the public
// routes and falls back to index.html (the SPA shell) for the app's own deep
// links.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  resolvePageTitle,
} from '@hilos/core'
import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'

import { appName, pageTitles } from '../src/pages/pageTitles'
import About from '../src/views/About/About.vue'
import License from '../src/views/License/License.vue'
import Privacy from '../src/views/Privacy/Privacy.vue'
import Terms from '../src/views/Terms/Terms.vue'

// The content component for each public page key. The framework owns the page
// identity and routes (HILOS_FOOTER_LINKS / HILOS_PAGE_ROUTES); the demo owns
// the content, so supplying the components is the demo's concern.
const publicPages: Record<string, Component> = {
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
}

// A real deployment templates its canonical origin; the demo hardcodes the test
// and prod nginx origin, which is all robots.txt and sitemap.xml advertise here.
const siteOrigin = 'https://localhost'

const distDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'dist')
const template = readFileSync(join(distDir, 'index.html'), 'utf8')

const prerenderedRoutes: string[] = []

for (const { page } of HILOS_FOOTER_LINKS) {
  const route = HILOS_PAGE_ROUTES[page]
  const component = publicPages[page]
  if (route === undefined || component === undefined) {
    continue
  }

  const body = await renderToString(
    createSSRApp({ render: () => h(component) }),
  )
  // No catalog label and nothing to wait for: prerendering runs at build time
  // with no socket, and the pages it renders are the public footer ones, whose
  // labels the frontend owns outright.
  const title = resolvePageTitle(page, pageTitles, appName, undefined, true)
  const html = template
    .replace('<div id="app"></div>', `<div id="app">${body}</div>`)
    .replace(/<title>[^<]*<\/title>/, `<title>${title}</title>`)

  writeFileSync(join(distDir, `${route.replace(/^\//, '')}.html`), html)
  prerenderedRoutes.push(route)
}

writeFileSync(
  join(distDir, 'robots.txt'),
  `User-agent: *\nAllow: /\nSitemap: ${siteOrigin}/sitemap.xml\n`,
)

const urls = ['/', ...prerenderedRoutes]
  .map((path) => `  <url><loc>${siteOrigin}${path}</loc></url>`)
  .join('\n')
writeFileSync(
  join(distDir, 'sitemap.xml'),
  `<?xml version="1.0" encoding="UTF-8"?>\n` +
    `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n` +
    `${urls}\n</urlset>\n`,
)
