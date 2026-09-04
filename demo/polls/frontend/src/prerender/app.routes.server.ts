// The SSG route table Angular's native prerenderer walks. Each framework footer
// page (About / Terms / Privacy / License; HILOS_FOOTER_LINKS) is one prerendered
// route, emitted at build time as a static <route>/index.html the nginx hybrid
// config serves (docs/agents/frontend/build-and-docker.md). The authed, real-time
// area is never prerendered, so no other route is listed and no server/CSR
// fallback is declared. The matching @angular/router config lives in main.server.ts.
import { RenderMode, type ServerRoute } from '@angular/ssr'
import { HILOS_FOOTER_LINKS, HILOS_PAGE_ROUTES } from '@hilos/core'

export const serverRoutes: ServerRoute[] = HILOS_FOOTER_LINKS.flatMap(({ page }) => {
  const route = HILOS_PAGE_ROUTES[page]
  return route === undefined
    ? []
    : [{ path: route.replace(/^\//, ''), renderMode: RenderMode.Prerender }]
})
