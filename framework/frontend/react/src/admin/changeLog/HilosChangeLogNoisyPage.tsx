import { useId, useMemo } from 'react'
import {
  CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_NOISY_COLUMNS_TITLE,
  readHilosChangeLogNoisyColumns,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import type { HilosChangeLogContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosChangeLogNoisyPage}. */
export interface HilosChangeLogNoisyPageProps {
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}

/**
 * The noisy-column snapshot, unchanged for the legacy view.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosChangeLogNoisyPage({
  context,
}: HilosChangeLogNoisyPageProps) {
  const noisyHeadingId = useId()
  const noisyColumns = readHilosChangeLogNoisyColumns(
    useSignal(
      useMemo(
        () => context.scopes.pageDataSignal(CHANGE_LOG_NOISY_COLUMNS_SECTION),
        [context],
      ),
    ),
  )
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_NOISY}>
      <section className="mt-4" data-id="hilos-change-log-noisy-columns">
        <h2 id={noisyHeadingId} className="h5">
          {CHANGE_LOG_NOISY_COLUMNS_TITLE}
        </h2>
        <p className="small text-body-secondary">
          {CHANGE_LOG_NOISY_COLUMNS_NOTE}
        </p>
        {noisyColumns === null ? (
          <p className="text-body-secondary">—</p>
        ) : noisyColumns.length === 0 ? (
          <p className="text-body-secondary">
            {CHANGE_LOG_NOISY_COLUMNS_EMPTY}
          </p>
        ) : (
          <>
            <div className="d-none d-md-block">
              <table
                className="table table-sm align-middle mb-0"
                aria-labelledby={noisyHeadingId}
              >
                <thead>
                  <tr>
                    <th scope="col">{CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL}</th>
                    <th scope="col">{CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL}</th>
                  </tr>
                </thead>
                <tbody>
                  {noisyColumns.map((column) => (
                    <tr key={`${column.table}.${column.column}`}>
                      <td className="font-monospace text-break">
                        {column.table}.{column.column}
                      </td>
                      <td className="small text-break">{column.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="d-md-none d-flex flex-column gap-2">
              {noisyColumns.map((column) => (
                <div
                  key={`${column.table}.${column.column}`}
                  className="border rounded-3 p-3"
                >
                  <div className="font-monospace text-break mb-1">
                    {column.table}.{column.column}
                  </div>
                  <p className="small text-body-secondary text-break mb-0">
                    {column.reason}
                  </p>
                </div>
              ))}
            </div>
          </>
        )}
      </section>
    </HilosAdminPage>
  )
}
