import { useEffect, useMemo } from 'react'
import {
  CHANGE_LOG_NOISY_COLUMN_FIELD,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_REASON_FIELD,
  createHilosChangeLogNoisyColumnsTable,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import type { HilosChangeLogContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Props for {@link HilosChangeLogNoisyPage}. */
export interface HilosChangeLogNoisyPageProps {
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}

/**
 * The server-windowed list of columns the journal skips as noise.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosChangeLogNoisyPage({
  context,
}: HilosChangeLogNoisyPageProps) {
  const columns = useMemo(
    () => createHilosChangeLogNoisyColumnsTable(context),
    [context],
  )

  useEffect(() => {
    columns.start()
    return () => columns.dispose()
  }, [columns])

  return (
    <HilosAdminPage
      page={HilosPages.CHANGE_LOG_NOISY}
      headKind="section"
      tabsLabel="Change Log pages"
    >
      <p className="text-body-secondary">{CHANGE_LOG_NOISY_COLUMNS_NOTE}</p>
      <HilosViewportTable
        controller={columns.controller}
        cells={{
          [CHANGE_LOG_NOISY_COLUMN_FIELD]: (row) => (
            <code className="text-break">
              {row.table}.{row.column}
            </code>
          ),
          [CHANGE_LOG_NOISY_REASON_FIELD]: (row) => (
            <span className="small text-break">{row.reason}</span>
          ),
        }}
      />
    </HilosAdminPage>
  )
}
