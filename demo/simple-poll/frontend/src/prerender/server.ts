// Prerender the public footer pages (About / Terms / Privacy / License) to
// static HTML — the SSG half of the hybrid build (docs/agents/frontend/build-and-docker.md).
// Angular's builder-driven prerender expects an Angular-Router app, but the poll
// app routes through the framework's HilosRouter, so this hand-rolls the native
// server renderer instead: `renderApplication` (which sets up the server platform
// itself) renders each public component into the client index.html. The build
// compiles this as the ssr entry (angular.json `prerender` configuration) and a
// post-build step runs it (package.json). The client app
// (src/app/bootstrap/main.ts) is a separate bootstrap, never rendered here, so
// the socket it opens via bootHilos never runs during the build.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import type { Type } from '@angular/core'
import { renderApplication } from '@angular/platform-server'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  resolvePageTitle,
} from '@hilos/core'

import { appName, pageTitles } from '../app/pages/pageTitles'
import { About } from '../app/views/about/about'
import { License } from '../app/views/license/license'
import { Privacy } from '../app/views/privacy/privacy'
import { Terms } from '../app/views/terms/terms'
import bootstrap, { setPrerenderPage } from './main.server'

// The content component for each public page key. The framework owns the page
// identity and routes (HILOS_FOOTER_LINKS / HILOS_PAGE_ROUTES); the demo owns
// the content, so supplying the components is the demo's concern.
const publicPages: Record<string, Type<unknown>> = {
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
}

// This runner is built into a throwaway dist-prerender/server/ (its own
// `prerender` configuration in angular.json) so the real `ng build` stays a flat
// dist/ that nginx and the daemon already expect. The prerendered pages and the
// index.html template it reads both live in that real dist/, two levels up.
const distDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'dist')
const template = readFileSync(join(distDir, 'index.html'), 'utf8')

for (const { page } of HILOS_FOOTER_LINKS) {
  const route = HILOS_PAGE_ROUTES[page]
  const component = publicPages[page]
  if (route === undefined || component === undefined) {
    continue
  }

  setPrerenderPage(component)
  const title = resolvePageTitle(page, pageTitles, appName)
  const document = template.replace(
    /<title>[^<]*<\/title>/,
    `<title>${title}</title>`,
  )
  const html = await renderApplication(bootstrap, { document, url: route })
  writeFileSync(join(distDir, `${route.replace(/^\//, '')}.html`), html)
}
