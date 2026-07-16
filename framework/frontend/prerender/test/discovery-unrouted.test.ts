// The defensive branch of discoverPublicRoutes: a footer link declared with no
// route in HILOS_PAGE_ROUTES is a build error. A mocked catalog is used because
// the real one routes every footer page, so this state cannot arise from it.
import { expect, it, vi } from 'vitest'

vi.mock('@hilos/core', () => ({
  HILOS_FOOTER_LINKS: [{ page: 'ghost', label: 'Ghost' }],
  HILOS_PAGE_ROUTES: {},
}))

const { discoverPublicRoutes } = await import('../src/discovery.js')

it('throws when a footer link has no route', () => {
  expect(() => discoverPublicRoutes({ ghost: 'component:ghost' })).toThrow(
    /no route in HILOS_PAGE_ROUTES/,
  )
})
