// The warehouses admin actions: mutations dispatched as tracked actions over the
// connection. The added or changed row returns separately over the live
// warehouses table.
import type { ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import { type WarehouseDraft } from './warehouseDraft.js'

/** Action name on the server (EcommerceShopSignalConstants::WAREHOUSE_CREATE). */
export const WAREHOUSE_CREATE = 'warehouse_create'

/** Action name on the server (EcommerceShopSignalConstants::WAREHOUSE_UPDATE). */
export const WAREHOUSE_UPDATE = 'warehouse_update'

/**
 * Add a warehouse. The fields go raw: the server trims and checks them.
 *
 * @param draft The name and address the dialog collected.
 */
export function sendWarehouseCreate(draft: WarehouseDraft): ActionHandle {
  // Payload keys `name` and `address` correspond to server-side
  // WarehouseCreateActionDTO, read as ObjectWarehouse::name / ::address.
  return actions.dispatch(WAREHOUSE_CREATE, {
    name: draft.name,
    address: draft.address,
  })
}

/**
 * Change a warehouse's name and address. The fields go raw: the server trims
 * and checks them, and refuses when the row no longer holds what the dialog
 * expects.
 *
 * @param id The warehouse id.
 * @param draft The name and address the dialog holds.
 * @param saved The name and address the dialog expects the row to hold.
 */
export function sendWarehouseUpdate(
  id: number,
  draft: WarehouseDraft,
  saved: WarehouseDraft,
): ActionHandle {
  // Payload keys correspond to server-side WarehouseUpdateActionDTO: `id`,
  // `name` and `address` read as ObjectWarehouse::id / ::name / ::address,
  // `expectedName` and `expectedAddress` as the values the row must still hold.
  return actions.dispatch(WAREHOUSE_UPDATE, {
    id,
    name: draft.name,
    address: draft.address,
    expectedName: saved.name,
    expectedAddress: saved.address,
  })
}
