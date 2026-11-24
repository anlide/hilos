import {
  createSignal,
  type ActionHandle,
  type ReadonlySignal,
} from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Action name on the server (EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART). */
const PRODUCT_ADD_TO_CART = 'product_add_to_cart'

const pending = createSignal<ReadonlySet<number>>(new Set())

/** Additions still waiting for their own reply, even after leaving the page. */
export const pendingProductCartAdds: ReadonlySignal<ReadonlySet<number>> =
  pending

/**
 * Add a single unit of the product shown on the page.
 *
 * @param productId The product ID from the accepted page snapshot.
 * @returns The tracked request, or null if this product already has one.
 */
export function sendProductAddToCart(productId: number): ActionHandle | null {
  if (pending.get().has(productId)) return null

  const handle = actions.dispatch(PRODUCT_ADD_TO_CART, { productId })
  pending.set(new Set([...pending.get(), productId]))

  function release(): void {
    const remaining = new Set(pending.get())
    remaining.delete(productId)
    pending.set(remaining)
  }

  void handle.done.then(release, release)
  return handle
}
