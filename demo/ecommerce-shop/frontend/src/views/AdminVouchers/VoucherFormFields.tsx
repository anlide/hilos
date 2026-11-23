import { type VoucherDraft } from './voucherEdit.js'

const FIELD_IDS = {
  add: {
    code: 'admin-vouchers-add-code',
    discountPercent: 'admin-vouchers-add-discount-percent',
    active: 'admin-vouchers-add-active',
  },
  edit: {
    code: 'admin-vouchers-edit-code',
    discountPercent: 'admin-vouchers-edit-discount-percent',
    active: 'admin-vouchers-edit-active',
  },
} as const

interface VoucherFormFieldsProps {
  readonly mode: keyof typeof FIELD_IDS
  readonly draft: VoucherDraft
  readonly onChange: (patch: Partial<VoucherDraft>) => void
}

/**
 * The shared fields and hints for Add and Edit voucher.
 *
 * @param props The dialog mode, draft and field change handler.
 */
export function VoucherFormFields({
  mode,
  draft,
  onChange,
}: VoucherFormFieldsProps) {
  const ids = FIELD_IDS[mode]
  const codeHint = `${ids.code}-hint`
  const activeHint = `${ids.active}-hint`

  return (
    <>
      <div className="mb-3">
        <label className="form-label" htmlFor={ids.code}>
          Code
        </label>
        <input
          id={ids.code}
          type="text"
          className="form-control"
          value={draft.code}
          aria-describedby={codeHint}
          data-id={ids.code}
          data-autofocus
          onChange={(event) => onChange({ code: event.target.value })}
        />
        <div id={codeHint} className="form-text">
          Buyers type it in the cart. Letters and digits; case does not matter.
        </div>
      </div>
      <div className="mb-3">
        <label className="form-label" htmlFor={ids.discountPercent}>
          Discount, %
        </label>
        <input
          id={ids.discountPercent}
          type="text"
          inputMode="numeric"
          className="form-control"
          value={draft.discountPercent}
          data-id={ids.discountPercent}
          onChange={(event) =>
            onChange({ discountPercent: event.target.value })
          }
        />
      </div>
      <div className="form-check form-switch">
        <input
          id={ids.active}
          type="checkbox"
          role="switch"
          className="form-check-input"
          checked={draft.active}
          aria-describedby={activeHint}
          data-id={ids.active}
          onChange={(event) => onChange({ active: event.target.checked })}
        />
        <label className="form-check-label" htmlFor={ids.active}>
          Active
        </label>
        <div id={activeHint} className="form-text">
          A switched-off code is refused in the cart.
        </div>
      </div>
    </>
  )
}
