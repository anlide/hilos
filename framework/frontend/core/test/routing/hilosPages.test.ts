import { describe, expect, it } from 'vitest'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  HILOS_FOOTER_LINKS,
} from '../../src/routing/hilosPages.js'

describe('HILOS_ROUTE_DECLARATIONS', () => {
  it('declares a route for every Hilos page key', () => {
    for (const key of Object.values(HilosPages)) {
      expect(HILOS_ROUTE_DECLARATIONS[key].path).toBeTypeOf('string')
      expect(HILOS_ROUTE_DECLARATIONS[key].admin).toBeTypeOf('boolean')
    }
    expect(Object.keys(HILOS_ROUTE_DECLARATIONS)).toHaveLength(
      Object.values(HilosPages).length,
    )
  })

  it('mounts admin paths under /hilos and personal/public pages at the root', () => {
    // Root-routed framework pages: the public footer pages plus the personal
    // profile page. Every other Hilos page is admin and lives under /hilos.
    const rootPages = new Set<string>([
      HilosPages.PROFILE,
      ...HILOS_FOOTER_LINKS.map((l) => l.page),
    ])
    for (const [page, declaration] of Object.entries(
      HILOS_ROUTE_DECLARATIONS,
    )) {
      expect(declaration.path.startsWith('/hilos')).toBe(!rootPages.has(page))
      // The address is not the rule — a project may mount its admin anywhere —
      // but the framework's own rows must agree with their own convention, or a
      // mistyped flag hides the verifier form from a page that needs it.
      expect(declaration.admin).toBe(declaration.path.startsWith('/hilos'))
    }
  })

  it('derives the path map from the declarations', () => {
    expect(HILOS_PAGE_ROUTES).toEqual(
      Object.fromEntries(
        Object.entries(HILOS_ROUTE_DECLARATIONS).map(([page, declaration]) => [
          page,
          declaration.path,
        ]),
      ),
    )
  })

  it('exposes the public framework pages as footer links with root routes', () => {
    expect(HILOS_FOOTER_LINKS.map((link) => link.page)).toEqual([
      HilosPages.ABOUT,
      HilosPages.TERMS,
      HilosPages.PRIVACY,
      HilosPages.LICENSE,
    ])
    for (const link of HILOS_FOOTER_LINKS) {
      expect(link.label).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page]).toBeTypeOf('string')
      expect(HILOS_PAGE_ROUTES[link.page].startsWith('/hilos')).toBe(false)
    }
  })

  it('routes its own templated paths back to their page keys', () => {
    const router = createPageRouter(HILOS_ROUTE_DECLARATIONS, {
      fallback: HilosPages.DASHBOARD,
    })
    expect(router.match('/hilos').page).toBe(HilosPages.DASHBOARD)
    expect(router.match('/profile').page).toBe(HilosPages.PROFILE)
    expect(router.match('/about').page).toBe(HilosPages.ABOUT)
    expect(router.match('/hilos/users')).toEqual({
      page: HilosPages.USERS,
      params: {},
      admin: true,
    })
    expect(router.match('/hilos/user/5')).toEqual({
      page: HilosPages.USER,
      params: { userId: '5' },
      admin: true,
    })
    expect(router.match('/hilos/billing/stripe/payments')).toEqual({
      page: HilosPages.BILLING_PAYMENTS,
      params: { providerId: 'stripe' },
      admin: true,
    })
  })
})
