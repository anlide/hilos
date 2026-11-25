import {
  HilosConnection,
  PAGE_SIGNAL_SCHEMAS,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
  ScopeManager,
  bindPageScope,
  createHilosRouter,
  createSignal,
  ingest,
  type WebSocketLike,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { PAGE_CATEGORY, PAGE_PRODUCT } from '../../../src/pages/keys.js'
import { router } from '../../../src/pages/routes.js'
import {
  createProductCartCountSignal,
  createProductDetailsSignal,
  createProductPageResponseMatcher,
  createProductViewerCountSignal,
} from '../../../src/views/Product/productPage.js'
import { type ProductDetails } from '../../../src/views/Product/types/ProductDetails.js'

const product: ProductDetails = {
  id: 8,
  slug: 'керамічний-горщик',
  name: 'Ceramic pot',
  kind: 'stock',
  price: 41000,
  description: 'Glazed ceramic with a drainage hole.',
  pictureUrl: '/pictures/pot.webp',
  units: 42,
  availability: 'in_stock',
  categoryName: 'Pots & planters',
  categorySlug: 'pots',
}

describe('product page payload', () => {
  it('reads a complete snapshot at the public route with a decoded slug', () => {
    const route = router.match(`/product/${encodeURIComponent(product.slug)}`)
    expect(route).toEqual({
      page: PAGE_PRODUCT,
      admin: false,
      params: { slug: product.slug },
    })

    const scopes = new ScopeManager()
    const scope = scopes.openPage(PAGE_PRODUCT)
    const details = createProductDetailsSignal(scopes, createSignal(route))

    expect(details.get()).toBeNull()
    ingest(scope, { data: { product } })
    expect(details.get()).toEqual(product)
  })

  it('requires the complete snapshot and the wire field types', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(PAGE_PRODUCT)
    const route = createSignal({
      page: PAGE_PRODUCT,
      admin: false,
      params: { slug: product.slug },
    })
    const details = createProductDetailsSignal(scopes, route)

    const { price, ...withoutPrice } = product
    const invalidSnapshots = [
      withoutPrice,
      { ...product, id: 0 },
      { ...product, price: price + 0.5 },
      { ...product, units: -1 },
      { ...product, kind: 'unknown' },
      { ...product, availability: 'sold' },
      { ...product, pictureUrl: null },
      { ...product, categorySlug: undefined },
    ]
    for (const snapshot of invalidSnapshots) {
      ingest(scope, { data: { product: snapshot } })
      expect(details.get()).toBeNull()
    }
  })
})

describe('product page navigation', () => {
  it('ignores the product data on a different page with the same slug', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage(PAGE_CATEGORY)
    const route = createSignal({
      page: PAGE_CATEGORY,
      admin: false,
      params: { slug: product.slug },
    })
    const details = createProductDetailsSignal(scopes, route)

    ingest(scope, { data: { product } })
    expect(details.get()).toBeNull()
  })

  it('hides the previous snapshot on navigation and ignores a late reply', () => {
    const next = { ...product, id: 9, slug: 'clay-pot', name: 'Clay pot' }
    const route = createSignal(
      router.match(`/product/${encodeURIComponent(product.slug)}`),
    )
    const scopes = new ScopeManager()
    const firstScope = scopes.openPage(PAGE_PRODUCT)
    const details = createProductDetailsSignal(scopes, route)
    ingest(firstScope, { data: { product } })
    expect(details.get()).toEqual(product)

    route.set(router.match(`/product/${next.slug}`))
    expect(details.get()).toBeNull()

    const nextScope = scopes.openPage(PAGE_PRODUCT)
    ingest(nextScope, { data: { product } })
    expect(details.get()).toBeNull()
    ingest(nextScope, { data: { product: next } })
    expect(details.get()).toEqual(next)
  })

  function openProduct(otherViewerCount?: number) {
    const listeners = new Map<
      string,
      Parameters<WebSocketLike['addEventListener']>[1]
    >()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
      webSocketFactory: () => ({
        send: () => {},
        close: () => {},
        addEventListener: (type, listener) => {
          listeners.set(type, listener)
        },
      }),
    })
    const scopes = new ScopeManager()
    const pages = bindPageScope(connection, scopes, {
      pageResponseMatchers: new Map([
        [PAGE_PRODUCT, createProductPageResponseMatcher(scopes)],
      ]),
    })
    const navigator = createHilosRouter(router, pages, {
      pathname: () => `/product/${encodeURIComponent(product.slug)}`,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    })
    const details = createProductDetailsSignal(scopes, navigator.currentRoute)
    const viewers = createProductViewerCountSignal(scopes, details)
    const carts = createProductCartCountSignal(scopes, details)
    const receive = (type: string, data: Record<string, unknown>) =>
      listeners.get('message')!({ data: JSON.stringify({ type, data }) })
    const pageData = (data: Record<string, unknown>) =>
      receive(SIGNAL_TYPE_PAGE_RESPONSE, {
        page: PAGE_PRODUCT,
        payload: { data },
      })
    const answer = (snapshot: Partial<ProductDetails>, count?: number) =>
      pageData({
        product: snapshot,
        productViewers:
          count === undefined
            ? undefined
            : { slug: snapshot.slug, otherViewerCount: count },
      })
    const viewerAnswer = (slug: string, otherViewerCount: unknown) =>
      pageData({ productViewers: { slug, otherViewerCount } })

    connection.connect()
    navigator.start()
    answer(product, otherViewerCount)
    expect(details.get()).toEqual(product)

    return {
      navigator,
      details,
      viewers,
      carts,
      receive,
      pageData,
      answer,
      viewerAnswer,
    }
  }

  it('keeps the current answer and its readiness when a previous slug answers late', () => {
    const { navigator, details, answer } = openProduct()
    const next = {
      ...product,
      id: 9,
      slug: 'глиняний-горщик',
      name: 'Clay pot',
    }
    navigator.navigate(`/product/${encodeURIComponent(next.slug)}`)
    expect(navigator.pageLoading.get()).toBe(true)

    answer(product)
    expect(details.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(true)

    answer(next)
    expect(details.get()).toEqual(next)
    expect(navigator.pageLoading.get()).toBe(false)

    answer(product)
    expect(details.get()).toEqual(next)
    expect(navigator.pageLoading.get()).toBe(false)

    const updated = { ...next, price: 42000 }
    answer(updated)
    expect(details.get()).toEqual(updated)
  })

  it('updates the current viewer count independently of product snapshots', () => {
    const { details, viewers, answer, viewerAnswer } = openProduct(0)
    expect(viewers.get()).toBe(0)

    const updated = { ...product, price: 42000 }
    answer(updated)
    expect(viewers.get()).toBe(0)
    viewerAnswer(product.slug, 3)
    expect(viewers.get()).toBe(3)
    expect(details.get()).toEqual(updated)

    for (const invalid of [-1, 1.5, '3']) {
      viewerAnswer(product.slug, invalid)
      expect(viewers.get()).toBe(3)
    }

    const latest = { ...updated, units: 41 }
    answer(latest)
    expect(details.get()).toEqual(latest)
    expect(viewers.get()).toBe(3)
  })

  it.each([
    { slug: 'previous-product', otherViewerCount: 4 },
    { slug: product.slug, otherViewerCount: 1.5 },
    { slug: product.slug, otherViewerCount: -1 },
  ])(
    'keeps the last count when an updated card brings %j',
    (productViewers) => {
      const { navigator, details, viewers, pageData, viewerAnswer } =
        openProduct(3)
      const updated = { ...product, price: 42000 }

      pageData({ product: updated, productViewers })
      expect(details.get()).toEqual(updated)
      expect(viewers.get()).toBe(3)
      expect(navigator.pageLoading.get()).toBe(false)

      viewerAnswer(product.slug, 4)
      expect(viewers.get()).toBe(4)
      expect(details.get()).toEqual(updated)
    },
  )

  it('drops the old viewer count on navigation and waits for a full answer', () => {
    const { navigator, details, viewers, pageData, viewerAnswer } =
      openProduct(2)
    const next = { ...product, id: 9, slug: 'clay-pot', name: 'Clay pot' }
    expect(viewers.get()).toBe(2)

    navigator.navigate(`/product/${next.slug}`)
    viewerAnswer(next.slug, 3)
    expect(viewers.get()).toBeUndefined()
    expect(details.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(true)

    pageData({
      product: next,
      productViewers: { slug: product.slug, otherViewerCount: 4 },
    })
    expect(details.get()).toEqual(next)
    expect(viewers.get()).toBeUndefined()
    expect(navigator.pageLoading.get()).toBe(false)

    viewerAnswer(next.slug, 0)
    expect(viewers.get()).toBe(0)
    viewerAnswer(product.slug, 4)
    expect(viewers.get()).toBe(0)
  })

  it('keeps the current 404 after a previous slug answers until the current slug is served', () => {
    const { navigator, details, viewers, receive, answer, viewerAnswer } =
      openProduct()
    const next = { ...product, id: 9, slug: 'clay-pot', name: 'Clay pot' }
    navigator.navigate(`/product/${next.slug}`)
    answer(next, 3)
    expect(viewers.get()).toBe(3)

    const refusal = {
      page: PAGE_PRODUCT,
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
    }
    receive(SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR, refusal)
    expect(navigator.pageError.get()).toEqual(refusal)

    answer(product)
    expect(navigator.pageError.get()).toEqual(refusal)
    expect(navigator.pageLoading.get()).toBe(false)
    expect(details.get()).toBeNull()
    expect(viewers.get()).toBeUndefined()

    answer({ slug: next.slug })
    viewerAnswer(next.slug, 0)
    expect(navigator.pageError.get()).toEqual(refusal)
    expect(viewers.get()).toBeUndefined()

    answer(next, -1)
    expect(navigator.pageError.get()).toBeNull()
    expect(details.get()).toEqual(next)
    expect(viewers.get()).toBeUndefined()
    viewerAnswer(next.slug, 0)
    expect(viewers.get()).toBe(0)
  })

  it('accepts cart counts only for the displayed slug and product ID', () => {
    const { details, viewers, carts, pageData } = openProduct(2)
    expect(carts.get()).toBeUndefined()

    const address = { productId: product.id, slug: product.slug }
    pageData({ product, productCarts: { ...address, cartCount: 3 } })
    expect(carts.get()).toBe(3)

    for (const productCarts of [
      { ...address, slug: 'clay-pot', cartCount: 0 },
      { ...address, productId: product.id + 1, cartCount: 0 },
      { ...address, cartCount: -1 },
      { ...address, cartCount: 1.5 },
      { ...address, cartCount: '3' },
    ]) {
      pageData({ productCarts })
      expect(carts.get()).toBe(3)
    }

    const updated = { ...product, price: 42000 }
    pageData({
      product: updated,
      productViewers: { slug: product.slug, otherViewerCount: 4 },
      productCarts: { ...address, cartCount: -1 },
    })
    expect(details.get()).toEqual(updated)
    expect(viewers.get()).toBe(4)
    expect(carts.get()).toBe(3)

    pageData({
      productViewers: { slug: 'clay-pot', otherViewerCount: 0 },
      productCarts: { ...address, cartCount: 0 },
    })
    expect(carts.get()).toBe(0)
    expect(viewers.get()).toBe(4)
    expect(details.get()).toEqual(updated)

    const replacement = { ...updated, id: updated.id + 1 }
    pageData({ product: replacement })
    expect(details.get()).toEqual(replacement)
    expect(carts.get()).toBeUndefined()
  })

  it('waits for a full snapshot after navigation and refusal before accepting carts', () => {
    const { navigator, details, carts, receive, pageData, answer } =
      openProduct()
    pageData({
      productCarts: { slug: product.slug, productId: product.id, cartCount: 3 },
    })
    expect(carts.get()).toBe(3)

    const next = { ...product, id: 9, slug: 'clay-pot', name: 'Clay pot' }
    const cartUpdate = {
      productCarts: { slug: next.slug, productId: next.id, cartCount: 2 },
    }
    navigator.navigate(`/product/${next.slug}`)
    pageData(cartUpdate)
    expect(navigator.pageLoading.get()).toBe(true)
    expect(details.get()).toBeNull()
    expect(carts.get()).toBeUndefined()

    answer(next)
    expect(carts.get()).toBeUndefined()
    pageData(cartUpdate)
    expect(carts.get()).toBe(2)

    const refusal = {
      page: PAGE_PRODUCT,
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
    }
    receive(SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR, refusal)
    pageData(cartUpdate)
    expect(navigator.pageError.get()).toEqual(refusal)
    expect(details.get()).toBeNull()
    expect(carts.get()).toBeUndefined()

    pageData({ product: next, ...cartUpdate })
    expect(navigator.pageError.get()).toBeNull()
    expect(carts.get()).toBe(2)
  })
})

describe('live product snapshots', () => {
  it('updates price, stock, and category from one page-data slot', () => {
    const route = createSignal(
      router.match(`/product/${encodeURIComponent(product.slug)}`),
    )
    const scopes = new ScopeManager()
    const scope = scopes.openPage(PAGE_PRODUCT)
    const details = createProductDetailsSignal(scopes, route)
    ingest(scope, { data: { product } })
    expect(details.get()).toEqual(product)

    const updated: ProductDetails = {
      ...product,
      price: 39000,
      units: 0,
      availability: 'out_of_stock',
      categoryName: 'Indoor pots',
      categorySlug: 'indoor-pots',
    }
    ingest(scope, { data: { product: updated } })
    expect(details.get()).toEqual(updated)
  })
})
