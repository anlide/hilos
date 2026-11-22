// The two fields of a warehouse dialog — a name and an address — shared by the
// Add and the edit windows; the window owns the draft and the form around them.
// Bootstrap classes only (styling-rules.md).
import { type WarehouseDraft } from './warehouseDraft.js'

/** What a warehouse dialog hands its fields. */
export interface WarehouseFormFieldsProps {
  /** The prefix of every id and data-id: one per dialog. */
  readonly idPrefix: 'admin-warehouses-add' | 'admin-warehouses-edit'
  /** The draft the dialog holds. */
  readonly draft: WarehouseDraft
  /** Lay the changed field over the draft. */
  readonly onChange: (patch: Partial<WarehouseDraft>) => void
}

/**
 * The name, with its hint, and the address of a warehouse dialog.
 *
 * @param props The dialog's prefix, draft and change handler.
 */
export function WarehouseFormFields({
  idPrefix,
  draft,
  onChange,
}: WarehouseFormFieldsProps) {
  const nameId = `${idPrefix}-name`
  const nameHintId = `${idPrefix}-name-hint`
  const addressId = `${idPrefix}-address`

  return (
    <>
      <div className="mb-3">
        <label className="form-label" htmlFor={nameId}>
          Name
        </label>
        <input
          id={nameId}
          type="text"
          className="form-control"
          value={draft.name}
          aria-describedby={nameHintId}
          data-id={nameId}
          data-autofocus
          onChange={(event) => onChange({ name: event.target.value })}
        />
        <div id={nameHintId} className="form-text">
          Buyers never see it; admins pick it when setting stock.
        </div>
      </div>
      <div>
        <label className="form-label" htmlFor={addressId}>
          Address
        </label>
        <input
          id={addressId}
          type="text"
          className="form-control"
          value={draft.address}
          data-id={addressId}
          onChange={(event) => onChange({ address: event.target.value })}
        />
      </div>
    </>
  )
}
