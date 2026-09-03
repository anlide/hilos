// The prerender server bootstrap: Angular's native prerenderer (angular.json
// production `outputMode: static` + this `server` entry) renders each public
// footer page to static HTML through it (docs/agents/frontend/build-and-docker.md).
// The client app (src/app/bootstrap/main.ts) routes through the framework's
// HilosRouter and never loads @angular/router; but the native prerenderer
// discovers routes through @angular/router, so this server-only bootstrap
// declares the public pages as an Angular Router config and hands their SSG
// render modes to provideServerRendering. Kept free of top-level side effects so
// importing it for the server build has none.
import { Component, type Type } from '@angular/core'
import {
  bootstrapApplication,
  type BootstrapContext,
} from '@angular/platform-browser'
import { provideRouter, RouterOutlet, type Routes } from '@angular/router'
import { provideServerRendering, withRoutes } from '@angular/ssr'
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
import { serverRoutes } from './app.routes.server'

// The content component for each public page key. The framework owns the page
// identity and routes (HILOS_FOOTER_LINKS / HILOS_PAGE_ROUTES); the demo owns the
// content, so supplying the components is the demo's concern.
const publicPages: Record<string, Type<unknown>> = {
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
}

// The Angular Router config the prerender pass resolves each route against: the
// framework route path mapped to the demo's content component, titled from the
// framework catalog through resolvePageTitle (Angular's default TitleStrategy
// writes it into the prerendered <title>). Paths are relative, matching
// app.routes.server.ts.
const routes: Routes = HILOS_FOOTER_LINKS.flatMap(({ page }) => {
  const route = HILOS_PAGE_ROUTES[page]
  const component = publicPages[page]
  if (route === undefined || component === undefined) {
    return []
  }
  return [
    {
      path: route.replace(/^\//, ''),
      component,
      // No catalog label and nothing to wait for: prerendering runs at build
      // time with no socket, and the pages it renders are the public footer
      // ones, whose labels the frontend owns outright.
      title: resolvePageTitle(page, pageTitles, appName, undefined, true),
    },
  ]
})

// The SSR root: a bare router outlet that renders the matched public component
// into the client index.html <app-root>. The real client app (src/app/app.ts) is
// a separate bootstrap that routes through HilosRouter, never this shell.
@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  template: '<router-outlet />',
})
export class PrerenderRoot {}

export default function bootstrap(context: BootstrapContext) {
  return bootstrapApplication(
    PrerenderRoot,
    {
      providers: [
        provideRouter(routes),
        provideServerRendering(withRoutes(serverRoutes)),
      ],
    },
    context,
  )
}
