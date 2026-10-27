import { useMemo } from 'react'
import {
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogJournalNote,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  readHilosChangeLogOverview,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import type { HilosChangeLogContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosChangeLogPage}. */
export interface HilosChangeLogPageProps {
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}

/**
 * The Change Log overview and its two journal figures.
 *
 * @param props The project context (connection, page scope, tracked actions).
 */
export function HilosChangeLogPage({ context }: HilosChangeLogPageProps) {
  useSignal(hilosTimeZone)
  const overviewSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
    [context],
  )
  const overview = readHilosChangeLogOverview(useSignal(overviewSection))
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG}>
      <p className="text-body-secondary">{CHANGE_LOG_SECTION_NOTE}</p>

      <div className="row row-cols-1 row-cols-sm-2 g-3 mb-4">
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-database" aria-hidden="true" />
              <span className="small">{CHANGE_LOG_TILE_JOURNAL_LABEL}</span>
            </div>
            <div
              className="fs-4 lh-1 mb-1"
              data-id="hilos-change-log-tile-journal"
            >
              {overview ? formatChangeLogEntries(overview.journalEntries) : '—'}
            </div>
            <div className="small text-body-secondary">
              {overview ? changeLogJournalNote(overview) : '—'}
            </div>
          </div>
        </div>
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-table" aria-hidden="true" />
              <span className="small">{CHANGE_LOG_TILE_TRACKED_LABEL}</span>
            </div>
            <div
              className="fs-4 lh-1 mb-1"
              data-id="hilos-change-log-tile-tracked"
            >
              {overview ? formatChangeLogCoverage(overview) : '—'}
            </div>
            <div className="small text-body-secondary">
              {CHANGE_LOG_TILE_TRACKED_NOTE}
            </div>
          </div>
        </div>
      </div>
    </HilosAdminPage>
  )
}
