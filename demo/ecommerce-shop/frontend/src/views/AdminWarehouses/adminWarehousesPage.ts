// The warehouses admin page selectors: the page-scoped warehouses table rows fed
// into a server-windowed TableViewportController, each raw row resolved into its
// WarehouseRow view-model. The backend warehouses table carries the whole row,
// counts included, in the `warehouse` slot. The slot bears an `id`, so binding
// the window files it in the page scope as the `warehouse` entity and leaves a
// reference in the row: the row is read through that reference. The view reads
// the controller, never a raw store.
import {
  HILOS_TABLE_ACTIONS_KEY,
  TableViewportController,
  bindTableViewport,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_WAREHOUSES } from '../../pages/keys.js'
import { type WarehouseRow } from './types/tables/WarehouseRow.js'

// Wire keys: the warehouses table (EcommerceShopTableContext::warehouses) and its
// one row slot (WarehousesTable::ROW_SLOT), a WarehouseTableRow record that is
// also the slot's entity type in the page scope.
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

const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** Read a record field as a string, or the empty string when it is not one. */
function readString(
  record: Readonly<Record<string, unknown>> | undefined,
  field: string,
): string {
  const value = record?.[field]

  return typeof value === 'string' ? value : ''
}

/** Read a record field as a number, or zero when it is not one. */
function readNumber(
  record: Readonly<Record<string, unknown>> | undefined,
  field: string,
): number {
  const value = record?.[field]

  return typeof value === 'number' ? value : 0
}

/**
 * Resolve one raw warehouses-table row into its view-model: the fields of the
 * referenced warehouse entity, the id falling back to the row key.
 *
 * @param row The raw table row from the page-scoped table store.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function resolveWarehouseRow(
  row: TableRow,
  fieldsOf: EntityFieldsResolver,
): WarehouseRow {
  const warehouse = slotFields(row.slots[WAREHOUSE_SLOT], fieldsOf)
  const id = warehouse[WAREHOUSE_ID_FIELD]

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
// to the right, as numbers do. The last column holds the pencil that opens the
// Edit warehouse dialog; it stays on the narrow-screen card too, and it reads
// the name its label carries.
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
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: [WAREHOUSE_NAME_FIELD],
  },
]

/**
 * The warehouses table frame. No title: the section head names the table. The
 * main action opens the Add warehouse dialog, which the view owns.
 *
 * @param addWarehouse Open the Add warehouse dialog.
 */
function warehousesFrame(addWarehouse: () => void): HilosTableFrame {
  return {
    search: { placeholder: 'Search warehouses' },
    columns: WAREHOUSES_COLUMNS,
    mainAction: { label: 'Add warehouse', press: addWarehouse },
    empty: {
      title: 'No warehouses yet',
      hint: 'Add one before setting stock: stock always lives in a warehouse.',
    },
  }
}

/**
 * The server-windowed controller for the warehouses admin table: search, sort
 * and paging change the viewport descriptor sent over the connection, and the
 * backend replies a window plus live deltas scoped to the table's (page,
 * tableKey) address. Rows resolve through {@link resolveWarehouseRow}.
 *
 * @param addWarehouse Open the Add warehouse dialog — the table's main action.
 */
export function createWarehousesTable(
  addWarehouse: () => void,
): WarehousesTable {
  const controller = new TableViewportController<WarehouseRow>({
    resolve: (row) => resolveWarehouseRow(row, fieldsOf),
    frame: warehousesFrame(addWarehouse),
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
      // The slot's own key is its entity type: no entity-type override to declare.
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
