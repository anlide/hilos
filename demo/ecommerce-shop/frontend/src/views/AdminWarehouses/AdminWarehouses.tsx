// The shop's Warehouses admin page: every warehouse with the number of products
// and units it keeps, the counts redrawn live as stock changes. The table sits
// under the section head of its own dashboard group; its main action opens the
// Add warehouse dialog, the pencil in a row opens the Edit warehouse dialog,
// and the trash can opens the Delete warehouse dialog on that row; all are
// mounted inside the admin page so the view mode reaches their submit.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  hilosTableRowEditSource,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosViewportTable,
  useSignal,
} from '@hilos/react/base'

import { PAGE_ADMIN_WAREHOUSES } from '../../pages/keys.js'
import {
  WAREHOUSE_ADDRESS_FIELD,
  WAREHOUSE_NAME_FIELD,
  WAREHOUSE_PRODUCT_COUNT_FIELD,
  WAREHOUSE_UNIT_COUNT_FIELD,
  createWarehousesTable,
} from './adminWarehousesPage.js'
import { type WarehouseRow } from './types/tables/WarehouseRow.js'
import { WarehouseAddModal } from './WarehouseAddModal.js'
import { WarehouseDeleteModal } from './WarehouseDeleteModal.js'
import { createWarehouseEdit } from './warehouseEdit.js'
import { WarehouseEditModal } from './WarehouseEditModal.js'
import { WarehouseRowActions } from './WarehouseRowActions.js'

/** Whether the Add warehouse dialog is open, and the key of its latest opening. */
interface AddOpening {
  readonly open: boolean
  readonly key: number
}

/** The warehouses admin page: the live table, its Add, Edit and Delete warehouse dialogs. */
export default function AdminWarehouses() {
  const [adding, setAdding] = useState<AddOpening>({ open: false, key: 0 })
  // The setter is stable, so the table is made once. Every opening takes a new
  // key: a new dialog window, with no refusal left from the one before.
  const warehouses = useMemo(
    () =>
      createWarehousesTable(() =>
        setAdding((opening) => ({ open: true, key: opening.key + 1 })),
      ),
    [],
  )
  const edit = useMemo(
    () => createWarehouseEdit(warehouses.controller),
    [warehouses],
  )
  const editing = useSignal(edit.opened)
  // Every opening of the edit takes a new key too: one window per opening.
  const [editKey, setEditKey] = useState(0)

  const deleteSource = useMemo(
    () =>
      hilosTableRowEditSource(warehouses.controller, (row) => String(row.id)),
    [warehouses],
  )
  const [deleteKey, setDeleteKey] = useState(0)
  const [deleteInitialRow, setDeleteInitialRow] = useState<WarehouseRow | null>(
    null,
  )

  // Bind the table on mount, consuming its initial page window, and release its
  // listeners on unmount; the edit listens for the merge's steps over the same
  // span.
  useEffect(() => {
    warehouses.start()
    edit.start()

    return () => {
      deleteSource.close()
      edit.dispose()
      warehouses.dispose()
    }
  }, [warehouses, edit, deleteSource])

  // A row already gone opens no window.
  function openEdit(row: WarehouseRow): void {
    if (edit.open(String(row.id))) {
      setEditKey((key) => key + 1)
    }
  }

  function openDelete(row: WarehouseRow): void {
    const initial = deleteSource.open(String(row.id))
    if (initial !== null) {
      setDeleteInitialRow(initial)
      setDeleteKey((key) => key + 1)
    }
  }

  function closeDelete(): void {
    deleteSource.close()
    setDeleteInitialRow(null)
  }

  return (
    <HilosAdminPage page={PAGE_ADMIN_WAREHOUSES} headKind="section">
      <HilosViewportTable
        controller={warehouses.controller}
        dataId="admin-warehouses-table"
        cells={{
          [WAREHOUSE_NAME_FIELD]: (row) => (
            <span className="fw-medium">{row.name}</span>
          ),
          [WAREHOUSE_ADDRESS_FIELD]: (row) => (
            <span className="small">{row.address}</span>
          ),
          [WAREHOUSE_PRODUCT_COUNT_FIELD]: (row) => row.productCount,
          [WAREHOUSE_UNIT_COUNT_FIELD]: (row) => row.unitCount,
          // Only opens the dialog, so it stays live in the view mode, as Add
          // warehouse does.
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <WarehouseRowActions
              row={row}
              onEdit={() => openEdit(row)}
              onDelete={() => openDelete(row)}
            />
          ),
        }}
      />
      {adding.open ? (
        <WarehouseAddModal
          key={adding.key}
          onClose={() => setAdding((opening) => ({ ...opening, open: false }))}
        />
      ) : null}
      {editing ? <WarehouseEditModal key={editKey} edit={edit} /> : null}
      {deleteInitialRow ? (
        <WarehouseDeleteModal
          key={deleteKey}
          source={deleteSource}
          initialRow={deleteInitialRow}
          onClose={closeDelete}
        />
      ) : null}
    </HilosAdminPage>
  )
}
