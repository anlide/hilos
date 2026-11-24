import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

const CART_REMOVE_LINE = 'cart_remove_line'

/** The kind of cart that owns the saved line addressed by the action. */
export type CartOwnerKind = 'guest' | 'account'

/**
 * Remove a saved line through the Cart page.
 *
 * @param lineId The saved line's primary key.
 * @param ownerKind The kind of cart in the accepted snapshot.
 */
export function sendCartRemoveLine(
  lineId: number,
  ownerKind: CartOwnerKind,
): ActionHandle {
  return actions.dispatch(CART_REMOVE_LINE, { lineId, ownerKind })
}

/** Action name on the server (EcommerceShopSignalConstants::CART_STEP_QUANTITY). */
export const CART_STEP_QUANTITY = 'cart_step_quantity'

/** One unit added to or removed from a saved quantity. */
export type CartQuantityStep = -1 | 1

/**
 * Change a saved cart line by one unit.
 *
 * @param lineId The saved line's primary key from the Cart page snapshot.
 * @param ownerKind The cart that owns the line.
 * @param step The direction of the quantity change.
 */
export function sendCartStepQuantity(
  lineId: number,
  ownerKind: CartOwnerKind,
  step: CartQuantityStep,
): ActionHandle {
  return actions.dispatch(CART_STEP_QUANTITY, { lineId, ownerKind, step })
}
