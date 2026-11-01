// The Maintenance Words tab: lists stubs for protected-mode operations. The core
// owns rows, copy and the merged "no own entry" row message; this view binds its
// window to Words and shares Maintenance's section head. Read only — no search,
// filters or actions. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import {
  createHilosMaintenanceWordsTable,
  MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
  MAINTENANCE_WORDS_MESSAGE_FIELD,
  MAINTENANCE_WORDS_OPERATION_FIELD,
  MAINTENANCE_WORDS_TITLE_FIELD,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Props for {@link HilosMaintenanceWordsPage}. */
export interface HilosMaintenanceWordsPageProps {
  /** The project context: scope stores, the connection and the action lifecycle. */
  context: HilosMaintenanceContext
}

/**
 * The words that announce each protected-mode operation, read only.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenanceWordsPage({
  context,
}: HilosMaintenanceWordsPageProps) {
  const words = useMemo(
    () =>
      createHilosMaintenanceWordsTable(context, HilosPages.MAINTENANCE_WORDS),
    [context],
  )

  // Bind the table to Words on mount, consuming its initial page window, and
  // release its listeners on unmount.
  useEffect(() => {
    words.start()

    return () => words.dispose()
  }, [words])

  return (
    <HilosAdminPage
      page={HilosPages.MAINTENANCE_WORDS}
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <HilosViewportTable
        controller={words.controller}
        dataId="hilos-maintenance-words-table"
        cells={{
          [MAINTENANCE_WORDS_OPERATION_FIELD]: (row) => (
            <span data-id={`hilos-maintenance-words-row-${row.operation}`}>
              {row.operation}
            </span>
          ),
          [MAINTENANCE_WORDS_TITLE_FIELD]: (row) => row.title,
          [MAINTENANCE_WORDS_MESSAGE_FIELD]: (row) => row.message,
          [MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD]: (row) => row.bannerMessage,
        }}
      />
    </HilosAdminPage>
  )
}
