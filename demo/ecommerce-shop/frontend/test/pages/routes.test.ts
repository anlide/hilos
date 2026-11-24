import { describe, expect, it } from 'vitest'

import { router } from '../../src/pages/routes.js'

describe('shop admin routes', () => {
  it.each([
    '/hilos/app/warehouses',
    '/hilos/app/categories',
    '/hilos/app/products',
    '/hilos/app/product/17',
    '/hilos/app/product/17/stock',
    '/hilos/app/vouchers',
  ])('leaves the former address %s unregistered', (formerPath) => {
    expect(router.match(formerPath)).toEqual({
      page: null,
      params: {},
      admin: false,
    })
  })
})
