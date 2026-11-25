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

/** Action name on the server (EcommerceShopSignalConstants::CART_SET_QUANTITY). */
export const CART_SET_QUANTITY = 'cart_set_quantity'

/** Action name on the server (EcommerceShopSignalConstants::CART_APPLY_VOUCHER). */
export const CART_APPLY_VOUCHER = 'cart_apply_voucher'

/** Action name on the server (EcommerceShopSignalConstants::CART_REMOVE_VOUCHER). */
export const CART_REMOVE_VOUCHER = 'cart_remove_voucher'

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

/**
 * Set the quantity of a saved cart line.
 *
 * @param lineId The saved line's primary key from the Cart page snapshot.
 * @param ownerKind The cart that owns the line.
 * @param quantity The quantity exactly as entered.
 * @param expectedQuantity The accepted saved quantity the edit is based on.
 */
export function sendCartSetQuantity(
  lineId: number,
  ownerKind: CartOwnerKind,
  quantity: string,
  expectedQuantity: number,
): ActionHandle {
  return actions.dispatch(CART_SET_QUANTITY, {
    lineId,
    ownerKind,
    quantity,
    expectedQuantity,
  })
}

/**
 * Apply a voucher to the cart selected by the active Cart subscription.
 *
 * @param code The voucher code exactly as entered.
 * @param ownerKind The kind of cart in the accepted snapshot.
 */
export function sendCartApplyVoucher(
  code: string,
  ownerKind: CartOwnerKind,
): ActionHandle {
  return actions.dispatch(CART_APPLY_VOUCHER, { code, ownerKind })
}

/**
 * Remove the displayed voucher through the active Cart subscription.
 *
 * @param expectedVoucherId The voucher's primary key in the accepted snapshot.
 * @param ownerKind The kind of cart in that snapshot.
 * @param pageSubscriptionProof The proof accompanying that snapshot.
 */
export function sendCartRemoveVoucher(
  expectedVoucherId: number,
  ownerKind: CartOwnerKind,
  pageSubscriptionProof: string,
): ActionHandle {
  return actions.dispatch(
    CART_REMOVE_VOUCHER,
    { expectedVoucherId, ownerKind },
    { pageSubscriptionProof },
  )
}
