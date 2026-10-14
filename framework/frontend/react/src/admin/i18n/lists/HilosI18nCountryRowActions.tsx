import { useId } from 'react'
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryDeleteReason,
  type HilosI18nCountryRow,
} from '@hilos/core'

/** Each desktop/card projection owns its accessible live deletion reason. */
export function HilosI18nCountryRowActions({
  row,
  onDelete,
}: {
  row: HilosI18nCountryRow
  onDelete: () => void
}) {
  const reasonId = useId()
  return (
    <div className="d-flex flex-column align-items-start gap-1">
      <button
        type="button"
        className={`btn btn-sm ${row.canDelete ? 'btn-outline-danger' : HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone}`}
        aria-label={`Delete country ${hilosI18nCountryDeleteLabel(row)}`}
        aria-disabled={!row.canDelete || undefined}
        aria-describedby={!row.canDelete ? reasonId : undefined}
        data-id={`i18n-countries-delete-${row.code}`}
        onClick={() => {
          if (row.canDelete) onDelete()
        }}
      >
        <i className="bi bi-trash" aria-hidden="true" />
      </button>
      {!row.canDelete && (
        <span
          id={reasonId}
          className="small text-body-secondary"
          data-id={`i18n-countries-delete-reason-${row.code}`}
        >
          {hilosI18nCountryDeleteReason(row)}
        </span>
      )}
    </div>
  )
}
