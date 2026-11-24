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
  lineId: 19,
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
  voucher: null,
  total: '24000',
}
const emptyCart: CartSnapshot = {
  userId: null,
  lines: [],
  itemsTotal: '0',
  voucher: null,
  total: '0',
}
const voucherCart: CartSnapshot = {
  ...guestCart,
  voucher: { code: 'SAVE25', active: true, discount: '6000' },
  total: '18000',
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

    answer({ userId: null, lines: [], itemsTotal: '0', voucher: null })
    expect(snapshot.get()).toBeNull()
    answer({ userId: null, lines: [], itemsTotal: '0', total: '0' })
    expect(snapshot.get()).toBeNull()

    answer(emptyCart)
    expect(snapshot.get()).toEqual(emptyCart)
  })

  it('requires a positive integer lineId on every line of the snapshot', () => {
    const { snapshot, answer } = openCart()
    const { lineId, ...withoutLineId } = line

    answer({ ...guestCart, lines: [withoutLineId] })
    expect(snapshot.get()).toBeNull()

    answer(guestCart)
    expect(snapshot.get()?.lines[0]?.lineId).toBe(lineId)

    for (const invalidId of [null, '19', 0, -1, 1.5, true]) {
      answer({
        ...guestCart,
        lines: [line, { ...line, productId: 8, lineId: invalidId }],
      })
      expect(snapshot.get()).toEqual(guestCart)
    }
  })

  it.each([undefined, null, 0, -1, 1.5, '19', true])(
    'rejects %j for the required saved line ID',
    (lineId) => {
      const { snapshot, answer } = openCart()

      answer({ ...guestCart, lines: [{ ...line, lineId }] })
      expect(snapshot.get()).toBeNull()

      answer(guestCart)
      expect(snapshot.get()).toEqual(guestCart)
    },
  )

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
  ])('rejects %j for every required amount', (invalid) => {
    const { snapshot, answer } = openCart()

    answer({ ...guestCart, itemsTotal: invalid })
    expect(snapshot.get()).toBeNull()
    answer({ ...guestCart, lines: [{ ...line, lineTotal: invalid }] })
    expect(snapshot.get()).toBeNull()
    answer({ ...voucherCart, total: invalid })
    expect(snapshot.get()).toBeNull()
    answer({
      ...voucherCart,
      voucher: { ...voucherCart.voucher, discount: invalid },
    })
    expect(snapshot.get()).toBeNull()
  })

  it('takes an active, a switched-off and no voucher, and refuses one without its parts', () => {
    const { snapshot, answer } = openCart()

    answer(voucherCart)
    expect(snapshot.get()).toEqual(voucherCart)

    const switchedOff = {
      ...guestCart,
      voucher: { code: 'SAVE25', active: false, discount: '0' },
    }
    answer(switchedOff)
    expect(snapshot.get()).toEqual(switchedOff)

    answer(guestCart)
    expect(snapshot.get()).toEqual(guestCart)

    for (const voucher of [
      { code: 'SAVE25', active: false, discount: '6000' },
      { code: 'SAVE25', active: 'true', discount: '6000' },
      { code: 'SAVE25', discount: '6000' },
      { code: '', active: true, discount: '6000' },
      { active: true, discount: '6000' },
      { code: 'SAVE25', active: true },
      undefined,
    ]) {
      answer({ ...voucherCart, voucher })
      expect(snapshot.get()).toEqual(guestCart)
    }
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
      voucher: { code: 'SAVE1', active: true, discount: '90999908090001' },
      total: '9008990900910090',
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
      voucher: { code: 'SAVE1', active: false, discount: '0' },
      total: '9099990718000182',
    }
    answer(live)
    expect(snapshot.get()).toEqual(live)
  })

  it('replaces the full snapshot as price and stock change while keeping the saved quantity', () => {
    const { snapshot, answer } = openCart()
    const unique = {
      ...line,
      lineId: 20,
      productId: 8,
      slug: 'morning-meadow',
      name: 'Morning meadow',
      kind: 'unique',
      quantity: 1,
      availableQuantity: 1,
      lineTotal: '12000',
    }
    const initial = {
      ...emptyCart,
      lines: [line, unique],
      itemsTotal: '36000',
      total: '36000',
    }
    answer(initial)
    expect(snapshot.get()).toEqual(initial)

    const restocked = {
      ...line,
      availableQuantity: 8,
      price: 13000,
      lineTotal: '65000',
    }
    const live = {
      ...emptyCart,
      lines: [restocked],
      itemsTotal: '65000',
      total: '65000',
    }
    answer(live)
    expect(snapshot.get()).toEqual(live)

    const unavailable = { ...restocked, availableQuantity: 0, lineTotal: '0' }
    const sold = { ...emptyCart, lines: [unavailable] }
    answer(sold)
    expect(snapshot.get()).toEqual(sold)
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
    answer(voucherCart)
    expect(snapshot.get()).toEqual(voucherCart)

    const accountCart = { ...emptyCart, userId: 9 }
    person.set(accountCart.userId)
    expect(snapshot.get()).toBeNull()
    answer({ ...voucherCart })
    expect(snapshot.get()).toBeNull()
    answer(accountCart)
    expect(snapshot.get()).toEqual(accountCart)
    answer({ ...guestCart })
    expect(snapshot.get()).toEqual(accountCart)

    person.set(null)
    expect(snapshot.get()).toBeNull()
    answer({ ...accountCart })
    expect(snapshot.get()).toBeNull()
    const nextGuest = { ...emptyCart }
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

    const nextGuest = { ...emptyCart }
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
