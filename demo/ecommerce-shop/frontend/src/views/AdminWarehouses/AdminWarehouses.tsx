// The shop's Warehouses admin page: every warehouse with the number of products
// and units it keeps, the counts redrawn live as stock changes. The table sits
// under the section head of its own dashboard group; its main action opens the
// Add warehouse dialog, mounted inside the admin page so the view mode reaches
// its submit. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import { HilosAdminPage, HilosViewportTable } from '@hilos/react/base'

import { PAGE_ADMIN_WAREHOUSES } from '../../pages/keys.js'
import {
  WAREHOUSE_ADDRESS_FIELD,
  WAREHOUSE_NAME_FIELD,
  WAREHOUSE_PRODUCT_COUNT_FIELD,
  WAREHOUSE_UNIT_COUNT_FIELD,
  createWarehousesTable,
} from './adminWarehousesPage.js'
import { WarehouseAddModal } from './WarehouseAddModal.js'

/** Whether the Add warehouse dialog is open, and the key of its latest opening. */
interface AddOpening {
  readonly open: boolean
  readonly key: number
}

/** The warehouses admin page: the live table and its Add warehouse dialog. */
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

  // Bind the table on mount, consuming its initial page window, and release its
  // listeners on unmount.
  useEffect(() => {
    warehouses.start()

    return () => warehouses.dispose()
  }, [warehouses])

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
        }}
      />
      {adding.open ? (
        <WarehouseAddModal
          key={adding.key}
          onClose={() => setAdding((opening) => ({ ...opening, open: false }))}
        />
      ) : null}
    </HilosAdminPage>
  )
}
