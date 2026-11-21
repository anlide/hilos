// The shop's cart (HIL-2206): this demo's own answer to "what is in my cart" for
// a browser that may or may not carry an account. The cart is not a table the
// page draws — the backend sends every tab that shares one cart its whole
// contents on a project signal of its own, declared here as a schema so it
// parses at the boundary before anything reacts to it.
//
// The store keeps the last message whole (a newer one replaces the older) and
// answers with its lines only while the message is about the tab's present
// owner: the person it names is the person signed in here, and a guest's cart
// is the one with nobody on both sides. Otherwise it answers null — "not known
// yet" — so a late message of the owner a tab had before a sign-in or a
// sign-out is never shown.
//
// Kept pure of `./connection` — the connection arrives as a parameter — so that
// module can merge the schema without a cycle back through this one. It is a
// cross-cutting app-setup concern and therefore its own bootstrap file
// (docs/agents/frontend/bootstrap-structure.md).
//
// The signal name is byte-equal to the backend
// `EcommerceShopSignalConstants::CART_STATE` constant.
import {
  computedSignal,
  createSignal,
  z,
  type HilosConnection,
  type ProjectSignal,
  type ProjectSignalSchemas,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core/base'

import { currentUserId } from './session.js'

/** Signal `type` for the cart (PHP `EcommerceShopSignalConstants::CART_STATE`). */
export const CART_STATE_SIGNAL = 'cart_state'

/**
 * The payload: the person the cart belongs to (null for a guest) and the whole
 * cart, one entry per line in the order the lines were put there; an empty cart
 * is an empty list.
 */
export const cartStateSignalSchema = z.object({
  userId: z.number().int().nullable(),
  lines: z.array(
    z.object({
      productId: z.number().int(),
      quantity: z.number().int().positive(),
    }),
  ),
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
  /**
   * The lines of the cart this tab's present owner has, or null while the last
   * message is about somebody else or none has arrived.
   */
  readonly cart: ReadonlySignal<readonly CartLine[] | null>
  /**
   * Keep {@link Cart.cart} fed from the connection's cart signal.
   *
   * Called before `bootHilos` opens the socket, so the subscription is standing
   * when the first message arrives — a listener attached later could miss it.
   *
   * @param connection The project's connection singleton.
   * @returns Unsubscribe for the registered signal handler.
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
  const lastMessage = createSignal<CartState | null>(null)
  const cart = computedSignal<readonly CartLine[] | null>(() => {
    const message = lastMessage.get()
    return message !== null && message.userId === signedInUserId.get()
      ? message.lines
      : null
  })

  return {
    cart,
    bind: (connection) =>
      connection.on('projectSignal', (signal: ProjectSignal) => {
        if (signal.type !== CART_STATE_SIGNAL) {
          return
        }
        lastMessage.set(signal.data as CartState)
      }),
  }
}

/**
 * The cart of the owner this tab has now; null until a message about that owner
 * has arrived.
 *
 * SCAFFOLD: not read yet — the shop's screens (counter, cart page, "In cart")
 * are the readers and arrive in their own leaves (HIL-2208, HIL-2209, HIL-2211,
 * HIL-2212).
 */
export const { cart: currentCart, bind: bindCart } = createCart(currentUserId)
