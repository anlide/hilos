import { useId } from 'react'
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryRowDeleteReason,
  type HilosI18nCountryRow,
} from '@hilos/core/admin/i18n'

import { HilosActionReason } from '../../../HilosActionReason.js'

/** Each desktop/card projection owns its accessible live deletion reason. */
export function HilosI18nCountryRowActions({
  row,
  onDelete,
}: {
  row: HilosI18nCountryRow
  onDelete: () => void
}) {
  const reasonId = useId()
  const reason = row.canDelete ? null : hilosI18nCountryRowDeleteReason(row)
  return (
    <>
      <HilosActionReason reason={reason} />
      <button
        type="button"
        className={`btn btn-sm ${row.canDelete ? 'btn-outline-danger' : HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone}`}
        aria-label={`Delete country ${hilosI18nCountryDeleteLabel(row)}`}
        disabled={!row.canDelete}
        aria-describedby={reason ? reasonId : undefined}
        data-id={`i18n-countries-delete-${row.code}`}
        onClick={() => {
          if (row.canDelete) onDelete()
        }}
      >
        <i className="bi bi-trash" aria-hidden="true" />
      </button>
      {reason && (
        <span
          id={reasonId}
          className="visually-hidden"
          data-id={`i18n-countries-delete-reason-${row.code}`}
        >
          {reason}
        </span>
      )}
    </>
  )
}
