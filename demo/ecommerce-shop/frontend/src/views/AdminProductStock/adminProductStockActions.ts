import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Action name on the server (EcommerceShopSignalConstants::PRODUCT_STOCK_SET_UNITS). */
export const PRODUCT_STOCK_SET_UNITS = 'product_stock_set_units'

/**
 * Set stock through ProductStockSetUnitsActionDTO. The server checks the raw
 * units text and compares the expected count with what is stored.
 *
 * @param productId The product whose stock changes.
 * @param warehouseId The warehouse holding that stock.
 * @param units The units exactly as entered in the form.
 * @param expectedUnits The units in the live row accepted by the edit session.
 */
export function sendProductStockSetUnits(
  productId: number,
  warehouseId: number,
  units: string,
  expectedUnits: number,
): ActionHandle {
  return actions.dispatch(PRODUCT_STOCK_SET_UNITS, {
    productId,
    warehouseId,
    units,
    expectedUnits,
  })
}
