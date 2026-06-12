import { describe, expect, it } from 'vitest'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { HilosPages, HILOS_PAGE_ROUTES } from '../../src/routing/hilosPages.js'

describe('HILOS_PAGE_ROUTES', () => {
  it('declares a route for every Hilos page key', () => {
    for (const key of Object.values(HilosPages)) {
      expect(HILOS_PAGE_ROUTES[key]).toBeTypeOf('string')
    }
    expect(Object.keys(HILOS_PAGE_ROUTES)).toHaveLength(
      Object.values(HilosPages).length,
    )
  })

  it('mounts every admin path under /hilos', () => {
    for (const template of Object.values(HILOS_PAGE_ROUTES)) {
      expect(template.startsWith('/hilos')).toBe(true)
    }
  })

  it('routes its own templated paths back to their page keys', () => {
    const router = createPageRouter(HILOS_PAGE_ROUTES, {
      fallback: HilosPages.DASHBOARD,
    })
    expect(router.match('/hilos').page).toBe(HilosPages.DASHBOARD)
    expect(router.match('/hilos/users/5')).toEqual({
      page: HilosPages.USER,
      params: { userId: '5' },
    })
    expect(router.match('/hilos/billing/stripe/payments')).toEqual({
      page: HilosPages.BILLING_PAYMENTS,
      params: { providerId: 'stripe' },
    })
  })
})
