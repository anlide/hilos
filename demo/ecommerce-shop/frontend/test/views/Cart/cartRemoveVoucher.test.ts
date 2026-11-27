// @vitest-environment happy-dom

import {
  SIGNAL_TYPE_ACTION_ERROR,
  createSignal,
  hilosToasts,
  parseSignalValue,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type SignalEnvelope,
} from '@hilos/core/base'
import { Fragment, act, createElement, createRef, type ReactNode } from 'react'
import { flushSync } from 'react-dom'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, assert, expect, it, vi } from 'vitest'

import { currentUserId, scopes } from '../../../src/bootstrap/session.js'
import { PAGE_CART } from '../../../src/pages/keys.js'
import { type CartSnapshot } from '../../../src/types/Cart.js'
import Cart from '../../../src/views/Cart/Cart.js'
import { CART_REMOVE_VOUCHER } from '../../../src/views/Cart/cartActions.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(data: never) => void>
  >()
  let state: ConnectionState = 'connected'
  const connection: Pick<HilosConnection, 'on' | 'sendAction' | 'state'> = {
    get state() {
      return state
    },
    on(event, listener) {
      const handlers = listeners.get(event) ?? new Set<(data: never) => void>()
      listeners.set(event, handlers)
      handlers.add(listener)
      return () => handlers.delete(listener)
    },
    sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
  }
  function emit<K extends keyof HilosConnectionEventMap>(
    event: K,
    data: HilosConnectionEventMap[K],
  ): void {
    listeners.get(event)?.forEach((listener) => listener(data as never))
  }
  return {
    connection,
    emit,
    changeState(next: ConnectionState) {
      state = next
      emit('state', next)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    connection: wire.connection,
    actions: new ActionLifecycle(wire.connection),
  }
})

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager, createSignal } = await import('@hilos/core/base')
  return {
    scopes: new ScopeManager(),
    currentUserId: createSignal<number | null>(null),
  }
})

const guest: CartSnapshot = {
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
      price: 1000,
      quantity: 1,
      availableQuantity: 4,
      lineTotal: '1000',
    },
  ],
  itemsTotal: '1000',
  voucher: { id: 25, code: 'SAVE25', active: true, discount: '250' },
  total: '750',
}

const sendAction = vi.mocked(wire.connection.sendAction)
const firstProof = 'a'.repeat(64)
const nextProof = 'b'.repeat(64)
let root: Root | undefined

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    wire.changeState('disconnected')
    scopes.dropPage()
    wire.changeState('connected')
  })
  root = undefined
  document.body.replaceChildren()
  hilosToasts.clear()
  sendAction.mockClear()
  vi.unstubAllGlobals()
})

function answer(cart: CartSnapshot, proof: string | null = firstProof): void {
  scopes.page()!.data.set('cart', cart, proof ?? undefined)
}

async function mountCart(
  cart: CartSnapshot,
  view: ReactNode = createElement(Cart),
): Promise<void> {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const person = currentUserId as ReturnType<typeof createSignal<number | null>>
  person.set(cart.userId)
  scopes.openPage(PAGE_CART)
  root = createRoot(document.body.appendChild(document.createElement('div')))
  await act(async () => {
    answer(cart)
    root!.render(view)
  })
}

function removeButton(): HTMLButtonElement | null {
  return document.querySelector('button[aria-label="Remove voucher"]')
}

function reply(frame: SignalEnvelope): void {
  const result = parseSignalValue(frame)
  assert(result.ok)
  const { signal } = result
  assert(signal.kind === 'actionSuccess' || signal.kind === 'actionError')
  wire.emit(signal.kind, signal)
}

function succeed(requestId: string | undefined): void {
  reply({
    type: 'action_success',
    requestId,
    data: { action: CART_REMOVE_VOUCHER },
  })
}

it('tracks one click and restores focus from either order of server replies', async () => {
  await mountCart(guest)
  const remove = removeButton()!
  const apply = document.querySelector('[data-id="cart-voucher-apply-open"]')
  const total = document.querySelector('[data-id="shop-cart-total"]')
  expect(remove.type).toBe('button')
  expect(remove.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')

  remove.focus()
  await act(async () => {
    remove.click()
    remove.click()
  })
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    'cart_remove_voucher',
    { expectedVoucherId: 25, ownerKind: 'guest' },
    expect.any(String),
    firstProof,
  )
  expect(document.querySelector('dialog, [role="dialog"]')).toBeNull()
  expect(remove.getAttribute('aria-busy')).toBe('true')
  expect(remove.getAttribute('aria-disabled')).toBe('true')
  expect(remove.disabled).toBe(false)
  expect(document.activeElement).toBe(remove)

  await act(async () => succeed('unrelated-request'))
  expect(remove.getAttribute('aria-busy')).toBe('true')
  await act(async () => succeed(sendAction.mock.calls[0][2]))
  expect(remove.hasAttribute('aria-busy')).toBe(false)
  expect(removeButton()).toBe(remove)
  expect(total?.textContent).toBe('₴7.50')
  expect(apply?.textContent).toBe('Apply another voucher')
  expect(document.activeElement).toBe(remove)

  await act(async () => answer({ ...guest, voucher: null, total: '1000' }))
  expect(removeButton()).toBeNull()
  expect(total?.textContent).toBe('₴10')
  expect(apply?.textContent).toBe('Apply voucher')
  expect(document.activeElement).toBe(apply)

  await act(async () => answer(guest))
  const second = removeButton()!
  second.focus()
  await act(async () => second.click())
  expect(second.getAttribute('aria-busy')).toBe('true')
  await act(async () =>
    answer({ ...guest, lines: [], voucher: null, itemsTotal: '0', total: '0' }),
  )
  const shopping = document.querySelector('[data-id="shop-cart-go-shopping"]')
  expect(shopping?.textContent).toBe('Go shopping')
  expect(document.activeElement).toBe(shopping)
  await act(async () => succeed(sendAction.mock.calls[1][2]))
  expect(document.activeElement).toBe(shopping)
  expect(hilosToasts.toasts.get()).toEqual([])
})

it('preserves focus moved during the commit that removes the voucher', async () => {
  const outside = createRef<HTMLButtonElement>()
  let focusBeforeHandoff: Element | null = null

  function view(moveFocus: boolean) {
    return createElement(
      Fragment,
      null,
      createElement(
        'button',
        {
          type: 'button',
          ref: moveFocus
            ? (button: HTMLButtonElement | null) => {
                if (!button) return
                focusBeforeHandoff = document.activeElement
                button.focus()
              }
            : outside,
        },
        'Outside the cart',
      ),
      createElement(Cart),
    )
  }

  await mountCart(guest, view(false))
  const button = outside.current!
  const remove = removeButton()!
  remove.focus()
  expect(document.activeElement).toBe(remove)

  await act(async () => {
    flushSync(() => {
      answer({ ...guest, voucher: null, total: guest.itemsTotal })
      root!.render(view(true))
    })
  })

  expect(removeButton()).toBeNull()
  expect(focusBeforeHandoff).toBe(document.body)
  expect(document.activeElement).toBe(button)
})

it('keeps a refused voucher, allows retry, and ignores obsolete clicks', async () => {
  const account: CartSnapshot = {
    ...guest,
    userId: 17,
    guestCartKey: null,
    voucher: { id: 26, code: 'OLD26', active: false, discount: '0' },
    total: '1000',
  }
  await mountCart(account)
  const remove = removeButton()!
  const row = document.querySelector('[data-id="shop-cart-voucher"]')
  const total = document.querySelector('[data-id="shop-cart-total"]')

  remove.focus()
  await act(async () => remove.click())
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    'cart_remove_voucher',
    { expectedVoucherId: 26, ownerKind: 'account' },
    expect.any(String),
    firstProof,
  )
  const [action, , requestId] = sendAction.mock.calls[0]
  const reason = 'This voucher is no longer applied to your cart.'
  await act(async () =>
    reply({
      type: SIGNAL_TYPE_ACTION_ERROR,
      requestId,
      data: { action: CART_REMOVE_VOUCHER, reason },
    }),
  )
  expect(removeButton()).toBe(remove)
  expect(row?.textContent).toContain('OLD26 · Inactive')
  expect(total?.textContent).toBe('₴10')
  expect(document.activeElement).toBe(remove)
  expect(remove.hasAttribute('aria-disabled')).toBe(false)
  expect(hilosToasts.toasts.get()).toMatchObject([
    { severity: 'error', message: reason },
  ])

  await act(async () => remove.click())
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    action,
    { expectedVoucherId: 26, ownerKind: 'account' },
    expect.any(String),
    firstProof,
  )
  await act(async () => wire.changeState('reconnecting'))
  expect(removeButton()).toBeNull()
  await act(async () => {
    wire.changeState('connected')
    answer({ ...account })
  })
  const restored = removeButton()!
  await act(async () => {
    answer({ ...account, voucher: guest.voucher, total: '750' })
    expect(restored.closest('dt')?.textContent).toContain('OLD26')
    restored.click()
  })
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(restored.closest('dt')?.textContent).toContain('SAVE25')

  const product = document.querySelector<HTMLAnchorElement>(
    '[data-id="shop-cart-product-link"]',
  )!
  product.focus()
  await act(async () => answer({ ...account, voucher: null }))
  expect(removeButton()).toBeNull()
  expect(document.activeElement).toBe(product)
})

it.each([
  { ownerKind: 'guest', cart: guest },
  { ownerKind: 'account', cart: { ...guest, userId: 17, guestCartKey: null } },
])(
  'keeps an old $ownerKind button bound to its own snapshot until React commits',
  async ({ cart, ownerKind }) => {
    await mountCart(cart)
    const previous = removeButton()!
    await act(async () => {
      answer(cart, nextProof)
      previous.click()
    })
    expect(sendAction).not.toHaveBeenCalled()

    await act(async () => removeButton()!.click())
    expect(sendAction).toHaveBeenCalledExactlyOnceWith(
      CART_REMOVE_VOUCHER,
      { expectedVoucherId: cart.voucher!.id, ownerKind },
      expect.any(String),
      nextProof,
    )
    await act(async () => succeed(sendAction.mock.calls[0][2]))

    await act(async () => answer(cart, null))
    await act(async () => removeButton()!.click())
    expect(sendAction).toHaveBeenCalledTimes(1)
  },
)
