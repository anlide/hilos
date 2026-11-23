// Vouchers under their shared administration section head. The SDK owns the
// search, the Active filter, paging and narrow-screen cards; this view supplies
// the cells. One heading comes from the section head, which also names the table.
import { useLayoutEffect, useState } from 'react'
import { HILOS_TABLE_ACTIONS_KEY } from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosViewportTable,
  useSignal,
} from '@hilos/react/base'

import { PAGE_ADMIN_VOUCHERS } from '../../pages/keys.js'
import {
  VOUCHER_ACTIVE_FIELD,
  VOUCHER_CODE_FIELD,
  VOUCHER_DISCOUNT_PERCENT_FIELD,
} from './adminVouchers.js'
import { createVouchersTable, type VouchersTable } from './adminVouchersPage.js'
import { type VoucherRow } from './types/tables/VoucherRow.js'
import { VoucherAddModal } from './VoucherAddModal.js'
import { createVoucherEdit, type VoucherEdit } from './voucherEdit.js'
import { VoucherEditModal } from './VoucherEditModal.js'

interface VouchersWorkspace {
  readonly table: VouchersTable
  readonly edit: VoucherEdit
}

/**
 * Draw the table and mount a fresh edit dialog for each successful opening.
 *
 * @param props The table and edit session owned by the page's current setup.
 */
function VouchersTableView({ table, edit }: VouchersWorkspace) {
  const editing = useSignal(edit.opened)
  const [editKey, setEditKey] = useState(0)

  function openEdit(row: VoucherRow): void {
    if (edit.open(String(row.id))) {
      setEditKey((key) => key + 1)
    }
  }

  return (
    <>
      <HilosViewportTable
        controller={table.controller}
        dataId="admin-vouchers-table"
        cells={{
          [VOUCHER_CODE_FIELD]: (row) => (
            <span className="font-monospace fw-semibold">{row.code}</span>
          ),
          [VOUCHER_DISCOUNT_PERCENT_FIELD]: (row) => `${row.discountPercent}%`,
          [VOUCHER_ACTIVE_FIELD]: (row) => (row.active ? 'Active' : 'Off'),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              aria-label={`Edit · ${row.code}`}
              data-id="admin-vouchers-edit-open"
              onClick={() => openEdit(row)}
            >
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          ),
        }}
      />
      {editing && <VoucherEditModal key={editKey} edit={edit} />}
    </>
  )
}

/** The Vouchers administration page with its live table and Add and Edit dialogs. */
export default function AdminVouchers() {
  const [workspace, setWorkspace] = useState<VouchersWorkspace | null>(null)
  const [adding, setAdding] = useState(false)

  useLayoutEffect(() => {
    // Disposal is permanent, so each setup owns both instances.
    const table = createVouchersTable(() => setAdding(true))
    const edit = createVoucherEdit(table.controller)
    table.start()
    edit.start()
    setWorkspace({ table, edit })
    return () => {
      edit.dispose()
      table.dispose()
    }
  }, [])

  return (
    <HilosAdminPage page={PAGE_ADMIN_VOUCHERS} headKind="section">
      {workspace && <VouchersTableView {...workspace} />}
      {adding && <VoucherAddModal onClose={() => setAdding(false)} />}
    </HilosAdminPage>
  )
}
