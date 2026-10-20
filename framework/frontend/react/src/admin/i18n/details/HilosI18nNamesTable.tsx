import type { ReactNode } from 'react'
import {
  HilosI18nNameRowKey,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
import { type TableViewportController } from '@hilos/core/base'
import { HilosViewportTable } from '../../../HilosViewportTable.js'

/** The shared names view; each subject page supplies only its action controls. */
export interface HilosI18nNamesTableProps {
  controller: TableViewportController<HilosI18nNameRow>
  nameActions?: (row: HilosI18nNameRow) => ReactNode
  correctionActions?: (
    row: HilosI18nNameRow,
    correction: HilosI18nNameRow['corrections'][number],
  ) => ReactNode
}

/** A plate distinguishes absence from every saved string, including an empty one. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** One table for language and country names, with the controller's locale panels. */
export function HilosI18nNamesTable({
  controller,
  nameActions,
  correctionActions,
}: HilosI18nNamesTableProps) {
  return (
    <HilosViewportTable
      controller={controller}
      cells={{
        [HilosI18nNameRowKey.code]: (row) => (
          <>
            <span className="text-uppercase">{row.code}</span>
            <span className="text-body-secondary ms-1">{row.nativeName}</span>
          </>
        ),
        [HilosI18nNameRowKey.name]: (row) =>
          row.name === null ? (
            <span className={PLATE} data-id={`i18n-names-none-${row.code}`}>
              <i className="bi bi-dash-circle me-1" aria-hidden="true" />
              No name
            </span>
          ) : (
            <span className="fw-medium">{row.name}</span>
          ),
        actions: (row) => nameActions?.(row),
      }}
      details={{
        [HilosI18nNameRowKey.corrections]: (row) => (
          <ul className="list-unstyled mb-0">
            {row.corrections.map((correction) => (
              <li
                key={correction.localeId}
                className="d-flex flex-wrap align-items-center gap-2 py-1"
                data-id={`i18n-names-correction-${correction.localeCode}`}
              >
                <i
                  className="bi bi-arrow-return-right text-body-secondary"
                  aria-hidden="true"
                />
                <span>{correction.localeCode}</span>
                <span className="text-body-secondary">
                  {correction.countryName ??
                    correction.countryCode.toUpperCase()}
                </span>
                {correction.name === null ? (
                  <span
                    className={PLATE}
                    data-id={`i18n-names-inherit-${correction.localeCode}`}
                  >
                    <i
                      className="bi bi-arrow-return-right me-1"
                      aria-hidden="true"
                    />
                    as in “{row.nativeName}”
                  </span>
                ) : (
                  <span className="fw-medium">{correction.name}</span>
                )}
                {correctionActions?.(row, correction)}
              </li>
            ))}
          </ul>
        ),
      }}
    />
  )
}
