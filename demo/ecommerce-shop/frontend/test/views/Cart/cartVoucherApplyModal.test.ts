// @vitest-environment happy-dom

import {
  SIGNAL_TYPE_ACTION_ERROR,
  USER_ENTITY_TYPE,
  ingest,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
} from '@hilos/core/base'
import { HilosTakeoverViewOnlyContext } from '@hilos/react/base'
import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_CART } from '../../../src/pages/keys.js'
import Cart from '../../../src/views/Cart/Cart.js'
import { CART_APPLY_VOUCHER } from '../../../src/views/Cart/cartActions.js'
import { type CartSnapshot } from '../../../src/views/Cart/types/Cart.js'

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
  voucher: null,
  total: '1000',
}
const empty = { ...guest, lines: [], itemsTotal: '0', total: '0' }
const discounted: CartSnapshot = {
  ...guest,
  voucher: { id: 20, code: 'SAVE20', active: true, discount: '200' },
  total: '800',
}
const sendAction = vi.mocked(wire.connection.sendAction)
let root: Root

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  scopes.openPage(PAGE_CART)
  root = createRoot(document.body.appendChild(document.createElement('div')))
})

afterEach(async () => {
  await act(async () => {
    root.unmount()
    wire.changeState('disconnected')
    scopes.dropPage()
    scopes.session.data.set('currentUser', undefined)
    wire.changeState('connected')
  })
  document.body.replaceChildren()
  sendAction.mockClear()
  vi.unstubAllGlobals()
})

function render(lookOnly = false): void {
  root.render(
    createElement(
      HilosTakeoverViewOnlyContext.Provider,
      { value: lookOnly },
      createElement(Cart),
    ),
  )
}

function answer(cart: CartSnapshot): void {
  ingest(scopes.page()!, { data: { cart } })
}

function signIn(userId: number): void {
  ingest(
    scopes.session,
    { entities: { currentUser: { id: userId } } },
    { entityTypes: { currentUser: USER_ENTITY_TYPE } },
  )
}

function trigger(): HTMLButtonElement {
  return document.querySelector('[data-id="cart-voucher-apply-open"]')!
}

function dialog(): HTMLElement | null {
  return document.querySelector('[role="dialog"][aria-label="Apply voucher"]')
}

async function open() {
  await act(async () => trigger().click())
  const input = dialog()!.querySelector<HTMLInputElement>(
    '[data-id="cart-voucher-apply-code"]',
  )!
  const submit = dialog()!.querySelector<HTMLButtonElement>(
    '[data-id="cart-voucher-apply-submit"]',
  )!
  return { input, form: input.form!, submit }
}

function button(label: string): HTMLButtonElement {
  return [
    ...document.querySelectorAll<HTMLButtonElement>(
      '[role="dialog"] button, [role="alertdialog"] button',
    ),
  ].find((control) => control.textContent?.trim() === label)!
}

function type(input: HTMLInputElement, value: string): void {
  Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value',
  )!.set!.call(input, value)
  input.dispatchEvent(new Event('input', { bubbles: true }))
}

function success(requestId: string | undefined): void {
  wire.emit('actionSuccess', {
    kind: 'actionSuccess',
    action: CART_APPLY_VOUCHER,
    requestId,
    message: undefined,
    reply: undefined,
    envelope: {
      type: 'action_success',
      data: { action: CART_APPLY_VOUCHER },
    },
  })
}

function fail(requestId: string | undefined, reason: string): void {
  wire.emit('actionError', {
    kind: 'actionError',
    action: CART_APPLY_VOUCHER,
    requestId,
    reason,
    errorCode: undefined,
    errorType: undefined,
    errorDetail: undefined,
    envelope: {
      type: SIGNAL_TYPE_ACTION_ERROR,
      data: { action: CART_APPLY_VOUCHER, reason },
    },
  })
}

it('labels the action from the accepted voucher and opens an empty, focused field', async () => {
  await act(async () => {
    answer(empty)
    render()
  })
  expect(trigger()).toBeNull()
  await act(async () => answer(guest))
  const opener = trigger()
  expect(opener.textContent).toBe('Apply voucher')
  const { input, form, submit } = await open()

  expect(input.labels?.[0]?.textContent).toBe('Voucher code')
  expect(input.value).toBe('')
  expect(document.activeElement).toBe(input)
  expect(submit.disabled).toBe(true)
  await act(async () => type(input, '   '))
  expect(submit.disabled).toBe(true)
  await act(async () => form.requestSubmit())
  expect(sendAction).not.toHaveBeenCalled()
  await act(async () => button('Cancel').click())
  await act(async () => button('Keep editing').click())
  expect(input.value).toBe('   ')
  await act(async () => {
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
  })
  await act(async () => button('Discard').click())
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(opener)

  await act(async () =>
    answer({
      ...guest,
      voucher: { id: 20, code: 'SAVE20', active: false, discount: '0' },
    }),
  )
  expect(trigger().textContent).toBe('Apply another voucher')
  expect((await open()).input.value).toBe('')
})

it('dispatches the original code once and closes only on its own success', async () => {
  await act(async () => {
    answer(guest)
    render()
  })
  const opener = trigger()
  const { input, form, submit } = await open()
  await act(async () => type(input, '  sAvE20 '))
  await act(async () => {
    submit.click()
    form.requestSubmit()
  })
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    CART_APPLY_VOUCHER,
    { code: '  sAvE20 ', ownerKind: 'guest' },
    expect.any(String),
  )
  expect(submit.getAttribute('aria-busy')).toBe('true')
  await act(async () => answer({ ...guest }))
  await act(async () => success('other-request'))
  expect(dialog()).not.toBeNull()
  await act(async () => success(sendAction.mock.calls[0][2]))
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(opener)
  expect(opener.textContent).toBe('Apply voucher')
  await act(async () => answer(discounted))
  expect(opener.textContent).toBe('Apply another voucher')
})

it('shows the matching server reason and lets the buyer correct and retry', async () => {
  const userId = 17
  await act(async () => {
    signIn(userId)
    answer({ ...discounted, userId, guestCartKey: null })
    render()
  })
  const { input, form, submit } = await open()
  const errorRoom = dialog()!.querySelector(
    '[data-id="cart-voucher-apply-error"]',
  )!
  const total = document.querySelector('[data-id="shop-cart-total"]')!
  await act(async () => type(input, 'SPELLING'))
  await act(async () => form.requestSubmit())
  await act(async () => fail('other-request', 'Another action failed'))
  expect(errorRoom.textContent?.trim()).toBe('')

  const reason = 'There is no voucher with this code.'
  await act(async () => fail(sendAction.mock.calls[0][2], reason))
  expect(errorRoom.textContent).toContain(reason)
  expect(input.value).toBe('SPELLING')
  expect(total.textContent).toBe('₴8')
  expect(submit.disabled).toBe(false)
  expect(sendAction).toHaveBeenCalledTimes(1)

  await act(async () => type(input, 'SAVE20'))
  await act(async () => form.requestSubmit(submit))
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    CART_APPLY_VOUCHER,
    { code: 'SAVE20', ownerKind: 'account' },
    expect.any(String),
  )
  await act(async () => success(sendAction.mock.calls[1][2]))
  expect(dialog()).toBeNull()
})

it('keeps a draft through live updates and freshness loss, and retires it with its cart', async () => {
  await act(async () => {
    answer(guest)
    render()
  })
  const { input, form, submit } = await open()
  await act(async () => type(input, 'SAVE30'))
  await act(async () => answer(discounted))
  expect(dialog()?.contains(input)).toBe(true)
  expect(input.value).toBe('SAVE30')
  expect(trigger().textContent).toBe('Apply another voucher')
  await act(async () => form.requestSubmit())
  const droppedRequest = sendAction.mock.calls[0][2]

  await act(async () => wire.changeState('reconnecting'))
  expect(submit.disabled).toBe(true)
  await act(async () => {
    wire.changeState('connected')
    form.requestSubmit()
    success(droppedRequest)
  })
  expect(submit.disabled).toBe(true)
  expect(input.value).toBe('SAVE30')
  expect(sendAction).toHaveBeenCalledTimes(1)

  await act(async () => {
    answer({ ...discounted })
    render(true)
  })
  expect(submit.disabled).toBe(true)
  await act(async () => form.requestSubmit())
  expect(sendAction).toHaveBeenCalledTimes(1)
  await act(async () => render())
  expect(submit.disabled).toBe(false)
  expect(sendAction).toHaveBeenCalledTimes(1)
  await act(async () => form.requestSubmit())
  const originalRequest = sendAction.mock.calls[1][2]

  await act(async () => answer(empty))
  expect(trigger()).toBeNull()
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(
    document.querySelector('section[aria-label="Cart"]'),
  )
  await act(async () => answer(guest))
  const second = await open()
  expect(second.input.value).toBe('')
  await act(async () => success(originalRequest))
  expect(dialog()?.contains(second.input)).toBe(true)

  await act(async () => wire.changeState('reconnecting'))
  expect(dialog()?.contains(second.input)).toBe(true)
  await act(async () => signIn(17))
  expect(dialog()).toBeNull()
})
