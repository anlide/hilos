// @vitest-environment happy-dom

import {
  createSignal,
  hilosToasts,
  ingest,
  type HilosConnection,
} from '@hilos/core/base'
import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'

import { currentUserId, scopes } from '../../../src/bootstrap/session.js'
import { formatMoney } from '../../../src/money/money.js'
import { PAGE_CART, PAGE_MAIN } from '../../../src/pages/keys.js'
import Cart from '../../../src/views/Cart/Cart.js'
import type { CartLine } from '../../../src/views/Cart/types/Cart.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, (reply: unknown) => void>()
  return {
    connection: {
      state: 'connected',
      sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
      on(event: string, listener: (reply: unknown) => void) {
        listeners.set(event, listener)
        return () => listeners.delete(event)
      },
    },
    emit(event: string, reply: unknown) {
      listeners.get(event)?.(reply)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    connection: wire.connection,
    actions: new ActionLifecycle(wire.connection as unknown as HilosConnection),
  }
})

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager, createSignal } = await import('@hilos/core/base')
  return {
    scopes: new ScopeManager(),
    currentUserId: createSignal<number | null>(null),
  }
})

const firstLine: CartLine = {
  lineId: 19,
  productId: 7,
  slug: 'pine-bark',
  name: 'Pine bark',
  kind: 'stock',
  pictureUrl: '/pictures/pine-bark.webp',
  price: 12000,
  quantity: 1,
  availableQuantity: 2,
  lineTotal: '12000',
}
const unavailableLine: CartLine = {
  ...firstLine,
  lineId: 20,
  productId: 8,
  slug: 'moss',
  name: 'Moss',
  availableQuantity: 0,
  lineTotal: '0',
}
const lastLine: CartLine = {
  ...firstLine,
  lineId: 21,
  productId: 9,
  slug: 'garden-pot',
  name: 'Garden pot',
}

const sendAction = wire.connection.sendAction
let root: Root | undefined

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    for (const [action, , requestId] of sendAction.mock.calls) {
      wire.emit('actionSuccess', { action, requestId })
    }
    scopes.openPage(PAGE_MAIN)
  })
  root = undefined
  document.body.replaceChildren()
  hilosToasts.clear()
  sendAction.mockClear()
  vi.unstubAllGlobals()
})

function answer(lines: CartLine[], itemsTotal: string): void {
  const userId = currentUserId.get()
  ingest(scopes.page()!, {
    data: {
      cart: {
        userId,
        guestCartKey: userId === null ? 'guest-cart' : null,
        lines,
        itemsTotal,
        voucher: null,
        total: itemsTotal,
      },
    },
  })
}

async function mountCart(
  userId: number | null,
  lines: CartLine[],
  itemsTotal: string,
): Promise<void> {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const person = currentUserId as ReturnType<typeof createSignal<number | null>>
  person.set(userId)
  scopes.openPage(PAGE_CART)
  answer(lines, itemsTotal)

  const container = document.body.appendChild(document.createElement('div'))
  root = createRoot(container)
  await act(async () => root!.render(createElement(Cart)))
}

function removeButton(name: string): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>(
    `button[aria-label="Remove ${name}"]`,
  )
}

function succeed(call: number): void {
  const [action, , requestId] = sendAction.mock.calls[call]
  wire.emit('actionSuccess', { action, requestId })
}

it('removes an unavailable guest line on the server snapshot and restores focus', async () => {
  await mountCart(null, [firstLine, unavailableLine, lastLine], '24000')
  const unavailable = removeButton(unavailableLine.name)!
  const first = removeButton(firstLine.name)!
  const last = removeButton(lastLine.name)!

  expect(unavailable.type).toBe('button')
  expect(unavailable.querySelector('i')?.getAttribute('aria-hidden')).toBe(
    'true',
  )
  expect(unavailable.closest('li')?.textContent).toContain('Out of stock')

  unavailable.focus()
  await act(async () => {
    unavailable.click()
    unavailable.click()
  })
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    'cart_remove_line',
    { lineId: unavailableLine.lineId, ownerKind: 'guest' },
    expect.any(String),
  )
  expect(document.querySelector('dialog, [role="dialog"]')).toBeNull()
  expect(unavailable.getAttribute('aria-busy')).toBe('true')
  expect(unavailable.getAttribute('aria-disabled')).toBe('true')
  expect(last.hasAttribute('aria-disabled')).toBe(false)
  expect(document.activeElement).toBe(unavailable)

  // A live change to a different row leaves the in-flight control focused.
  await act(async () =>
    answer(
      [{ ...firstLine, lineTotal: '13000' }, unavailableLine, lastLine],
      '25000',
    ),
  )
  expect(document.activeElement).toBe(unavailable)

  await act(async () => succeed(0))
  expect(removeButton(unavailableLine.name)).toBe(unavailable)
  const total = document.querySelector('[data-id="shop-cart-total"]')
  expect(total?.textContent).toBe(formatMoney('25000'))
  expect(hilosToasts.toasts.get()).toEqual([])

  await act(async () => answer([firstLine, lastLine], '24000'))
  expect(removeButton(unavailableLine.name)).toBeNull()
  expect(total?.textContent).toBe(formatMoney('24000'))
  expect(document.activeElement).toBe(last)

  await act(async () => last.click())
  await act(async () => answer([firstLine], '12000'))
  expect(document.activeElement).toBe(first)
  await act(async () => succeed(1))

  await act(async () => first.click())
  await act(async () => answer([], '0'))
  const shopping = document.querySelector('[data-id="shop-cart-go-shopping"]')
  expect(shopping?.textContent).toBe('Go shopping')
  expect(document.activeElement).toBe(shopping)
  await act(async () => succeed(2))
})

it('keeps a refused account line, lets it retry, and preserves a later focus choice', async () => {
  await mountCart(15, [firstLine, lastLine], '24000')
  const first = removeButton(firstLine.name)!
  const last = removeButton(lastLine.name)!
  const reason = 'This cart item is no longer available.'

  first.focus()
  await act(async () => first.click())
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    'cart_remove_line',
    { lineId: firstLine.lineId, ownerKind: 'account' },
    expect.any(String),
  )
  const [action, , requestId] = sendAction.mock.calls[0]
  await act(async () => wire.emit('actionError', { action, requestId, reason }))

  expect(removeButton(firstLine.name)).toBe(first)
  expect(document.activeElement).toBe(first)
  expect(first.hasAttribute('aria-disabled')).toBe(false)
  expect(hilosToasts.toasts.get()).toMatchObject([
    { severity: 'error', message: reason },
  ])

  await act(async () => first.click())
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    action,
    { lineId: firstLine.lineId, ownerKind: 'account' },
    expect.any(String),
  )
  const product = last.closest('li')!.querySelector('a')!
  product.focus()
  await act(async () => answer([lastLine], '12000'))
  expect(removeButton(firstLine.name)).toBeNull()
  expect(document.activeElement).toBe(product)
  await act(async () => succeed(1))
})
