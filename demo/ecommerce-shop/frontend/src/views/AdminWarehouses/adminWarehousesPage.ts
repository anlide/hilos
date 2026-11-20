// The warehouses admin page selectors: the page-scoped warehouses table rows fed
// into a server-windowed TableViewportController, each raw row resolved into its
// WarehouseRow view-model. The backend warehouses table carries the whole row as
// an inline record in the `warehouse` slot — the counts are recounted from stock
// on the server, so the slot is a record, not an entity reference. The view
// reads the controller, never a raw store.
import {
  TableViewportController,
  bindTableViewport,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { PAGE_ADMIN_WAREHOUSES } from '../../pages/keys.js'
import { type WarehouseRow } from './types/tables/WarehouseRow.js'

// Wire keys: the warehouses table (EcommerceShopTableContext::warehouses) and its
// one row slot (WarehousesTable::ROW_SLOT), an inline WarehouseTableRow record.
const WAREHOUSES_TABLE = 'warehouses'
const WAREHOUSE_SLOT = 'warehouse'

/** Row payload key of the warehouse id. */
const WAREHOUSE_ID_FIELD = 'id'

/** Row payload key of the warehouse name (also the table's sort field). */
export const WAREHOUSE_NAME_FIELD = 'name'

/** Row payload key of the warehouse address. */
export const WAREHOUSE_ADDRESS_FIELD = 'address'

/** Row payload key of the number of different products the warehouse keeps. */
export const WAREHOUSE_PRODUCT_COUNT_FIELD = 'productCount'

/** Row payload key of the number of units the warehouse keeps in all. */
export const WAREHOUSE_UNIT_COUNT_FIELD = 'unitCount'

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Read a record field as a string, or the empty string when it is not one. */
function readString(
  record: Record<string, unknown> | undefined,
  field: string,
): string {
  const value = record?.[field]

  return typeof value === 'string' ? value : ''
}

/** Read a record field as a number, or zero when it is not one. */
function readNumber(
  record: Record<string, unknown> | undefined,
  field: string,
): number {
  const value = record?.[field]

  return typeof value === 'number' ? value : 0
}

/**
 * Resolve one raw warehouses-table row into its view-model: the fields of the
 * inline `warehouse` record, the id falling back to the row key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveWarehouseRow(row: TableRow): WarehouseRow {
  const warehouse = recordSlot(row.slots[WAREHOUSE_SLOT])
  const id = warehouse?.[WAREHOUSE_ID_FIELD]

  return {
    id: typeof id === 'number' ? id : Number(row.rowKey),
    name: readString(warehouse, WAREHOUSE_NAME_FIELD),
    address: readString(warehouse, WAREHOUSE_ADDRESS_FIELD),
    productCount: readNumber(warehouse, WAREHOUSE_PRODUCT_COUNT_FIELD),
    unitCount: readNumber(warehouse, WAREHOUSE_UNIT_COUNT_FIELD),
  }
}

/** The warehouses table handle the admin view drives: controller plus mount lifecycle. */
export interface WarehousesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<WarehouseRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

// Only the name sorts: it is the order field the server knows. The counts read
// to the right, as numbers do.
const WAREHOUSES_COLUMNS: HilosTableColumnOf<WarehouseRow>[] = [
  {
    key: WAREHOUSE_NAME_FIELD,
    label: 'Warehouse',
    sortable: true,
    card: 'title',
  },
  { key: WAREHOUSE_ADDRESS_FIELD, label: 'Address' },
  {
    key: WAREHOUSE_PRODUCT_COUNT_FIELD,
    label: 'Products',
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
  {
    key: WAREHOUSE_UNIT_COUNT_FIELD,
    label: 'Units',
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
]

// No title: the section head names the table. No main action yet: adding a
// warehouse is a leaf of its own.
const WAREHOUSES_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search warehouses' },
  columns: WAREHOUSES_COLUMNS,
  empty: {
    title: 'No warehouses yet',
    hint: 'Add one before setting stock: stock always lives in a warehouse.',
  },
}

/**
 * The server-windowed controller for the warehouses admin table: search, sort
 * and paging change the viewport descriptor sent over the connection, and the
 * backend replies a window plus live deltas scoped to the table's (page,
 * tableKey) address. Rows resolve through {@link resolveWarehouseRow}.
 */
export function createWarehousesTable(): WarehousesTable {
  const controller = new TableViewportController<WarehouseRow>({
    resolve: resolveWarehouseRow,
    frame: WAREHOUSES_FRAME,
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_WAREHOUSES,
        WAREHOUSES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      connection.sendTableRendered(
        PAGE_ADMIN_WAREHOUSES,
        WAREHOUSES_TABLE,
        rendered,
      ),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(
        PAGE_ADMIN_WAREHOUSES,
        WAREHOUSES_TABLE,
        rowKey,
      ),
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      // The slot is an inline record, not an entity: no entity types to normalize.
      teardown.push(
        bindTableViewport(
          connection,
          scopes,
          { page: PAGE_ADMIN_WAREHOUSES, tableKey: WAREHOUSES_TABLE },
          controller,
        ),
      )
    },
    dispose() {
      // A row still in focus on the server is let go — an empty focus key —
      // before the bindings are dropped.
      controller.releaseFocus()
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}
