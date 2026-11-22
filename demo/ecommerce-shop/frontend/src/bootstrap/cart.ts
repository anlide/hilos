// The shop's cart (HIL-2206): this demo's own answer to "what is in my cart" for
// a browser that may or may not carry an account. The cart is not a table the
// page draws — the backend sends every tab that shares one cart its whole
// contents on a project signal of its own, declared here as a schema so it
// parses at the boundary before anything reacts to it.
//
// The store accepts snapshots for the tab's current owner and clears them
// when the owner changes. For guests, the socket's group membership identifies
// the session. A handshake response, a rotation or a socket drop clears the
// guest snapshot until the current session's group receives its cart.
//
// Kept pure of `./connection` — the connection arrives as a parameter — so that
// module can merge the schema without a cycle back through this one. It is a
// cross-cutting app-setup concern and therefore its own bootstrap file
// (docs/agents/frontend/bootstrap-structure.md).
//
// The signal name is byte-equal to the backend
// `EcommerceShopSignalConstants::CART_STATE` constant.
import {
  SIGNAL_HANDSHAKE_RESPONSE,
  computedSignal,
  createSignal,
  subscribeSignal,
  z,
  type HilosConnection,
  type ProjectSignalSchemas,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core/base'

import { currentUserId } from './session.js'

/** Signal `type` for the cart (PHP `EcommerceShopSignalConstants::CART_STATE`). */
export const CART_STATE_SIGNAL = 'cart_state'

/**
 * The payload: the person the cart belongs to (null for a guest) and the whole
 * cart, with its lines in insertion order and its saved voucher ID or null.
 */
export const cartStateSignalSchema = z.object({
  userId: z.number().int().nullable(),
  lines: z.array(
    z.object({
      productId: z.number().int(),
      quantity: z.number().int().positive(),
    }),
  ),
  voucherId: z.number().int().positive().nullable(),
})

/** The parsed payload of one cart message. */
export type CartState = z.infer<typeof cartStateSignalSchema>

/** One line of the cart: a product and how many of it. */
export type CartLine = CartState['lines'][number]

/**
 * The project signal schema `createHilosConnection` merges so the cart parses at
 * the boundary.
 */
export const CART_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [CART_STATE_SIGNAL]: cartStateSignalSchema,
}

/** The cart store of one tab: what it knows and how to feed it. */
export interface Cart {
  /** The current owner's snapshot, or null until the store knows that cart. */
  readonly state: ReadonlySignal<CartState | null>
  /**
   * The lines of the current owner's snapshot, or null while it is unknown.
   */
  readonly cart: ReadonlySignal<readonly CartLine[] | null>
  /**
   * Keep {@link Cart.state} fed from the connection's cart signal.
   *
   * Called before `bootHilos` opens the socket, so the subscription is standing
   * when the first message arrives — a listener attached later could miss it.
   *
   * @param connection The project's connection singleton.
   * @returns Unsubscribe for the connection listeners.
   */
  bind(connection: HilosConnection): Unsubscribe
}

/**
 * Create a cart store over the signed-in person of the tab.
 *
 * @param signedInUserId The person signed in in this tab; null for a guest.
 */
export function createCart(
  signedInUserId: ReadonlySignal<number | null>,
): Cart {
  const snapshot = createSignal<CartState | null>(null)
  subscribeSignal(signedInUserId, () => snapshot.set(null))

  const state = computedSignal<CartState | null>(() => {
    const current = snapshot.get()
    return current !== null && current.userId === signedInUserId.get()
      ? current
      : null
  })
  const cart = computedSignal<readonly CartLine[] | null>(
    () => state.get()?.lines ?? null,
  )

  const clearGuestSnapshot = (): void => {
    if (signedInUserId.get() === null) {
      snapshot.set(null)
    }
  }

  return {
    state,
    cart,
    bind: (connection) => {
      const offProjectSignal = connection.on('projectSignal', (signal) => {
        if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
          // The response carries no guest session ID. The shop requests a
          // fresh snapshot after each guest response.
          clearGuestSnapshot()
          return
        }
        if (signal.type !== CART_STATE_SIGNAL) {
          return
        }
        const next = signal.data as CartState
        if (next.userId === signedInUserId.get()) {
          snapshot.set(next)
        }
      })
      const offRotation = connection.on('sessionRotate', clearGuestSnapshot)
      const offConnectionState = connection.on('state', (connectionState) => {
        if (connectionState !== 'connected') {
          clearGuestSnapshot()
        }
      })
      return () => {
        offProjectSignal()
        offRotation()
        offConnectionState()
      }
    },
  }
}

/**
 * The current owner's snapshot and its line projection are null while that
 * cart is unknown. In a known snapshot, a null voucherId means no voucher.
 *
 * SCAFFOLD: the voucher snapshot is for the cart's voucher UI (HIL-2231).
 * The line projection is for the counter, cart page and "In cart" views
 * (HIL-2208, HIL-2209, HIL-2211, HIL-2212).
 */
export const {
  state: currentCartState,
  cart: currentCart,
  bind: bindCart,
} = createCart(currentUserId)
