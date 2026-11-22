// Connection lifecycle for the Vouchers table. The pure row selectors live in
// adminVouchers.ts so they can also be read without application bootstrap.
import { TableViewportController, bindTableViewport } from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_VOUCHERS } from '../../pages/keys.js'
import {
  VOUCHERS_TABLE,
  resolveVoucherRow,
  vouchersFrame,
} from './adminVouchers.js'
import { type VoucherRow } from './types/tables/VoucherRow.js'

const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** The Vouchers table controller and its mount lifecycle. */
export interface VouchersTable {
  /** The server-windowed controller rendered by the view. */
  readonly controller: TableViewportController<VoucherRow>
  /** Bind the table on mount. */
  start(): void
  /** Permanently release the controller and its bindings on unmount. */
  dispose(): void
}

/** Create the server-windowed Vouchers table. */
export function createVouchersTable(): VouchersTable {
  const controller = new TableViewportController<VoucherRow>({
    resolve: (row) => resolveVoucherRow(row, fieldsOf),
    frame: vouchersFrame(),
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_VOUCHERS,
        VOUCHERS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      connection.sendTableRendered(
        PAGE_ADMIN_VOUCHERS,
        VOUCHERS_TABLE,
        rendered,
      ),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(PAGE_ADMIN_VOUCHERS, VOUCHERS_TABLE, rowKey),
    sendFacets: (facets) =>
      connection.sendTableFacets(PAGE_ADMIN_VOUCHERS, VOUCHERS_TABLE, facets),
  })
  let off: (() => void) | undefined
  let disposed = false

  return {
    controller,
    start() {
      if (disposed || off !== undefined) return
      off = bindTableViewport(
        connection,
        scopes,
        { page: PAGE_ADMIN_VOUCHERS, tableKey: VOUCHERS_TABLE },
        controller,
      )
    },
    dispose() {
      if (disposed) return
      disposed = true
      off?.()
      off = undefined
      controller.dispose()
    },
  }
}
