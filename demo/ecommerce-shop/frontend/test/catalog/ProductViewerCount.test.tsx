// @vitest-environment happy-dom

import {
  HilosConnection,
  bindPageScope,
  createHilosRouter,
  ingest,
} from '@hilos/core/base'
import { HilosRouterContext } from '@hilos/react/base'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { scopes } from '../../src/bootstrap/session.js'
import { ProductViewerCount } from '../../src/catalog/ProductViewerCount.js'
import { PAGE_PRODUCT } from '../../src/pages/keys.js'
import { router } from '../../src/pages/routes.js'
import Product from '../../src/views/Product/Product.js'
import { type ProductDetails } from '../../src/views/Product/types/ProductDetails.js'

describe('the product viewer count', () => {
  let container: HTMLElement
  let root: Root

  beforeEach(() => {
    vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
    container = document.body.appendChild(document.createElement('div'))
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => root.unmount())
    scopes.dropPage()
    container.remove()
    vi.unstubAllGlobals()
  })

  it.each([
    [0, 'Nobody else is looking at it now', 'Nobody is looking at it now'],
    [1, '1 other is looking at it now', '1 person is looking at it now'],
    [5, '5 others are looking at it now', '5 people are looking at it now'],
  ])(
    'announces %i viewers with each surface’s wording',
    (count, shop, admin) => {
      for (const [surface, text] of [
        ['shop', shop],
        ['admin', admin],
      ] as const) {
        act(() =>
          root.render(
            <ProductViewerCount count={undefined} surface={surface} />,
          ),
        )
        const status = container.querySelector('[role="status"]')
        const reading = container.querySelector(
          '[data-id="product-viewer-count"]',
        )
        expect(status?.getAttribute('aria-live')).toBe('polite')
        expect(status?.textContent).toBe('')
        expect(reading?.textContent).toBe('—')

        act(() =>
          root.render(<ProductViewerCount count={count} surface={surface} />),
        )
        expect(container.querySelector('[role="status"]')).toBe(status)
        expect(status?.textContent).toBe(text)
        expect(reading?.textContent).toBe(text)
        expect(reading?.closest('[role="status"], [aria-live]')).toBeNull()
        expect(reading?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
      }
    },
  )

  it('retains an empty shop status while out of stock and updates it on restock', () => {
    const product: ProductDetails = {
      id: 8,
      slug: 'clay-pot',
      name: 'Clay pot',
      kind: 'stock',
      price: 41000,
      description: 'A glazed pot.',
      pictureUrl: '/pictures/pot.webp',
      units: 0,
      availability: 'out_of_stock',
      categoryName: 'Pots & planters',
      categorySlug: 'pots',
    }
    const pages = bindPageScope(
      new HilosConnection({ url: 'ws://test/ws' }),
      scopes,
    )
    const navigator = createHilosRouter(router, pages, {
      pathname: () => `/product/${product.slug}`,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    })
    const scope = scopes.openPage(PAGE_PRODUCT)
    const update = (snapshot: ProductDetails, otherViewerCount: number) =>
      ingest(scope, {
        data: {
          product: snapshot,
          productViewers: { slug: product.slug, otherViewerCount },
        },
      })

    update(product, 1)
    act(() =>
      root.render(
        <HilosRouterContext.Provider value={navigator}>
          <Product />
        </HilosRouterContext.Provider>,
      ),
    )
    const status = container.querySelector('[role="status"]')
    const reading = () =>
      container.querySelector('[data-id="product-viewer-count"]')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(status?.textContent).toBe('')
    expect(reading()).toBeNull()

    const stocked = { ...product, units: 10, availability: 'in_stock' } as const
    act(() => update(stocked, 1))
    expect(container.querySelector('[role="status"]')).toBe(status)
    expect(status?.textContent).toBe('1 other is looking at it now')
    expect(reading()?.textContent).toBe('1 other is looking at it now')

    act(() => update(product, 2))
    expect(container.querySelector('[role="status"]')).toBe(status)
    expect(status?.textContent).toBe('')
    expect(reading()).toBeNull()

    act(() => update(stocked, 2))
    expect(container.querySelector('[role="status"]')).toBe(status)
    expect(status?.textContent).toBe('2 others are looking at it now')
    expect(reading()?.textContent).toBe('2 others are looking at it now')
  })
})
