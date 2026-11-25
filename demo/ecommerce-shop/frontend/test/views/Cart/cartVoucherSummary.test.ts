// @vitest-environment happy-dom

import { ingest, type ScopeManager } from '@hilos/core/base'
import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { PAGE_CART } from '../../../src/pages/keys.js'
import Cart from '../../../src/views/Cart/Cart.js'
import { type CartSnapshot } from '../../../src/views/Cart/types/Cart.js'

const tab = vi.hoisted(() => ({
  scopes: undefined as unknown as ScopeManager,
}))

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager, createSignal } = await import('@hilos/core/base')
  tab.scopes = new ScopeManager()
  return {
    scopes: tab.scopes,
    currentUserId: createSignal<number | null>(null),
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: { state: 'connected', on: () => () => undefined },
}))

const cart: CartSnapshot = {
  userId: null,
  guestCartKey: 'guest-cart',
  lines: [
    {
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
    },
  ],
  itemsTotal: '1002',
  voucher: { code: 'SAVE25', active: true, discount: '251' },
  total: '751',
}

let root: Root | undefined

beforeEach(async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  tab.scopes.openPage(PAGE_CART)
  root = createRoot(document.body.appendChild(document.createElement('div')))
  await act(async () => root!.render(createElement(Cart)))
})

afterEach(async () => {
  await act(async () => root?.unmount())
  document.body.replaceChildren()
  vi.unstubAllGlobals()
})

async function answer(snapshot: CartSnapshot): Promise<void> {
  await act(async () =>
    ingest(tab.scopes.page()!, { data: { cart: snapshot } }),
  )
}

/** The summary's terms and their values, in reading order. */
function summary(): string[][] {
  return [...document.querySelectorAll('dl > div')].map((row) => [
    row.querySelector('dt')!.textContent!,
    row.querySelector('dd')!.textContent!,
  ])
}

it('shows the exact discount between the items and the total', async () => {
  await answer(cart)

  expect(summary()).toEqual([
    ['Items', '₴10.02'],
    ['Voucher SAVE25', '−₴2.51'],
    ['Total', '₴7.51'],
  ])
  expect(
    document.querySelector('[data-id="shop-cart-total"]')!.textContent,
  ).toBe('₴7.51')
})

it('says a switched-off voucher is inactive with a dash, and the total is the items', async () => {
  await answer({
    ...cart,
    voucher: { code: 'SAVE25', active: false, discount: '0' },
    total: '1002',
  })

  expect(summary()).toEqual([
    ['Items', '₴10.02'],
    ['Voucher SAVE25 · Inactive', '—'],
    ['Total', '₴10.02'],
  ])
})

it('drops the voucher line once the voucher is taken off', async () => {
  await answer(cart)
  await answer({ ...cart, voucher: null, total: '1002' })

  expect(document.querySelector('[data-id="shop-cart-voucher"]')).toBeNull()
  expect(summary()).toEqual([
    ['Items', '₴10.02'],
    ['Total', '₴10.02'],
  ])
})

it('shows the total it was sent even when nothing is left to pay', async () => {
  await answer({
    ...cart,
    lines: [{ ...cart.lines[0]!, availableQuantity: 0, lineTotal: '0' }],
    itemsTotal: '0',
    voucher: { code: 'SAVE25', active: true, discount: '0' },
    total: '0',
  })

  expect(summary()).toEqual([
    ['Items', '₴0'],
    ['Voucher SAVE25', '−₴0'],
    ['Total', '₴0'],
  ])
})
