import {
  createSignal,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import {
  CART_STATE_SIGNAL,
  cartStateSignalSchema,
  createCart,
} from '../../src/bootstrap/cart.js'

/** A connection that only carries project-signal listeners, and a way to feed them. */
function stubConnection(): {
  connection: HilosConnection
  deliver: (type: string, data: unknown) => void
} {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    on: (event: string, listener: (signal: ProjectSignal) => void) => {
      if (event === 'projectSignal') {
        listeners.add(listener)
      }
      return () => listeners.delete(listener)
    },
  } as unknown as HilosConnection

  return {
    connection,
    deliver: (type, data) => {
      for (const listener of listeners) {
        listener({ kind: 'project', type, data } as ProjectSignal)
      }
    },
  }
}

/** A cart over the person signed in in a tab, bound to a stub connection. */
function mountCart(signedIn: number | null) {
  const person = createSignal<number | null>(signedIn)
  const { cart, bind } = createCart(person)
  const { connection, deliver } = stubConnection()
  bind(connection)

  return { cart, person, deliver }
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
    })

    expect(cart.get()).toEqual([
      { productId: 12, quantity: 2 },
      { productId: 7, quantity: 1 },
    ])
  })

  it('gives an empty cart as an empty list, not as unknown', () => {
    const { cart, deliver } = mountCart(null)

    deliver(CART_STATE_SIGNAL, { userId: null, lines: [] })

    expect(cart.get()).toEqual([])
  })

  it("gives a person's message to the tab that person is signed in to", () => {
    const { cart, deliver } = mountCart(5)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
    })

    expect(cart.get()).toEqual([{ productId: 3, quantity: 4 }])
  })

  it("hides a person's message from a tab another person is signed in to", () => {
    const { cart, deliver } = mountCart(7)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
    })

    expect(cart.get()).toBeNull()
  })

  it("hides a person's message from a guest tab", () => {
    const { cart, deliver } = mountCart(null)

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 3, quantity: 4 }],
    })

    expect(cart.get()).toBeNull()
  })

  it("hides a guest's message from a tab somebody is signed in to", () => {
    const { cart, deliver } = mountCart(5)

    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
    })

    expect(cart.get()).toBeNull()
  })

  it('stops showing a message once its owner is no longer the one signed in', () => {
    const { cart, person, deliver } = mountCart(null)
    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
    })

    person.set(5)

    expect(cart.get()).toBeNull()

    person.set(null)

    expect(cart.get()).toEqual([{ productId: 3, quantity: 4 }])
  })

  it('replaces the previous message whole with a newer one', () => {
    const { cart, deliver } = mountCart(5)
    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [
        { productId: 3, quantity: 4 },
        { productId: 8, quantity: 1 },
      ],
    })

    deliver(CART_STATE_SIGNAL, {
      userId: 5,
      lines: [{ productId: 8, quantity: 2 }],
    })

    expect(cart.get()).toEqual([{ productId: 8, quantity: 2 }])
  })

  it('leaves the store alone for a signal of another type', () => {
    const { cart, deliver } = mountCart(null)
    deliver(CART_STATE_SIGNAL, {
      userId: null,
      lines: [{ productId: 3, quantity: 4 }],
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
    deliver(CART_STATE_SIGNAL, { userId: null, lines: [] })

    expect(cart.get()).toBeNull()
  })
})

describe('cart_state schema', () => {
  it('accepts a guest cart and a person cart', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: null,
        lines: [{ productId: 12, quantity: 2 }],
      }).success,
    ).toBe(true)
    expect(
      cartStateSignalSchema.safeParse({ userId: 5, lines: [] }).success,
    ).toBe(true)
  })

  it('rejects a line with quantity 0', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: 5,
        lines: [{ productId: 12, quantity: 0 }],
      }).success,
    ).toBe(false)
  })

  it('rejects a line without productId', () => {
    expect(
      cartStateSignalSchema.safeParse({
        userId: 5,
        lines: [{ quantity: 2 }],
      }).success,
    ).toBe(false)
  })
})
