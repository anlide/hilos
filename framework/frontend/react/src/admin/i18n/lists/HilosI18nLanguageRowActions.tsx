import { useId } from 'react'
import {
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosI18nLanguageDelete,
  type HilosI18nLanguageRow,
} from '@hilos/core'

/** Each table/card projection owns a distinct description of its delete verdict. */
export function HilosI18nLanguageRowActions({
  row,
  onEdit,
  onDelete,
}: {
  row: HilosI18nLanguageRow
  onEdit: () => void
  onDelete: () => void
}) {
  const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  const verdict = hilosI18nLanguageDelete(row)
  const reasonId = useId()
  return (
    <span className="d-inline-flex flex-column align-items-end text-end">
      <span className="d-flex gap-1 justify-content-end">
        {row.enabled ? (
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            title={copy.open.view}
            aria-label={copy.open.view}
            data-id={`i18n-languages-view-${row.code}`}
            onClick={onEdit}
          >
            <i className="bi bi-eye" aria-hidden="true" />
          </button>
        ) : (
          <button
            type="button"
            className="btn btn-sm btn-outline-primary"
            title={copy.open.edit}
            aria-label={copy.open.edit}
            data-id={`i18n-languages-edit-${row.code}`}
            onClick={onEdit}
          >
            <i className="bi bi-pencil" aria-hidden="true" />
          </button>
        )}
        <button
          type="button"
          className={`btn btn-sm ${verdict.disabled ? 'btn-outline-secondary' : 'btn-outline-danger'}`}
          disabled={verdict.disabled}
          aria-label={HILOS_I18N_LANGUAGE_DELETE_COPY.rowLabel(row.nativeName)}
          aria-describedby={verdict.reason ? reasonId : undefined}
          data-id={`i18n-languages-delete-${row.code}`}
          onClick={onDelete}
        >
          <i className="bi bi-trash" aria-hidden="true" />
        </button>
      </span>
      {verdict.reason && (
        <span
          id={reasonId}
          className="small text-body-secondary text-wrap text-end mt-1 mb-0"
          data-id={`i18n-languages-delete-reason-${row.code}`}
        >
          {verdict.reason}
        </span>
      )}
    </span>
  )
}
