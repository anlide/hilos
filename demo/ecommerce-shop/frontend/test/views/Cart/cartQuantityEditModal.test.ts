// @vitest-environment happy-dom

import {
  SIGNAL_TYPE_ACTION_ERROR,
  USER_ENTITY_TYPE,
  ingest,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
} from '@hilos/core/base'
import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { actions } from '../../../src/bootstrap/connection.js'
import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_CART } from '../../../src/pages/keys.js'
import Cart from '../../../src/views/Cart/Cart.js'
import { CART_SET_QUANTITY } from '../../../src/views/Cart/cartActions.js'
import {
  type CartLine,
  type CartSnapshot,
} from '../../../src/views/Cart/types/Cart.js'

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

const stock: CartLine = {
  lineId: 11,
  productId: 7,
  slug: 'pine-bark',
  name: 'Pine bark',
  kind: 'stock',
  pictureUrl: '/pictures/pine-bark.webp',
  price: 12000,
  quantity: 5,
  availableQuantity: 2,
  lineTotal: '24000',
}
const guest: CartSnapshot = {
  userId: null,
  guestCartKey: 'guest-cart',
  lines: [stock],
  itemsTotal: '24000',
  voucher: null,
  total: '24000',
}
const empty = { ...guest, lines: [], itemsTotal: '0', total: '0' }
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
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

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

function dialog(): HTMLElement | null {
  return document.querySelector('[role="dialog"][aria-modal="true"]')
}

function button(
  label: string,
  within: ParentNode = dialog()!,
): HTMLButtonElement {
  return [...within.querySelectorAll('button')].find(
    (control) =>
      (control.getAttribute('aria-label') ?? control.textContent?.trim()) ===
      label,
  )!
}

async function openQuantity(cart = guest) {
  await act(async () => {
    answer(cart)
    root.render(createElement(Cart))
  })
  const trigger = button('Set quantity', document)
  await act(async () => trigger.click())
  const input = dialog()!.querySelector('input')!

  return { trigger, input, form: input.form! }
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
    action: CART_SET_QUANTITY,
    requestId,
    message: undefined,
    reply: undefined,
    envelope: { type: 'action_success', data: { action: CART_SET_QUANTITY } },
  })
}

function fail(requestId: string | undefined, reason: string): void {
  wire.emit('actionError', {
    kind: 'actionError',
    action: CART_SET_QUANTITY,
    requestId,
    reason,
    errorCode: undefined,
    errorType: undefined,
    errorDetail: undefined,
    envelope: {
      type: SIGNAL_TYPE_ACTION_ERROR,
      data: { action: CART_SET_QUANTITY, reason },
    },
  })
}

it('opens a named, focused quantity field only for a stock line', async () => {
  const unique: CartLine = {
    ...stock,
    lineId: 12,
    productId: 8,
    name: 'Morning meadow',
    kind: 'unique',
    quantity: 1,
    availableQuantity: 1,
  }
  const { trigger, input, form } = await openQuantity({
    ...guest,
    lines: [stock, unique],
  })
  const uniqueRow = [...document.querySelectorAll('li')].find((line) =>
    line.textContent?.includes(unique.name),
  )
  expect(uniqueRow?.textContent).toContain('1 · one of a kind')
  expect(uniqueRow?.querySelector('[data-id="shop-cart-quantity"]')).toBeNull()
  expect(dialog()?.getAttribute('aria-label')).toBe('Quantity: Pine bark')
  expect(input.labels?.[0]?.textContent).toBe('Quantity')
  expect(input.value).toBe('5')
  expect(document.activeElement).toBe(input)
  expect(button('Set').disabled).toBe(true)

  await act(async () => type(input, ''))
  expect(input.required).toBe(true)
  expect(button('Set').disabled).toBe(true)
  await act(async () => form.requestSubmit())
  await act(async () => type(input, ' 005 '))
  expect(button('Set').disabled).toBe(true)
  await act(async () => form.requestSubmit())
  expect(sendAction).not.toHaveBeenCalled()
  await act(async () => button('Cancel').click())
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(trigger)

  await act(async () => trigger.click())
  const reopened = dialog()!.querySelector('input')!
  await act(async () => type(reopened, '4'))
  await act(async () => {
    reopened.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
  })
  const confirmation = document.querySelector(
    '[aria-label="Discard changes?"]',
  )!
  expect(confirmation).not.toBeNull()
  await act(async () => button('Discard', confirmation).click())
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(trigger)
})

it('returns focus to the recreated Quantity button after reconnecting', async () => {
  const { trigger } = await openQuantity()
  await act(async () => wire.changeState('reconnecting'))
  expect(trigger.isConnected).toBe(false)
  await act(async () => {
    wire.changeState('connected')
    answer({ ...guest })
  })

  const replacement = button('Set quantity', document)
  expect(replacement).not.toBe(trigger)
  await act(async () => button('Cancel').click())
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(replacement)
})

it('sends one raw draft and closes on its own success while the row follows the snapshot', async () => {
  const { input, form, trigger } = await openQuantity()
  await act(async () => type(input, ' 0002 '))
  const set = button('Set')
  await act(async () => {
    set.click()
    form.requestSubmit()
  })
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    CART_SET_QUANTITY,
    {
      lineId: stock.lineId,
      ownerKind: 'guest',
      quantity: ' 0002 ',
      expectedQuantity: 5,
    },
    expect.any(String),
  )
  expect(set.getAttribute('aria-busy')).toBe('true')
  await act(async () => success('unrelated-request'))
  expect(dialog()).not.toBeNull()
  await act(async () => success(sendAction.mock.calls[0][2]))
  expect(dialog()).toBeNull()
  expect(document.activeElement).toBe(trigger)
  expect(trigger.textContent).toBe('Quantity: 5')
  await act(async () =>
    answer({ ...guest, lines: [{ ...stock, quantity: 2 }] }),
  )
  expect(trigger.textContent).toBe('Quantity: 2')
})

it('shows the matching refusal verbatim and submits the corrected draft', async () => {
  const { input, form, trigger } = await openQuantity()
  await act(async () => type(input, '3'))
  await act(async () => form.requestSubmit())
  await act(async () => fail('other-request', 'Another action failed'))
  expect(dialog()?.textContent).not.toContain('Another action failed')
  await act(async () => fail(sendAction.mock.calls[0][2], 'Only 2 in stock'))
  const errors = dialog()!.querySelectorAll('[data-id="hilos-action-error"]')
  expect(errors).toHaveLength(1)
  expect(errors[0].textContent).toContain('Only 2 in stock')
  expect(input.value).toBe('3')
  expect(trigger.textContent).toBe('Quantity: 5')

  await act(async () => type(input, '2'))
  await act(async () => form.requestSubmit(button('Set')))
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    CART_SET_QUANTITY,
    {
      lineId: stock.lineId,
      ownerKind: 'guest',
      quantity: '2',
      expectedQuantity: 5,
    },
    expect.any(String),
  )
  await act(async () => success(sendAction.mock.calls[1][2]))
  expect(dialog()).toBeNull()
})

it('refreshes the stock hint without changing or gating a new quantity', async () => {
  const { input, form } = await openQuantity()
  const hint = document.getElementById(input.getAttribute('aria-describedby')!)!
  expect(hint.textContent).toBe('Pine bark · 2 in stock')
  const draft = '9007199254740993'
  await act(async () => type(input, draft))
  await act(async () => {
    answer({ ...guest, lines: [{ ...stock, availableQuantity: 0 }] })
  })
  expect(hint.textContent).toBe('Pine bark · 0 in stock')
  expect(input.value).toBe(draft)
  expect(button('Set').disabled).toBe(false)
  await act(async () => form.requestSubmit())
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    CART_SET_QUANTITY,
    {
      lineId: stock.lineId,
      ownerKind: 'guest',
      quantity: draft,
      expectedQuantity: 5,
    },
    expect.any(String),
  )
})

it('takes untouched and matching live changes and saves Keep mine with the new baseline', async () => {
  const { input, form } = await openQuantity()
  const liveQuantity = (quantity: number) =>
    answer({ ...guest, lines: [{ ...stock, quantity }] })

  await act(async () => liveQuantity(4))
  expect(input.value).toBe('4')
  await act(async () => type(input, '003'))
  await act(async () => liveQuantity(3))
  expect(input.value).toBe('003')
  expect(button('Set').disabled).toBe(true)
  expect(button('Keep mine')).toBeUndefined()

  await act(async () => type(input, '2'))
  await act(async () => liveQuantity(6))
  expect(input.value).toBe('2')
  expect(dialog()?.textContent).toContain('Quantity changed elsewhere to 6.')
  expect(button('Take theirs')).toBeDefined()
  expect(button('Set').disabled).toBe(true)
  await act(async () => form.requestSubmit())
  expect(sendAction).not.toHaveBeenCalled()
  await act(async () => button('Keep mine').click())
  await act(async () => form.requestSubmit())
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    CART_SET_QUANTITY,
    {
      lineId: stock.lineId,
      ownerKind: 'guest',
      quantity: '2',
      expectedQuantity: 6,
    },
    expect.any(String),
  )
})

it('keeps the draft until the same guest reconnects and ignores success from the dropped transport', async () => {
  const { input, form } = await openQuantity()
  await act(async () => {
    type(input, '3')
    form.requestSubmit()
  })
  const oldRequestId = sendAction.mock.calls[0][2]
  await act(async () => wire.changeState('reconnecting'))
  expect(input.value).toBe('3')
  expect(button('Set').disabled).toBe(true)
  expect(button('Deleted')).toBeUndefined()
  await act(async () => form.requestSubmit())
  expect(sendAction).toHaveBeenCalledTimes(1)
  await act(async () => {
    wire.changeState('connected')
    success(oldRequestId)
  })
  expect(dialog()).not.toBeNull()
  expect(button('Set').disabled).toBe(true)
  await act(async () => {
    answer({ ...guest })
  })
  expect(input.value).toBe('3')
  expect(button('Set').disabled).toBe(false)
  await act(async () => form.requestSubmit())
  expect(sendAction).toHaveBeenCalledTimes(2)
  await act(async () => success(oldRequestId))
  expect(button('Set').getAttribute('aria-busy')).toBe('true')
  await act(async () => success(sendAction.mock.calls[1][2]))
  expect(dialog()).toBeNull()
})

it('keeps the draft after a confirmed deletion and falls back to focusing Cart', async () => {
  const { input, form } = await openQuantity()
  await act(async () => type(input, '3'))
  await act(async () => answer(empty))
  expect(input.value).toBe('3')
  expect(button('Deleted').disabled).toBe(true)
  await act(async () => form.requestSubmit())
  expect(sendAction).not.toHaveBeenCalled()
  await act(async () => button('Cancel').click())
  await act(async () => button('Discard', document).click())
  expect(document.activeElement).toBe(
    document.querySelector('section[aria-label="Cart"]'),
  )
})

it('closes the edit when a new guest answers with an empty cart', async () => {
  const { input } = await openQuantity()
  await act(async () => type(input, '3'))
  await act(async () => wire.changeState('reconnecting'))
  await act(async () => wire.changeState('connected'))
  expect(input.value).toBe('3')
  expect(button('Deleted')).toBeUndefined()

  await act(async () => answer({ ...empty, guestCartKey: 'another-guest' }))
  expect(dialog()).toBeNull()
  expect(document.body.textContent).toContain('Your cart is empty')
  expect(document.body.textContent).not.toContain('Deleted')
  expect(sendAction).not.toHaveBeenCalled()
})

it('settles an old guest request on the same socket without closing a new guest edit', async () => {
  const dispatch = vi.spyOn(actions, 'dispatch')
  const { input, form, trigger } = await openQuantity()
  await act(async () => {
    type(input, '2')
    form.requestSubmit()
  })
  const old = dispatch.mock.results[0].value
  const settled = vi.fn()
  void old.done.then(settled, settled)
  expect(button('Set').getAttribute('aria-busy')).toBe('true')

  await act(async () => answer({ ...guest, guestCartKey: 'another-guest' }))
  expect(dialog()).toBeNull()
  expect(trigger.isConnected).toBe(false)
  expect(settled).not.toHaveBeenCalled()
  await act(async () => button('Set quantity', document).click())
  const nextInput = dialog()!.querySelector('input')!
  expect(nextInput.value).toBe('5')
  await act(async () => {
    type(nextInput, '4')
    nextInput.form!.requestSubmit()
  })

  await act(async () => success(old.requestId))
  expect(settled).toHaveBeenCalledTimes(1)
  expect(settled).toHaveBeenCalledWith({ message: undefined, reply: undefined })
  expect(dialog()?.contains(nextInput)).toBe(true)
  expect(nextInput.value).toBe('4')
  expect(button('Set').getAttribute('aria-busy')).toBe('true')
  await act(async () => success(sendAction.mock.calls[1][2]))
  expect(dialog()).toBeNull()
})

it('closes on explicit session rotation and on a change of account', async () => {
  const { input } = await openQuantity()
  await act(async () => type(input, '2'))
  await act(async () =>
    wire.emit('sessionRotate', { ticket: 'ticket', cookieName: 'cookie' }),
  )
  expect(dialog()).toBeNull()
  await act(async () => {
    wire.changeState('reconnecting')
    wire.changeState('connected')
    signIn(9)
    answer({ ...guest, userId: 9, guestCartKey: null })
  })
  expect(dialog()).toBeNull()
  const account = await openQuantity({
    ...guest,
    userId: 9,
    guestCartKey: null,
  })
  expect(account.input.value).toBe('5')
  await act(async () => type(account.input, '4'))
  await act(async () => account.form.requestSubmit())
  expect(sendAction).toHaveBeenLastCalledWith(
    CART_SET_QUANTITY,
    {
      lineId: stock.lineId,
      ownerKind: 'account',
      quantity: '4',
      expectedQuantity: 5,
    },
    expect.any(String),
  )
  await act(async () => success(sendAction.mock.calls[0][2]))
  expect(dialog()).toBeNull()

  await act(async () => account.trigger.click())
  await act(async () => type(dialog()!.querySelector('input')!, '3'))
  await act(async () => {
    signIn(10)
    answer({ ...guest, userId: 10, guestCartKey: null })
  })
  expect(dialog()).toBeNull()
  expect(button('Set quantity', document)).not.toBe(account.trigger)
})
