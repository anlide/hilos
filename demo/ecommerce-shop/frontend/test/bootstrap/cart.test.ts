import {
  SESSION_SIGNAL_SCHEMAS,
  SIGNAL_HANDSHAKE_RESPONSE,
  ScopeManager,
  bindSessionScope,
  createSignal,
  parseSignalValue,
  sessionUserId,
  type HilosConnection,
  type HilosConnectionEventMap,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import {
  CART_SIGNAL_SCHEMAS,
  CART_STATE_SIGNAL,
  cartStateSignalSchema,
  createCart,
} from '../../src/bootstrap/cart.js'

/** A connection that feeds the cart's wire signals and lifecycle events. */
function stubConnection() {
  const listeners = new Map<string, Set<(payload: unknown) => void>>()
  const connection = {
    on: (event: string, listener: (payload: unknown) => void) => {
      const handlers =
        listeners.get(event) ?? new Set<(payload: unknown) => void>()
      handlers.add(listener)
      listeners.set(event, handlers)
      return () => handlers.delete(listener)
    },
  } as unknown as HilosConnection

  function emit<K extends keyof HilosConnectionEventMap & string>(
    event: K,
    payload: HilosConnectionEventMap[K],
  ): void {
    for (const listener of listeners.get(event) ?? []) {
      listener(payload)
    }
  }

  return {
    connection,
    emit,
    deliver: (type: string, data: unknown) => {
      emit('projectSignal', {
        kind: 'project',
        type,
        data,
        envelope: { type, data },
      })
    },
  }
}

/** A cart over the person signed in in a tab, bound to a stub connection. */
function mountCart(signedIn: number | null) {
  const person = createSignal<number | null>(signedIn)
  const { state, cart, bind } = createCart(person)
  const { connection, deliver, emit } = stubConnection()
  bind(connection)

  return { state, cart, person, deliver, emit }
}

/** Mount the cart and the session listener in the shop's boot order. */
function mountCartWithSession() {
  const scopes = new ScopeManager()
  const person = sessionUserId(scopes)
  const { state, cart, bind } = createCart(person)
  const { connection, deliver, emit } = stubConnection()
  bind(connection)
  bindSessionScope(connection, scopes)

  function handshake(userId: number | null): void {
    const parsed = parseSignalValue(
      {
        type: SIGNAL_HANDSHAKE_RESPONSE,
        data: {
          entities: {
            currentUser: userId === null ? null : { id: userId },
            impersonatedBy: null,
          },
          data: { accessLossReason: null },
        },
      },
      SESSION_SIGNAL_SCHEMAS,
    )
    if (!parsed.ok || parsed.signal.kind !== 'project') {
      throw new Error('Expected a valid handshake response')
    }
    emit('projectSignal', parsed.signal)
  }

  return { state, cart, connection, deliver, emit, handshake }
}

describe('cart store', () => {
  it('knows nothing before the first message', () => {
    const { cart } = mountCart(null)

    expect(cart.get()).toBeNull()
  })

  it("gives a guest's message to a tab nobody is signed in to", () => {
    const { cart, deliver } = mountCart(null)

    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [
        { productId: 12, quantity: 2 },
        { productId: 7, quantity: 1 },
      ],
      voucherId: null,
    })

    expect(cart.get()).toEqual([
      { productId: 12, quantity: 2 },
      { productId: 7, quantity: 1 },
    ])
  })

  it('gives an empty cart as an empty list, not as unknown', () => {
    const { cart, deliver } = mountCart(null)

    deliver(CART_STATE_SIGNAL, { userId: null, lines: [], voucherId: null })

    expect(cart.get()).toEqual([])
  })

  it("gives a person's message to the tab that person is signed in to", () => {
    const { cart, deliver } = mountCart(5)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: null,
    })

    expect(cart.get()).toEqual([{ productId: 3, quantity: 4 }])
  })

  it("hides a person's message from a tab another person is signed in to", () => {
    const { cart, deliver } = mountCart(7)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: null,
    })

    expect(cart.get()).toBeNull()
  })

  it("hides a person's message from a guest tab", () => {
    const { cart, deliver } = mountCart(null)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: null,
    })

    expect(cart.get()).toBeNull()
  })

  it("hides a guest's message from a tab somebody is signed in to", () => {
    const { cart, deliver } = mountCart(5)

    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: null,
    })

    expect(cart.get()).toBeNull()
  })

  it('forgets the guest cart when signing in and out without an account snapshot', () => {
    const { state, cart, person, deliver } = mountCart(null)
    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: 11,
    })

    person.set(5)
    person.set(null)

    expect(state.get()).toBeNull()
    expect(cart.get()).toBeNull()
  })

  it('replaces the previous message whole with a newer one', () => {
    const { cart, deliver } = mountCart(5)
    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [
        { productId: 3, quantity: 4 },
        { productId: 8, quantity: 1 },
      ],
      voucherId: null,
    })

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 8, quantity: 2 }],
      voucherId: null,
    })

    expect(cart.get()).toEqual([{ productId: 8, quantity: 2 }])
  })

  it('leaves the store alone for a signal of another type', () => {
    const { cart, deliver } = mountCart(null)
    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
      voucherId: null,
    })

    deliver('guest_identity', { userId: null, lines: [] })

    expect(cart.get()).toEqual([{ productId: 3, quantity: 4 }])
  })

  it('stops listening once unsubscribed', () => {
    const person = createSignal<number | null>(null)
    const { cart, bind } = createCart(person)
    const { connection, deliver } = stubConnection()
    const unsubscribe = bind(connection)

    unsubscribe()
    deliver(CART_STATE_SIGNAL, { userId: null, lines: [], voucherId: null })

    expect(cart.get()).toBeNull()
  })

  it('distinguishes an unknown cart, an empty voucher slot and a selected voucher', () => {
    const { state, cart, deliver } = mountCart(null)
    const emptyCart = { userId: null, lines: [], voucherId: null }

    expect(state.get()).toBeNull()
    deliver(CART_STATE_SIGNAL, emptyCart)
    expect(state.get()).toEqual(emptyCart)
    expect(cart.get()).toEqual([])

    const first = { ...emptyCart, voucherId: 11 }
    deliver(CART_STATE_SIGNAL, first)
    expect(state.get()).toEqual(first)

    const second = { ...emptyCart, voucherId: 17 }
    deliver(CART_STATE_SIGNAL, second)
    expect(state.get()).toEqual(second)
    expect(cart.get()).toEqual([])
  })

  it('keeps the current snapshot through late frames from the previous owner', () => {
    const { state, cart, person, deliver } = mountCart(null)
    const guest = {
      userId: null,
      lines: [{ productId: 12, quantity: 2 }],
      voucherId: 11,
    }
    const account = {
      userId: 5,
      lines: [{ productId: 7, quantity: 1 }],
      voucherId: 17,
    }

    deliver(CART_STATE_SIGNAL, guest)
    person.set(account.userId)
    deliver(CART_STATE_SIGNAL, account)
    deliver(CART_STATE_SIGNAL, { ...guest, voucherId: 19 })
    expect(state.get()).toEqual(account)
    expect(cart.get()).toEqual(account.lines)

    const updatedAccount = { ...account, voucherId: 21 }
    deliver(CART_STATE_SIGNAL, updatedAccount)
    expect(state.get()).toEqual(updatedAccount)
    expect(cart.get()).toEqual(updatedAccount.lines)

    deliver(CART_STATE_SIGNAL, guest)
    person.set(null)
    expect(state.get()).toBeNull()
    expect(cart.get()).toBeNull()
  })

  it.each(['session rotation', 'a socket drop'] as const)(
    'awaits a fresh guest snapshot after %s',
    (cause) => {
      const { state, cart, person, deliver, emit } = mountCart(null)
      const guest = {
        userId: null,
        lines: [{ productId: 12, quantity: 2 }],
        voucherId: 11,
      }
      deliver(CART_STATE_SIGNAL, guest)
      expect(state.get()).toEqual(guest)

      if (cause === 'session rotation') {
        emit('sessionRotate', {
          ticket: 'ticket',
          cookieName: 'session_rotate',
        })
      } else {
        emit('state', 'reconnecting')
        emit('state', 'connected')
      }

      expect(person.get()).toBeNull()
      expect(state.get()).toBeNull()
      expect(cart.get()).toBeNull()

      const newGuest = { userId: null, lines: [], voucherId: 17 }
      deliver(CART_STATE_SIGNAL, newGuest)
      expect(state.get()).toEqual(newGuest)
      expect(cart.get()).toEqual(newGuest.lines)
    },
  )

  it('clears the replaced guest cart during the handshake before the rotation ticket', () => {
    const { state, cart, connection, deliver, emit, handshake } =
      mountCartWithSession()
    const guest = {
      userId: null,
      lines: [{ productId: 12, quantity: 2 }],
      voucherId: 11,
    }
    handshake(null)
    deliver(CART_STATE_SIGNAL, guest)
    expect(state.get()).toEqual(guest)
    expect(cart.get()).toEqual(guest.lines)

    const observed: unknown[] = []
    connection.on('projectSignal', (signal) => {
      if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
        observed.push([state.get(), cart.get()])
      }
    })
    handshake(null)
    expect(observed).toEqual([[null, null]])

    emit('sessionRotate', { ticket: 'ticket', cookieName: 'session_rotate' })
    const replacement = {
      userId: null,
      lines: [{ productId: 7, quantity: 1 }],
      voucherId: 17,
    }
    deliver(CART_STATE_SIGNAL, replacement)
    expect(state.get()).toEqual(replacement)
    expect(cart.get()).toEqual(replacement.lines)
  })

  it('awaits a fresh cart snapshot on a repeated handshake for the same guest', () => {
    const { state, cart, deliver, handshake } = mountCartWithSession()
    const guest = {
      userId: null,
      lines: [{ productId: 12, quantity: 2 }],
      voucherId: 11,
    }
    handshake(null)
    deliver(CART_STATE_SIGNAL, guest)
    expect(state.get()).toEqual(guest)

    handshake(null)
    expect(state.get()).toBeNull()
    expect(cart.get()).toBeNull()

    deliver(CART_STATE_SIGNAL, guest)
    expect(state.get()).toEqual(guest)
    expect(cart.get()).toEqual(guest.lines)
  })

  it('keeps the account cart on a repeated handshake for the same person', () => {
    const { state, cart, deliver, handshake } = mountCartWithSession()
    const account = {
      userId: 5,
      lines: [{ productId: 7, quantity: 1 }],
      voucherId: 17,
    }
    handshake(account.userId)
    deliver(CART_STATE_SIGNAL, account)
    expect(state.get()).toEqual(account)

    handshake(account.userId)
    expect(state.get()).toEqual(account)
    expect(cart.get()).toEqual(account.lines)
  })

  it("shows only the current owner's snapshot when signing in and out", () => {
    const { state, cart, person, deliver } = mountCart(null)
    const guest = {
      userId: null,
      lines: [{ productId: 12, quantity: 2 }],
      voucherId: 11,
    }
    const account = {
      userId: 5,
      lines: [{ productId: 7, quantity: 1 }],
      voucherId: 17,
    }
    deliver(CART_STATE_SIGNAL, guest)
    expect(state.get()).toEqual(guest)

    person.set(account.userId)
    expect(cart.get()).toBeNull()
    deliver(CART_STATE_SIGNAL, { ...guest, voucherId: 19 })
    expect(state.get()).toBeNull()
    expect(cart.get()).toBeNull()

    deliver(CART_STATE_SIGNAL, account)
    expect(state.get()).toEqual(account)
    expect(cart.get()).toEqual(account.lines)

    person.set(null)
    deliver(CART_STATE_SIGNAL, { ...account, voucherId: 21 })
    expect(state.get()).toBeNull()
    expect(cart.get()).toBeNull()

    const returningGuest = { ...guest, voucherId: 23 }
    deliver(CART_STATE_SIGNAL, returningGuest)
    expect(state.get()).toEqual(returningGuest)
    expect(cart.get()).toEqual(returningGuest.lines)
  })
})

describe('cart_state schema', () => {
  it('accepts a guest cart and a person cart', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: null,
        lines: [{ productId: 12, quantity: 2 }],
        voucherId: 23,
      }).success,
    ).toBe(true)
    expect(
      cartStateSignalSchema.safeParse({ userId: 5, lines: [], voucherId: null })
        .success,
    ).toBe(true)
  })

  it('rejects a line with quantity 0', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: 5,
        lines: [{ productId: 12, quantity: 0 }],
        voucherId: null,
      }).success,
    ).toBe(false)
  })

  it('rejects a line without productId', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: 5,
        lines: [{ quantity: 2 }],
        voucherId: null,
      }).success,
    ).toBe(false)
  })

  it('rejects a missing or invalid voucher ID at the connection boundary', () => {
    const cart = { userId: null, lines: [] }
    const frame = { type: CART_STATE_SIGNAL, data: cart }

    expect(parseSignalValue(frame, CART_SIGNAL_SCHEMAS)).toMatchObject({
      ok: false,
      failure: { kind: 'invalid-signal-data', type: CART_STATE_SIGNAL },
    })
    const invalidIds = [0, -1, 1.5, '23', true, Number.MAX_SAFE_INTEGER + 1]
    for (const voucherId of invalidIds) {
      expect(
        parseSignalValue(
          { ...frame, data: { ...cart, voucherId } },
          CART_SIGNAL_SCHEMAS,
        ),
      ).toMatchObject({
        ok: false,
        failure: { kind: 'invalid-signal-data', type: CART_STATE_SIGNAL },
      })
    }
  })
})
