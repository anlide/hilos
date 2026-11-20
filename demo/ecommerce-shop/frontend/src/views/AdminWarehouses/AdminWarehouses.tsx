// The shop's Warehouses admin page: every warehouse with the number of products
// and units it keeps, the counts redrawn live as stock changes. The table sits
// under the section head of its own dashboard group. Read only for now.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import { HilosAdminPage, HilosViewportTable } from '@hilos/react/base'

import { PAGE_ADMIN_WAREHOUSES } from '../../pages/keys.js'
import {
  WAREHOUSE_ADDRESS_FIELD,
  WAREHOUSE_NAME_FIELD,
  WAREHOUSE_PRODUCT_COUNT_FIELD,
  WAREHOUSE_UNIT_COUNT_FIELD,
  createWarehousesTable,
} from './adminWarehousesPage.js'

/** The warehouses admin page, read only. */
export default function AdminWarehouses() {
  const warehouses = useMemo(() => createWarehousesTable(), [])

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
    </HilosAdminPage>
  )
}
