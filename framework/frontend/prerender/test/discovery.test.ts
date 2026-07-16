// discoverPublicRoutes against the real framework catalog: every footer link
// resolves to its route + component, and a missing component is a build error.
// The defensive unrouted-link branch is covered in discovery-unrouted.test.ts.
import { HILOS_FOOTER_LINKS, HILOS_PAGE_ROUTES } from '@hilos/core'
import { expect, it } from 'vitest'

import { discoverPublicRoutes } from '../src/discovery.js'

/** A component per public footer page — the value is opaque to discovery. */
function allComponents(): Record<string, string> {
  return Object.fromEntries(
    HILOS_FOOTER_LINKS.map(({ page }) => [page, `component:${page}`]),
  )
}

it('resolves every footer page to its route and component', () => {
  const resolved = discoverPublicRoutes(allComponents())

  expect(resolved).toHaveLength(HILOS_FOOTER_LINKS.length)
  for (const { page, route, component } of resolved) {
    expect(route).toBe(HILOS_PAGE_ROUTES[page])
    expect(component).toBe(`component:${page}`)
  }
})

it('throws when a footer page has no content component', () => {
  const components = allComponents()
  const missing = HILOS_FOOTER_LINKS[0].page
  delete components[missing]

  expect(() => discoverPublicRoutes(components)).toThrow(/no content component/)
})
