import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  resolvePageTitle,
} from '@hilos/core/base'
import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'

import { PAGE_HISTORY, PAGE_MAIN } from '../src/pages/keys.js'
import { appName, pageTitles } from '../src/pages/pageTitles.js'
import { router } from '../src/pages/routes.js'
import About from '../src/views/About/About.vue'
import History from '../src/views/History/History.vue'
import License from '../src/views/License/License.vue'
import Main from '../src/views/Main/Main.vue'
import Privacy from '../src/views/Privacy/Privacy.vue'
import Terms from '../src/views/Terms/Terms.vue'

const publicPages: Record<string, Component> = {
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
}

/** The site's own static pages, prerendered like the footer pages. */
const projectPages: Record<string, Component> = {
  [PAGE_HISTORY]: History,
}

/** Resolve the build-time origin used by search-engine metadata. */
export function siteOrigin(env: Record<string, string | undefined>): string {
  return (env.HILOS_SITE_ORIGIN ?? 'https://localhost').replace(/\/$/, '')
}

/** Escape text used in HTML attributes and XML URLs. */
function escapeAttribute(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

/** Render one static page into the template under its document title. */
async function renderStaticPage(
  template: string,
  page: string,
  component: Component,
): Promise<string> {
  const body = await renderToString(
    createSSRApp({ render: () => h(component) }),
  )
  const title = resolvePageTitle(page, pageTitles, appName, undefined, true)
  return template
    .replace('<div id="app"></div>', () => `<div id="app">${body}</div>`)
    .replace(
      /<title>[^<]*<\/title>/,
      () => `<title>${escapeAttribute(title)}</title>`,
    )
}

/** Render the site's public files without browser globals or filesystem I/O. */
export async function renderSite(
  template: string,
  origin: string,
): Promise<Record<string, string>> {
  const files: Record<string, string> = {}
  const prerenderedRoutes: string[] = []

  for (const { page } of HILOS_FOOTER_LINKS) {
    const route = HILOS_PAGE_ROUTES[page]
    const component = publicPages[page]
    if (route === undefined || component === undefined) {
      continue
    }

    files[`${route.replace(/^\//, '')}.html`] = await renderStaticPage(
      template,
      page,
      component,
    )
    prerenderedRoutes.push(route)
  }

  for (const [page, component] of Object.entries(projectPages)) {
    const route = router.path(page)
    if (route === undefined) {
      continue
    }

    files[`${route.replace(/^\//, '')}.html`] = await renderStaticPage(
      template,
      page,
      component,
    )
    prerenderedRoutes.push(route)
  }

  const body = await renderToString(createSSRApp({ render: () => h(Main) }))
  const title = resolvePageTitle(
    PAGE_MAIN,
    pageTitles,
    appName,
    undefined,
    true,
  )
  const description =
    'Hilos is a real-time PHP framework that keeps connected applications in sync without page reloads.'
  const PREVIEW_IMAGE = '/hilos-preview-512.png'
  const metadata = [
    `<meta name="description" content="${escapeAttribute(description)}">`,
    '<meta property="og:type" content="website">',
    '<meta property="og:site_name" content="Hilos">',
    `<meta property="og:title" content="${escapeAttribute(title)}">`,
    `<meta property="og:description" content="${escapeAttribute(description)}">`,
    `<meta property="og:url" content="${escapeAttribute(origin + '/')}">`,
    `<meta property="og:image" content="${escapeAttribute(origin + PREVIEW_IMAGE)}">`,
    '<meta property="og:image:type" content="image/png">',
    '<meta property="og:image:width" content="512">',
    '<meta property="og:image:height" content="512">',
    '<meta property="og:image:alt" content="Hilos logo: a blue circle with a yellow lightning bolt">',
    '<meta name="twitter:card" content="summary">',
    `<link rel="canonical" href="${escapeAttribute(origin + '/')}">`,
  ].join('\n')
  files['home.html'] = template
    .replace('<div id="app"></div>', () => `<div id="app">${body}</div>`)
    .replace(
      /<title>[^<]*<\/title>/,
      () => `<title>${escapeAttribute(title)}</title>`,
    )
    .replace('</head>', () => `${metadata}\n</head>`)
  files['robots.txt'] =
    `User-agent: *\nAllow: /\nSitemap: ${origin}/sitemap.xml\n`

  const urls = ['/', ...prerenderedRoutes]
    .map((path) => `  <url><loc>${escapeAttribute(origin + path)}</loc></url>`)
    .join('\n')
  files['sitemap.xml'] =
    `<?xml version="1.0" encoding="UTF-8"?>\n` +
    `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n` +
    `${urls}\n</urlset>\n`
  return files
}
