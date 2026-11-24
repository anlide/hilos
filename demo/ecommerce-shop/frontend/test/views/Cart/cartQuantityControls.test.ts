// @vitest-environment happy-dom

import {
  ActionError,
  USER_ENTITY_TYPE,
  createSignal,
  hilosToasts,
  ingest,
  type ActionResult,
} from '@hilos/core/base'
import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { actions, connection } from '../../../src/bootstrap/connection.js'
import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_CART } from '../../../src/pages/keys.js'
import Cart from '../../../src/views/Cart/Cart.js'
import { CART_STEP_QUANTITY } from '../../../src/views/Cart/cartActions.js'
import { type CartLine } from '../../../src/views/Cart/types/Cart.js'

const stockLine: CartLine = {
  lineId: 31,
  productId: 7,
  slug: 'pine-bark',
  name: 'Pine bark',
  kind: 'stock',
  pictureUrl: '/pictures/pine-bark.webp',
  price: 12000,
  quantity: 5,
  availableQuantity: 8,
  lineTotal: '60000',
}

let root: Root | undefined
const replies: { succeed: () => void; refuse: (reason: string) => void }[] = []

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  vi.spyOn(connection, 'state', 'get').mockReturnValue('connected')
  vi.spyOn(actions, 'dispatch').mockImplementation((action) => {
    const done = new Promise<ActionResult>((resolve, reject) => {
      replies.push({
        succeed: () => resolve({}),
        refuse: (reason) => reject(new ActionError(action, 'fail', reason)),
      })
    })
    return {
      requestId: String(replies.length),
      loading: createSignal(false),
      done,
    }
  })
  scopes.openPage(PAGE_CART)
})

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    replies.splice(0).forEach((reply) => reply.succeed())
    scopes.dropPage()
    scopes.session.data.set('currentUser', undefined)
  })
  root = undefined
  document.body.replaceChildren()
  hilosToasts.clear()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

async function mountCart(): Promise<void> {
  const container = document.body.appendChild(document.createElement('div'))
  const mounted = createRoot(container)
  root = mounted
  await act(async () => mounted.render(createElement(Cart)))
}

async function answer(
  lines: CartLine[],
  itemsTotal: string,
  userId: number | null = null,
): Promise<void> {
  await act(async () => {
    ingest(scopes.page()!, {
      data: {
        cart: {
          lines,
          itemsTotal,
          voucher: null,
          total: itemsTotal,
          userId,
        },
      },
    })
  })
}

function quantityControls(name = 'Pine bark') {
  const group = document.querySelector<HTMLElement>(
    `[role="group"][aria-label="Quantity of ${name}"]`,
  )!
  return {
    group,
    less: group.querySelector<HTMLButtonElement>(
      'button[aria-label="One less"]',
    )!,
    more: group.querySelector<HTMLButtonElement>(
      'button[aria-label="One more"]',
    )!,
    quantity: group.querySelector<HTMLElement>(
      '[data-id="shop-cart-quantity"]',
    )!,
    row: group.closest('li')!,
  }
}

it('shows a named pair of native buttons and applies each live stock limit', async () => {
  await mountCart()
  await answer([{ ...stockLine, quantity: 1, lineTotal: '12000' }], '12000')
  const { group, less, more, quantity, row } = quantityControls()
  expect(group.querySelectorAll('button')).toHaveLength(2)
  for (const button of [less, more]) {
    expect(button.type).toBe('button')
    expect(button.firstElementChild?.getAttribute('aria-hidden')).toBe('true')
  }
  expect(quantity.textContent).toBe('Quantity: 1')
  expect(less.disabled).toBe(true)
  expect(more.disabled).toBe(false)

  more.focus()
  await answer([{ ...stockLine, availableQuantity: 5 }], '60000')
  expect(quantity.textContent).toBe('Quantity: 5')
  expect(row.textContent).toContain('5 in stock')
  expect(less.disabled).toBe(false)
  expect(more.disabled).toBe(true)
  expect(document.activeElement).toBe(less)

  await answer(
    [{ ...stockLine, availableQuantity: 2, lineTotal: '24000' }],
    '24000',
  )
  expect(quantity.textContent).toBe('Quantity: 5')
  expect(row.textContent).toContain('Only 2 left')
  expect(less.disabled).toBe(false)
  expect(more.disabled).toBe(true)

  await answer([{ ...stockLine, availableQuantity: 0, lineTotal: '0' }], '0')
  expect(quantity.textContent).toBe('Quantity: 5')
  expect(row.textContent).toContain('Out of stock')
  expect(less.disabled).toBe(false)
  expect(more.disabled).toBe(true)
  expect(
    row.querySelector('[data-id="shop-cart-line-total"]')?.textContent,
  ).toBe('₴0')
  expect(
    document.querySelector('[data-id="shop-cart-total"]')?.textContent,
  ).toBe('₴0')

  await answer(
    [{ ...stockLine, quantity: 1, availableQuantity: 0, lineTotal: '0' }],
    '0',
  )
  expect(less.disabled).toBe(true)
  expect(more.disabled).toBe(true)
  expect(document.activeElement).toBe(group)

  await answer([stockLine], '60000')
  expect(more.disabled).toBe(false)
  expect(row.textContent).not.toContain('Out of stock')
})

it('renders a unique line with its fixed quantity and no quantity controls', async () => {
  await mountCart()
  await answer(
    [
      {
        ...stockLine,
        kind: 'unique',
        quantity: 1,
        availableQuantity: 1,
        lineTotal: '12000',
      },
    ],
    '12000',
  )
  const row = document.querySelector('li')!
  expect(row.textContent).toContain('1 · one of a kind')
  expect(row.querySelector('[role="group"]')).toBeNull()
  expect(row.querySelector('button')).toBeNull()
})

it('dispatches one guest step per row and keeps confirmed data through a refusal', async () => {
  await mountCart()
  const other = { ...stockLine, lineId: 69, productId: 15, name: 'Clay pot' }
  await answer([stockLine, other], '120000')
  const { less, more, quantity, row } = quantityControls()
  const otherControls = quantityControls('Clay pot')
  more.focus()

  await act(async () => {
    more.click()
    less.click()
    more.click()
  })
  expect(actions.dispatch).toHaveBeenCalledExactlyOnceWith(CART_STEP_QUANTITY, {
    lineId: 31,
    ownerKind: 'guest',
    step: 1,
  })
  expect(more.getAttribute('aria-disabled')).toBe('true')
  expect(less.getAttribute('aria-disabled')).toBe('true')
  expect(document.activeElement).toBe(more)
  expect(quantity.textContent).toBe('Quantity: 5')
  expect(
    row.querySelector('[data-id="shop-cart-line-total"]')?.textContent,
  ).toBe('₴600')
  expect(otherControls.less.getAttribute('aria-disabled')).toBeNull()

  await act(async () => otherControls.less.click())
  expect(actions.dispatch).toHaveBeenLastCalledWith(CART_STEP_QUANTITY, {
    lineId: 69,
    ownerKind: 'guest',
    step: -1,
  })
  expect(actions.dispatch).toHaveBeenCalledTimes(2)

  await act(async () => replies[0].refuse('Only 4 in stock'))
  expect(hilosToasts.toasts.get()).toMatchObject([
    { message: 'Only 4 in stock', severity: 'error' },
  ])
  expect(quantity.textContent).toBe('Quantity: 5')
  expect(
    row.querySelector('[data-id="shop-cart-line-total"]')?.textContent,
  ).toBe('₴600')
  expect(more.getAttribute('aria-disabled')).toBeNull()
  expect(otherControls.less.getAttribute('aria-disabled')).toBe('true')

  hilosToasts.clear()
  await act(async () => replies[1].succeed())
  expect(hilosToasts.toasts.get()).toEqual([])
  expect(otherControls.quantity.textContent).toBe('Quantity: 5')
  await answer(
    [stockLine, { ...other, quantity: 4, lineTotal: '48000' }],
    '108000',
  )
  expect(otherControls.quantity.textContent).toBe('Quantity: 4')
  expect(
    document.querySelector('[data-id="shop-cart-total"]')?.textContent,
  ).toBe('₴1,080')
})

it('resets the row controls when its cart kind or saved line changes', async () => {
  await mountCart()
  await answer([stockLine], '60000')
  const guest = quantityControls()
  guest.more.focus()
  await act(async () => guest.more.click())

  await act(async () => {
    ingest(
      scopes.session,
      { entities: { currentUser: { id: 9 } } },
      {
        entityTypes: { currentUser: USER_ENTITY_TYPE },
      },
    )
    ingest(scopes.page()!, {
      data: {
        cart: {
          lines: [stockLine],
          itemsTotal: '60000',
          voucher: null,
          total: '60000',
          userId: 9,
        },
      },
    })
  })
  const account = quantityControls()
  expect(account.more).not.toBe(guest.more)
  expect(account.more.getAttribute('aria-disabled')).toBeNull()
  expect(document.activeElement).not.toBe(account.more)
  await act(async () => account.less.click())
  expect(actions.dispatch).toHaveBeenLastCalledWith(CART_STEP_QUANTITY, {
    lineId: 31,
    ownerKind: 'account',
    step: -1,
  })

  await answer([{ ...stockLine, lineId: 42 }], '60000', 9)
  const replaced = quantityControls()
  expect(replaced.less).not.toBe(account.less)
  expect(replaced.less.getAttribute('aria-disabled')).toBeNull()
  await act(async () => replaced.more.click())
  expect(actions.dispatch).toHaveBeenLastCalledWith(CART_STEP_QUANTITY, {
    lineId: 42,
    ownerKind: 'account',
    step: 1,
  })
  await act(async () => replies[1].succeed())
  expect(replaced.more.getAttribute('aria-disabled')).toBe('true')
})
