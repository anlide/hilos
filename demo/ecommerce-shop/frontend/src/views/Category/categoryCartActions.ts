import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Action name on the server (EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART). */
const CATEGORY_ADD_TO_CART = 'category_add_to_cart'

/**
 * Add one unit of a product from the category window.
 *
 * @param productId The product id from the current row.
 */
export function sendCategoryAddToCart(productId: number): ActionHandle {
  return actions.dispatch(CATEGORY_ADD_TO_CART, { productId })
}
