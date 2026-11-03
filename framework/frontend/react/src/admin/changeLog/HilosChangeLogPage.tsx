import { useContext, useMemo } from 'react'
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
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'
import type { HilosChangeLogContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
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
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosChangeLogPage requires a provided Hilos router.')
  }
  const identity = useSignal(router.pageIdentity)
  const route = useSignal(router.currentRoute)
  const tabs = hilosTabLinks(
    identity?.pageTabs ?? [],
    route.params,
    router.resolvePath,
  )
  const feedPath = tabs.find(
    (tab) => tab.page === HilosPages.CHANGE_LOG_FEED,
  )?.to
  const tablesPath = tabs.find(
    (tab) => tab.page === HilosPages.CHANGE_LOG_TABLES,
  )?.to
  useSignal(hilosTimeZone)
  const overviewSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
    [context],
  )
  const overview = readHilosChangeLogOverview(useSignal(overviewSection))
  const tileClassName =
    'd-block border rounded-3 p-3 h-100 text-body text-decoration-none'
  const journalTile = (
    <>
      <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
        <i className="bi bi-database" aria-hidden="true" />
        <span className="small">{CHANGE_LOG_TILE_JOURNAL_LABEL}</span>
      </div>
      <div className="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-journal">
        {overview ? formatChangeLogEntries(overview.journalEntries) : '—'}
      </div>
      <div className="small text-body-secondary">
        {overview ? changeLogJournalNote(overview) : '—'}
      </div>
    </>
  )
  const trackedTile = (
    <>
      <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
        <i className="bi bi-table" aria-hidden="true" />
        <span className="small">{CHANGE_LOG_TILE_TRACKED_LABEL}</span>
      </div>
      <div className="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-tracked">
        {overview ? formatChangeLogCoverage(overview) : '—'}
      </div>
      <div className="small text-body-secondary">
        {CHANGE_LOG_TILE_TRACKED_NOTE}
      </div>
    </>
  )

  return (
    <HilosAdminPage
      page={HilosPages.CHANGE_LOG}
      headKind="section"
      tabsLabel="Change Log pages"
    >
      <p className="text-body-secondary">{CHANGE_LOG_SECTION_NOTE}</p>

      <div className="row row-cols-1 row-cols-sm-2 g-3 mb-4">
        <div className="col">
          {feedPath === undefined ? (
            <div className="border rounded-3 p-3 h-100">{journalTile}</div>
          ) : (
            <HilosLink
              to={feedPath}
              className={tileClassName}
              data-id="hilos-change-log-link-feed"
            >
              {journalTile}
            </HilosLink>
          )}
        </div>
        <div className="col">
          {tablesPath === undefined ? (
            <div className="border rounded-3 p-3 h-100">{trackedTile}</div>
          ) : (
            <HilosLink
              to={tablesPath}
              className={tileClassName}
              data-id="hilos-change-log-link-tables"
            >
              {trackedTile}
            </HilosLink>
          )}
        </div>
      </div>
    </HilosAdminPage>
  )
}
