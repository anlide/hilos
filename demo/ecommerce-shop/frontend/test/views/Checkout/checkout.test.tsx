// @vitest-environment happy-dom

import { ingest } from '@hilos/core/base'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_CHECKOUT } from '../../../src/pages/keys.js'
import type { CartLine, CartSnapshot } from '../../../src/types/Cart.js'
import Checkout from '../../../src/views/Checkout/Checkout.js'

const accountId = vi.hoisted(() => 42)

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager, createSignal } = await import('@hilos/core/base')
  return {
    scopes: new ScopeManager(),
    currentUserId: createSignal<number | null>(accountId),
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: { state: 'connected', on: () => () => undefined },
}))

const pineBark: CartLine = {
  lineId: 19,
  productId: 7,
  slug: 'pine-bark',
  name: 'Pine bark',
  kind: 'stock',
  pictureUrl: '/pictures/pine-bark.webp',
  price: 1002,
  quantity: 1,
  availableQuantity: 4,
  lineTotal: '1002',
}

const unavailable: CartLine = {
  lineId: 20,
  productId: 8,
  slug: 'morning-meadow',
  name: 'Morning meadow',
  kind: 'unique',
  pictureUrl: '/pictures/morning-meadow.webp',
  price: 9000,
  quantity: 1,
  availableQuantity: 0,
  lineTotal: '0',
}

const cart: CartSnapshot = {
  userId: accountId,
  guestCartKey: null,
  lines: [pineBark],
  itemsTotal: '1002',
  voucher: null,
  total: '1002',
}

let root: Root

beforeEach(async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  scopes.openPage(PAGE_CHECKOUT)
  root = createRoot(document.body.appendChild(document.createElement('div')))
  await act(async () => root.render(<Checkout />))
})

afterEach(async () => {
  await act(async () => root.unmount())
  scopes.dropPage()
  document.body.replaceChildren()
  vi.unstubAllGlobals()
})

async function answer(snapshot: CartSnapshot): Promise<void> {
  await act(async () => ingest(scopes.page()!, { data: { cart: snapshot } }))
}

it('adds a quantity only for multiples and shows the price of available units', async () => {
  const limited: CartLine = {
    lineId: 21,
    productId: 9,
    slug: 'garden-soil',
    name: 'Garden soil',
    kind: 'stock',
    pictureUrl: '/pictures/garden-soil.webp',
    price: 1400,
    quantity: 5,
    availableQuantity: 2,
    lineTotal: '2800',
  }
  await answer({
    ...cart,
    lines: [pineBark, limited],
    itemsTotal: '3802',
    total: '3802',
  })

  const [single, multiple] = document.querySelectorAll(
    'li[data-id="shop-checkout-line"]',
  )
  expect(single!.textContent).toContain('Pine bark')
  expect(single!.textContent).not.toContain('×')
  expect(multiple!.textContent).toContain('Garden soil × 5')
  expect(multiple!.textContent).toContain('Only 2 left')
  expect(multiple!.lastElementChild?.textContent).toBe('₴28')
  expect(
    document.querySelector('[data-id="shop-checkout-total"]')?.textContent,
  ).toBe('₴38.02')
})

it('keeps an out-of-stock line visible with zero contribution to the total', async () => {
  await answer({ ...cart, lines: [pineBark, unavailable] })

  const [, sold] = document.querySelectorAll('li[data-id="shop-checkout-line"]')
  expect(sold!.textContent).toContain('Morning meadow')
  expect(sold!.textContent).toContain('Out of stock')
  expect(sold!.lastElementChild?.textContent).toBe('₴0')
  expect(
    document.querySelector('[data-id="shop-checkout-total"]')?.textContent,
  ).toBe('₴10.02')
})

it('shows the active voucher as a deduction from the total', async () => {
  await answer({
    ...cart,
    voucher: { id: 25, code: 'SAVE25', active: true, discount: '251' },
    total: '751',
  })

  const voucher = document.querySelector('[data-id="shop-checkout-voucher"]')
  expect(voucher?.querySelector('dt')?.textContent).toBe('Voucher SAVE25')
  expect(voucher?.querySelector('dd')?.textContent).toBe('−₴2.51')
  expect(
    document.querySelector('[data-id="shop-checkout-total"]')?.textContent,
  ).toBe('₴7.51')
})

it('labels an inactive voucher and shows a dash for its deduction', async () => {
  await answer({
    ...cart,
    voucher: { id: 25, code: 'SAVE25', active: false, discount: '0' },
  })

  const voucher = document.querySelector('[data-id="shop-checkout-voucher"]')
  expect(voucher?.querySelector('dt')?.textContent).toBe(
    'Voucher SAVE25 · Inactive',
  )
  expect(voucher?.querySelector('dd')?.textContent).toBe('—')
})

it('names the disabled Pay button with the total and describes it with the payment hint', async () => {
  await answer(cart)

  const pay = document.querySelector<HTMLButtonElement>(
    'button[data-id="shop-checkout-pay"]',
  )!
  expect(pay.disabled).toBe(true)
  expect(pay.textContent).toBe('Pay ₴10.02')
  const descriptionId = pay.getAttribute('aria-describedby')
  expect(descriptionId).toBeTruthy()
  expect(document.getElementById(descriptionId!)?.textContent).toBe(
    'Paying is not connected yet — it comes with the next step of the demo.',
  )
})

it('links an empty cart back to shopping', async () => {
  await answer({ ...cart, lines: [], itemsTotal: '0', total: '0' })

  const empty = document.querySelector('[data-id="shop-checkout-empty"]')
  expect(empty?.querySelector('h2')?.textContent).toBe('Your cart is empty')
  const link = empty?.querySelector('a[data-id="shop-checkout-go-shopping"]')
  expect(link?.textContent).toBe('Go shopping')
  expect(link?.getAttribute('href')).toBe('/')
  expect(document.querySelector('[data-id="shop-checkout-pay"]')).toBeNull()
  expect(document.querySelector('[data-id="shop-checkout-total"]')).toBeNull()
})

it('links back to the cart when none of its items can be bought', async () => {
  await answer({ ...cart, lines: [unavailable], itemsTotal: '0', total: '0' })

  const empty = document.querySelector('[data-id="shop-checkout-unavailable"]')
  expect(empty?.querySelector('h2')?.textContent).toBe(
    'Nothing in your cart can be bought right now',
  )
  const link = empty?.querySelector('a[data-id="shop-checkout-back-to-cart"]')
  expect(link?.textContent).toBe('Back to cart')
  expect(link?.getAttribute('href')).toBe('/cart')
  expect(document.querySelector('[data-id="shop-checkout-pay"]')).toBeNull()
  expect(document.querySelector('[data-id="shop-checkout-total"]')).toBeNull()
})

it('moves focus to the replacement action as live snapshots change checkout state', async () => {
  await answer(cart)
  const back = document.querySelector<HTMLAnchorElement>(
    '[data-id="shop-checkout-back-to-cart"]',
  )!
  back.focus()

  await answer({ ...cart, lines: [], itemsTotal: '0', total: '0' })
  const shopping = document.querySelector<HTMLAnchorElement>(
    '[data-id="shop-checkout-go-shopping"]',
  )!
  expect(document.activeElement).toBe(shopping)

  await answer({ ...cart, lines: [unavailable], itemsTotal: '0', total: '0' })
  expect(document.activeElement).toBe(
    document.querySelector('[data-id="shop-checkout-back-to-cart"]'),
  )
  await answer(cart)
  expect(document.activeElement).toBe(
    document.querySelector('[data-id="shop-checkout-back-to-cart"]'),
  )
})

it('keeps focus on surviving controls and leaves an unfocused checkout alone', async () => {
  await answer(cart)
  const back = document.querySelector<HTMLAnchorElement>(
    '[data-id="shop-checkout-back-to-cart"]',
  )!
  back.focus()
  await answer({ ...cart, total: '900' })
  expect(document.activeElement).toBe(back)

  const breadcrumb = document.querySelector<HTMLAnchorElement>(
    'nav[aria-label="breadcrumb"] a',
  )!
  breadcrumb.focus()
  await answer({ ...cart, lines: [], itemsTotal: '0', total: '0' })
  expect(document.activeElement).toBe(breadcrumb)

  breadcrumb.blur()
  await answer(cart)
  expect(document.activeElement).toBe(document.body)
})

it('keeps the loading status visible to assistive technology before the answer', () => {
  expect(document.querySelector('h1')?.textContent).toBe('Checkout')
  expect(document.querySelector('[role="status"]')?.textContent).toBe(
    'Loading checkout…',
  )
  expect(document.querySelector('h2')).toBeNull()
  expect(document.querySelector('nav')).toBeNull()
})
