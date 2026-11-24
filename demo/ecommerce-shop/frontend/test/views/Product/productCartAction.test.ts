// @vitest-environment happy-dom

import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import {
  ActionError,
  createSignal,
  hilosToasts,
  type ActionResult,
} from '@hilos/core/base'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { type CartLine } from '../../../src/bootstrap/cart.js'
import { actions } from '../../../src/bootstrap/connection.js'
import { ProductContent } from '../../../src/views/Product/Product.js'
import { type ProductDetails } from '../../../src/views/Product/types/ProductDetails.js'

const stock: ProductDetails = {
  id: 12,
  slug: 'ceramic-pot',
  name: 'Ceramic pot',
  kind: 'stock',
  price: 41000,
  description: 'Glazed ceramic with a drainage hole.',
  pictureUrl: '/pictures/pot.webp',
  units: 3,
  availability: 'low',
  categoryName: 'Pots & planters',
  categorySlug: 'pots',
}
const unique: ProductDetails = {
  ...stock,
  id: 26,
  slug: 'summer-bouquet',
  name: 'Summer bouquet',
  kind: 'unique',
  units: 1,
  availability: 'free',
  categoryName: 'Bouquets',
  categorySlug: 'bouquets',
}
const cart = createSignal<readonly CartLine[] | null>(null)
const hint = 'Adds one. Change the quantity in the cart.'
let root: Root
let container: HTMLDivElement

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  cart.set(null)
  container = document.body.appendChild(document.createElement('div'))
  root = createRoot(container)
})

afterEach(async () => {
  await act(async () => root.unmount())
  container.remove()
  hilosToasts.clear()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

async function render(product: ProductDetails): Promise<void> {
  await act(async () =>
    root.render(createElement(ProductContent, { product, cart })),
  )
}

function addButton(): HTMLButtonElement {
  const button = container.querySelector<HTMLButtonElement>(
    'button[data-id="shop-product-add-to-cart"]',
  )
  if (!button) throw new Error('Expected the product cart button')
  return button
}

describe('product cart action', () => {
  it('waits for the personal snapshot and responds to live stock availability', async () => {
    await render(stock)

    const add = addButton()
    expect(add.type).toBe('button')
    expect(add.textContent).toBe('Add to cart')
    expect(add.getAttribute('aria-label')).toBe('Add to cart')
    expect(add.disabled).toBe(true)
    const descriptionId = add.getAttribute('aria-describedby')!
    expect(document.getElementById(descriptionId)?.textContent).toBe(hint)

    await act(async () => cart.set([{ productId: stock.id, quantity: 1 }]))
    expect(addButton().disabled).toBe(false)
    expect(addButton().textContent).toBe('Add to cart')

    await render({ ...stock, units: 0, availability: 'out_of_stock' })
    expect(addButton().textContent).toBe('Out of stock')
    expect(addButton().getAttribute('aria-label')).toBe('Out of stock')
    expect(addButton().disabled).toBe(true)
    expect(addButton().hasAttribute('aria-describedby')).toBe(false)
    expect(container.textContent).not.toContain(hint)

    await render(stock)
    expect(addButton().disabled).toBe(false)
    await act(async () => cart.set(null))
    expect(addButton().disabled).toBe(true)
  })

  it('swaps the unique product control with live cart membership and keeps keyboard focus', async () => {
    cart.set([])
    await render(unique)
    const availability = container.querySelector(
      '[data-id="shop-product-availability"]',
    )!
    expect(availability.textContent).toBe('One of a kind')
    expect(container.textContent).not.toContain(hint)
    addButton().focus()
    expect(document.activeElement).toBe(addButton())

    const line = { productId: unique.id, quantity: 1 }
    await act(async () => cart.set([line]))

    const link = container.querySelector<HTMLAnchorElement>(
      'a[data-id="shop-product-go-to-cart"]',
    )!
    expect(link.getAttribute('href')).toBe('/cart')
    expect(link.textContent).toBe('In your cart — go to cart')
    expect(link.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(availability.textContent).toBe('In your cart')
    expect(availability.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(container.querySelector('button')).toBeNull()
    expect(document.activeElement).toBe(link)

    await act(async () => cart.set([]))
    expect(container.querySelector('a')).toBeNull()
    expect(availability.textContent).toBe('One of a kind')
    expect(addButton().textContent).toBe('Add to cart')
    expect(document.activeElement).toBe(addButton())

    const outside = document.createElement('button')
    container.before(outside)
    try {
      outside.focus()
      await act(async () => cart.set([line]))
      expect(document.activeElement).toBe(outside)
      await act(async () => cart.set([]))
      expect(document.activeElement).toBe(outside)
    } finally {
      outside.remove()
    }
  })

  it('sends one tracked add per pending product, toasts a refusal and allows retry', async () => {
    let refuse!: (error: ActionError) => void
    const done = new Promise<ActionResult>((_resolve, reject) => {
      refuse = reject
    })
    const handle = {
      requestId: 'product-add-1',
      loading: createSignal(false),
      done,
    }
    const dispatch = vi.spyOn(actions, 'dispatch').mockReturnValueOnce(handle)
    const action = 'product_add_to_cart'
    const reason = 'There is not enough stock for this cart.'
    const refusal = new ActionError(action, 'fail', reason)

    cart.set([])
    try {
      await render(stock)
      await act(async () => {
        addButton().click()
        addButton().click()
      })
      expect(dispatch).toHaveBeenCalledExactlyOnceWith(action, {
        productId: stock.id,
      })
      expect(addButton().getAttribute('aria-busy')).toBe('true')
      expect(addButton().getAttribute('aria-disabled')).toBe('true')
      expect(cart.get()).toEqual([])

      await render(unique)
      expect(addButton().disabled).toBe(false)
      expect(addButton().hasAttribute('aria-busy')).toBe(false)
      expect(addButton().hasAttribute('aria-disabled')).toBe(false)
      await render(stock)
      expect(addButton().getAttribute('aria-busy')).toBe('true')
      await act(async () => addButton().click())
      expect(dispatch).toHaveBeenCalledTimes(1)
    } finally {
      await act(async () => refuse(refusal))
    }

    expect(hilosToasts.toasts.get()).toMatchObject([
      { message: reason, severity: 'error', scope: 'connection' },
    ])
    expect(addButton().hasAttribute('aria-busy')).toBe(false)
    dispatch.mockReturnValueOnce({ ...handle, done: Promise.resolve({}) })
    await act(async () => addButton().click())
    expect(dispatch).toHaveBeenCalledTimes(2)
    expect(dispatch).toHaveBeenLastCalledWith(action, { productId: stock.id })
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })
})
