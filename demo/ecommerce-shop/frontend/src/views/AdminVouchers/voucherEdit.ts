import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
  type TableViewportController,
} from '@hilos/core/base'

import { sendVoucherUpdate } from './adminVouchersActions.js'
import { type VoucherRow } from './types/tables/VoucherRow.js'

/** The voucher form holds text as entered and the active switch as a boolean. */
export interface VoucherDraft {
  readonly code: string
  readonly discountPercent: string
  readonly active: boolean
}

/** One Edit voucher session over the table's focused row. */
export type VoucherEdit = HilosTrackedRowEdit<VoucherRow, VoucherDraft>

const FIELD_LABELS: Readonly<Record<keyof VoucherDraft, string>> = {
  code: 'Code',
  discountPercent: 'Discount, %',
  active: 'Active',
}

/**
 * Whether the required text fields have been filled.
 *
 * @param draft The draft as shown in the form.
 */
export function isVoucherDraftComplete(draft: VoucherDraft): boolean {
  return draft.code !== '' && draft.discountPercent !== ''
}

/**
 * Compare the values VoucherFields would save. Match PHP trim's whitespace
 * and keep refused input distinct from any saved value.
 *
 * @param form The raw draft displayed and sent by the editor.
 */
function comparableVoucherDraft(form: VoucherDraft): VoucherDraft {
  const code = /^[ \t\r\n\v\0]*([A-Za-z0-9]{1,32})[ \t\r\n\v\0]*$/.exec(
    form.code,
  )
  const discount = /^[ \t\r\n\v\0]*([0-9]+)[ \t\r\n\v\0]*$/.exec(
    form.discountPercent,
  )
  const percent = Number(discount?.[1])

  return {
    code: code ? code[1].toUpperCase() : form.code,
    discountPercent:
      percent >= 1 && percent <= 100 ? String(percent) : form.discountPercent,
    active: form.active,
  }
}

/**
 * Create an edit over the vouchers table, using its focus for the live values.
 *
 * @param controller The table that holds the voucher being edited.
 */
export function createVoucherEdit(
  controller: TableViewportController<VoucherRow>,
): VoucherEdit {
  const source = hilosTableRowEditSource(controller, (row) => String(row.id))
  const edit = createHilosRowEdit<VoucherRow, VoucherDraft>(source, {
    initial: { code: '', discountPercent: '', active: true },
    fields: (row) => ({
      code: row.code,
      discountPercent: String(row.discountPercent),
      active: row.active,
    }),
    draft: comparableVoucherDraft,
    valid: isVoucherDraftComplete,
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map((field) => {
            const incoming = state.fields[field].incoming
            const value =
              typeof incoming === 'boolean'
                ? incoming
                  ? 'Active'
                  : 'Off'
                : incoming
            return `${FIELD_LABELS[field]} changed elsewhere to "${value}".`
          })
          .join(' '),
    },
  })

  return {
    ...edit,
    keepMine: () => {
      if (!edit.saving.get()) edit.keepMine()
    },
    takeTheirs: () => {
      if (!edit.saving.get()) edit.takeTheirs()
    },
    save: (run) => {
      const draft = edit.form.get()
      return edit.save((_, row) => {
        const live = source.live.get()
        if (live === undefined) return Promise.resolve(false)

        return run(sendVoucherUpdate(row.id, draft, live))
      })
    },
  }
}
