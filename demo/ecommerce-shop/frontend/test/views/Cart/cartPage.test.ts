import {
  SIGNAL_HANDSHAKE_RESPONSE,
  ScopeManager,
  createSignal,
  ingest,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { PAGE_CART, PAGE_MAIN } from '../../../src/pages/keys.js'
import { router } from '../../../src/pages/routes.js'
import { createCartPageSignal } from '../../../src/views/Cart/cartPage.js'
import {
  type CartLine,
  type CartSnapshot,
} from '../../../src/views/Cart/types/Cart.js'

const line: CartLine = {
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
const guestCart: CartSnapshot = {
  userId: null,
  lines: [line],
  itemsTotal: '24000',
}

/** Open the Cart scope with local session and connection events. */
function openCart(userId: number | null = null) {
  const scopes = new ScopeManager()
  const person = createSignal<number | null>(userId)
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    (data: never) => void
  >()
  let state: ConnectionState = 'connected'
  const connection: Pick<HilosConnection, 'on' | 'state'> = {
    get state() {
      return state
    },
    on(event, listener) {
      listeners.set(event, listener)
      return () => listeners.delete(event)
    },
  }
  function emit<K extends keyof HilosConnectionEventMap>(
    event: K,
    data: HilosConnectionEventMap[K],
  ): void {
    listeners.get(event)?.(data as never)
  }

  const snapshot = createCartPageSignal(scopes, person, connection)
  scopes.openPage(PAGE_CART)

  return {
    snapshot,
    person,
    scopes,
    answer: (cart: unknown) => ingest(scopes.page()!, { data: { cart } }),
    changeState: (next: ConnectionState) => {
      state = next
      emit('state', state)
    },
    rotate: () =>
      emit('sessionRotate', { ticket: 'ticket', cookieName: 'cookie' }),
    handshake: () => {
      const type = SIGNAL_HANDSHAKE_RESPONSE
      const data = {}
      emit('projectSignal', {
        kind: 'project',
        type,
        data,
        envelope: { type, data },
      })
    },
  }
}

describe('Cart page projection', () => {
  it('has a public route and waits for a complete answer before showing an empty cart', () => {
    expect(router.match('/cart')).toEqual({
      page: PAGE_CART,
      admin: false,
      params: {},
    })

    const { snapshot, answer } = openCart()
    expect(snapshot.get()).toBeNull()

    answer({ lines: [], itemsTotal: '0' })
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart, lines: [{ ...line, availableQuantity: '2' }] })
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart, itemsTotal: 0.5 })
    expect(snapshot.get()).toBeNull()

    const empty = { userId: null, lines: [], itemsTotal: '0' }
    answer(empty)
    expect(snapshot.get()).toEqual(empty)
  })

  it.each([
    undefined,
    24000,
    '',
    '00',
    '024000',
    '+1',
    '-1',
    '1.5',
    '1e3',
    ' 1',
    '1 ',
    '1\n',
  ])('rejects %j for either required total', (invalid) => {
    const { snapshot, answer } = openCart()

    answer({ ...guestCart, itemsTotal: invalid })
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart, lines: [{ ...line, lineTotal: invalid }] })
    expect(snapshot.get()).toBeNull()
  })

  it('keeps every digit in the first and live answers', () => {
    const { snapshot, answer } = openCart()
    const largeLine = {
      ...line,
      price: 99_999_999,
      quantity: 90_999_909,
      availableQuantity: 90_999_909,
      lineTotal: '9099990809000091',
    }
    const initial = {
      ...guestCart,
      lines: [largeLine],
      itemsTotal: '9099990809000091',
    }
    answer(initial)
    expect(snapshot.get()).toEqual(initial)

    answer({ ...initial, itemsTotal: '9099990809000091\n' })
    expect(snapshot.get()).toEqual(initial)

    const live = {
      ...initial,
      lines: [
        { ...largeLine, price: 99_999_998, lineTotal: '9099990718000182' },
      ],
      itemsTotal: '9099990718000182',
    }
    answer(live)
    expect(snapshot.get()).toEqual(live)
  })

  it('replaces the full snapshot as price and stock change while keeping the saved quantity', () => {
    const { snapshot, answer } = openCart()
    const unique = {
      ...line,
      productId: 8,
      slug: 'morning-meadow',
      name: 'Morning meadow',
      kind: 'unique',
      quantity: 1,
      availableQuantity: 1,
      lineTotal: '12000',
    }
    const initial = { userId: null, lines: [line, unique], itemsTotal: '36000' }
    answer(initial)
    expect(snapshot.get()).toEqual(initial)

    const restocked = {
      ...line,
      availableQuantity: 8,
      price: 13000,
      lineTotal: '65000',
    }
    answer({ userId: null, lines: [restocked], itemsTotal: '65000' })
    expect(snapshot.get()).toEqual({
      userId: null,
      lines: [restocked],
      itemsTotal: '65000',
    })

    const unavailable = { ...restocked, availableQuantity: 0, lineTotal: '0' }
    answer({ userId: null, lines: [unavailable], itemsTotal: '0' })
    expect(snapshot.get()).toEqual({
      userId: null,
      lines: [unavailable],
      itemsTotal: '0',
    })
  })

  it('only projects snapshots from the active Cart scope', () => {
    const { snapshot, scopes, answer } = openCart()
    answer(guestCart)
    expect(snapshot.get()).toEqual(guestCart)

    scopes.openPage(PAGE_MAIN)
    answer(guestCart)
    expect(snapshot.get()).toBeNull()

    scopes.openPage(PAGE_CART)
    expect(snapshot.get()).toBeNull()
    answer(guestCart)
    expect(snapshot.get()).toEqual(guestCart)
  })

  it('clears on owner changes in place and ignores late replies from a previous owner', () => {
    const { snapshot, person, answer } = openCart()
    answer(guestCart)
    expect(snapshot.get()).toEqual(guestCart)

    const accountCart = { userId: 9, lines: [], itemsTotal: '0' }
    person.set(accountCart.userId)
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart })
    expect(snapshot.get()).toBeNull()
    answer(accountCart)
    expect(snapshot.get()).toEqual(accountCart)
    answer({ ...guestCart })
    expect(snapshot.get()).toEqual(accountCart)

    person.set(null)
    expect(snapshot.get()).toBeNull()
    answer({ ...accountCart })
    expect(snapshot.get()).toBeNull()
    const nextGuest = { userId: null, lines: [], itemsTotal: '0' }
    answer(nextGuest)
    expect(snapshot.get()).toEqual(nextGuest)
  })

  it('does not revive the guest value still in the scope when returning to guest', () => {
    const { snapshot, person, scopes, answer } = openCart()
    answer(guestCart)
    person.set(9)
    expect(snapshot.get()).toBeNull()
    person.set(null)
    expect(scopes.pageDataSignal('cart').get()).toBe(guestCart)
    expect(snapshot.get()).toBeNull()

    answer({ ...guestCart })
    expect(snapshot.get()).toEqual(guestCart)
  })

  it('waits for a fresh Cart answer after a new guest handshake on the same socket', () => {
    const { snapshot, answer, handshake } = openCart()
    answer(guestCart)
    handshake()
    expect(snapshot.get()).toBeNull()

    const nextGuest = { userId: null, lines: [], itemsTotal: '0' }
    answer(nextGuest)
    expect(snapshot.get()).toEqual(nextGuest)
  })

  it('clears on rotation and waits for the new socket to answer', () => {
    const { snapshot, answer, rotate, changeState } = openCart()
    answer(guestCart)
    rotate()
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart })
    expect(snapshot.get()).toBeNull()

    changeState('reconnecting')
    changeState('connected')
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart })
    expect(snapshot.get()).toEqual(guestCart)
  })

  it('clears an account cart on a socket drop even if the owner stays the same', () => {
    const accountCart = { ...guestCart, userId: 9 }
    const { snapshot, answer, changeState } = openCart(accountCart.userId)
    answer(accountCart)
    expect(snapshot.get()).toEqual(accountCart)

    changeState('disconnected')
    expect(snapshot.get()).toBeNull()
    answer({ ...accountCart })
    changeState('connected')
    expect(snapshot.get()).toBeNull()
    answer({ ...accountCart })
    expect(snapshot.get()).toEqual(accountCart)
  })
})
