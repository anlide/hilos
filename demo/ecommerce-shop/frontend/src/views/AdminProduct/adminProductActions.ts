import { type ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** EcommerceShopSignalConstants::PRODUCT_UPDATE. */
export const PRODUCT_UPDATE = 'product_update'

/** ProductUpdateActionDTO, with a price as entered and null to keep the picture. */
export interface ProductUpdatePayload {
  readonly productId: number
  readonly expectedVersion: string
  readonly name: string
  readonly categoryId: number
  readonly price: string
  readonly description: string
  readonly pictureFileId: number | null
}

/**
 * Send the tracked update of one product's catalog fields.
 *
 * @param payload The catalog draft and the version it was checked against.
 */
export function sendProductUpdate(payload: ProductUpdatePayload): ActionHandle {
  return actions.dispatch(PRODUCT_UPDATE, { ...payload })
}

/** EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE. */
export const PRODUCT_WAREHOUSE_PLACE = 'product_warehouse_place'

/** ProductWarehousePlaceActionDTO, with null for a previously unplaced piece. */
interface ProductWarehousePlacePayload {
  readonly productId: number
  readonly warehouseId: number
  readonly expectedWarehouseId: number | null
}

/**
 * Send the tracked placement or move of a unique piece.
 *
 * @param payload The target warehouse and the live warehouse accepted by the editor.
 */
export function sendProductWarehousePlace(
  payload: ProductWarehousePlacePayload,
): ActionHandle {
  return actions.dispatch(PRODUCT_WAREHOUSE_PLACE, { ...payload })
}
