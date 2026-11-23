// Vouchers under their shared administration section head. The SDK owns the
// search, the Active filter, paging and narrow-screen cards; this view supplies
// the cells. One heading comes from the section head, which also names the table.
import { useLayoutEffect, useState } from 'react'
import { HilosAdminPage, HilosViewportTable } from '@hilos/react/base'

import { PAGE_ADMIN_VOUCHERS } from '../../pages/keys.js'
import {
  VOUCHER_ACTIVE_FIELD,
  VOUCHER_CODE_FIELD,
  VOUCHER_DISCOUNT_PERCENT_FIELD,
} from './adminVouchers.js'
import { createVouchersTable, type VouchersTable } from './adminVouchersPage.js'
import { VoucherAddModal } from './VoucherAddModal.js'

/** The Vouchers administration page with its live table and Add dialog. */
export default function AdminVouchers() {
  const [vouchers, setVouchers] = useState<VouchersTable | null>(null)
  const [adding, setAdding] = useState(false)

  useLayoutEffect(() => {
    // Disposal is permanent; each setup, including StrictMode replay, owns one.
    const table = createVouchersTable(() => setAdding(true))
    table.start()
    setVouchers(table)
    return () => table.dispose()
  }, [])

  return (
    <HilosAdminPage page={PAGE_ADMIN_VOUCHERS} headKind="section">
      {vouchers && (
        <HilosViewportTable
          controller={vouchers.controller}
          dataId="admin-vouchers-table"
          cells={{
            [VOUCHER_CODE_FIELD]: (row) => (
              <span className="font-monospace fw-semibold">{row.code}</span>
            ),
            [VOUCHER_DISCOUNT_PERCENT_FIELD]: (row) =>
              `${row.discountPercent}%`,
            [VOUCHER_ACTIVE_FIELD]: (row) => (row.active ? 'Active' : 'Off'),
          }}
        />
      )}
      {adding && <VoucherAddModal onClose={() => setAdding(false)} />}
    </HilosAdminPage>
  )
}
