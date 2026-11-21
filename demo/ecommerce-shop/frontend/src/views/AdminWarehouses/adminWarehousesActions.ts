// The warehouses admin actions: mutations dispatched as tracked actions over the
// connection. The added row returns separately over the live warehouses table.
import type { ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import { type WarehouseAddDraft } from './warehouseAddDraft.js'

/** Action name on the server (EcommerceShopSignalConstants::WAREHOUSE_CREATE). */
export const WAREHOUSE_CREATE = 'warehouse_create'

/**
 * Add a warehouse. The fields go raw: the server trims and checks them.
 *
 * @param draft The name and address the dialog collected.
 */
export function sendWarehouseCreate(draft: WarehouseAddDraft): ActionHandle {
  // Payload keys `name` and `address` correspond to server-side
  // WarehouseCreateActionDTO, read as ObjectWarehouse::name / ::address.
  return actions.dispatch(WAREHOUSE_CREATE, {
    name: draft.name,
    address: draft.address,
  })
}
