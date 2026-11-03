// HilosLogsPage — the framework Hilos logs page (HilosPages.LOGS), the section root
// and its overview. The shared section head replaces the old child-page cards.
// Summary figures, the forecast and the takeout banner come from the full overview
// frame. By node uses its own viewport table: the first window rides in page_response,
// later windows follow the reader's viewport, and leaving the page releases its
// subscription. The screen commands nothing; takeout links open the rotations history
// on its awaiting filter (HIL-903). The project supplies the connection and page scopes.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  createHilosLogsOverview,
  createHilosLogNodesTable,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecent,
  HILOS_ROTATION_STATE_DUE,
  logLevelVariant,
  logRotationsPath,
  logsOverviewThresholdPassed,
  logsOverviewBatchesNote,
  logsOverviewForecastNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewRecent,
  logsOverviewRecentBadge,
  logsOverviewRecentEmptyLead,
  logsOverviewRecentEmptyTitle,
  logsOverviewRecentLead,
  logsOverviewRecentLevel,
  logsOverviewRecentOrigin,
  logsOverviewRecentPath,
  logsOverviewRecentTabLabel,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  NODE_ARCHIVE_BYTES_FIELD,
  NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD,
  NODE_GROWTH_BYTES_PER_DAY_FIELD,
  NODE_ID_FIELD,
  NODE_LAST_ROTATION_AT_FIELD,
  NODE_LIVE_BYTES_FIELD,
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
} from '@hilos/core/admin/logs'
import { formatMoment, HilosPages, hilosTimeZone } from '@hilos/core/base'
import type {
  HilosLogNodeRow,
  HilosLogNodesContext,
  HilosLogsRecentTab,
} from '@hilos/core/admin/logs'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'
import { HilosNodeName } from '../HilosNodeName.js'

/** The tabs of the recent-failures panel, in the order they stand. */
const RECENT_TABS: readonly HilosLogsRecentTab[] = [
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
]

/** Props for {@link HilosLogsPage}. */
export interface HilosLogsPageProps {
  /** The project context: the connection and page scopes the screen reads. */
  context: HilosLogNodesContext
}

const ROTATIONS_PATH = logRotationsPath(HILOS_ROTATION_STATE_DUE)

/**
 * The framework logs overview and its server-windowed node table.
 *
 * @param props The project context (connection and page scopes).
 */
export function HilosLogsPage({ context }: HilosLogsPageProps) {
  const handle = useMemo(() => createHilosLogsOverview(context), [context])
  const nodesHandle = useMemo(
    () => createHilosLogNodesTable(context),
    [context],
  )
  const overview = useSignal(handle.overview)
  useSignal(hilosTimeZone)

  // The table's first window rides in page_response; later windows and the overview
  // frame arrive on their own signals.
  useEffect(() => {
    nodesHandle.start()
    handle.start()

    return () => {
      nodesHandle.dispose()
      handle.dispose()
    }
  }, [handle, nodesHandle])

  // The per-node table exists only where nodes have names: in a single-node
  // installation the whole idea of a node is absent, and a table of one row about
  // "this machine" would be furniture for a distinction that does not exist.
  const clustered = hasLogsOverviewNodes(overview)
  const thresholdPassed = logsOverviewThresholdPassed(overview)

  // Which of the two empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const state = logsOverviewState(overview)

  // The banner is about batches that already exist; at zero there is nothing to say,
  // and a banner saying so would be a warning about nothing.
  const batchesDue = overview?.batchesDueForTakeout ?? 0
  const nodesDue = logsOverviewNodesDue(overview)
  const growthNote = logsOverviewGrowthNote(overview)
  const growthForecast = logsOverviewForecastNote(overview)

  // The panel of last failures. It is drawn only where there ARE figures: saying
  // "nothing has gone wrong" about a picture that has not arrived would be good news
  // made up, and that is the one thing an empty state here must never look like.
  // The open tab is the screen's own state: both lists ride every frame, so a click
  // on a tab asks the server for nothing.
  const [recentTab, setRecentTab] =
    useState<HilosLogsRecentTab>(RECENT_TAB_ERRORS)
  const recentEntries = logsOverviewRecent(overview, recentTab)
  const hasRecent = hasLogsOverviewRecent(overview, recentTab)
  const recentLevel = logsOverviewRecentLevel(recentTab)

  return (
    <HilosAdminPage
      page={HilosPages.LOGS}
      headKind="section"
      tabsLabel="Logs pages"
    >
      <div
        className="row row-cols-1 row-cols-sm-2 g-3 mb-3"
        data-id="hilos-logs-tiles"
      >
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-clock-history" aria-hidden="true" />
              <span className="small">Last rotation</span>
            </div>
            {/* The tile names when it last happened and how many batches there
                are. It does NOT say "on schedule": that would be a verdict on the
                rotation setting, which is not in this frame, and reassuring an admin
                falsely here costs more than saying less. */}
            <div className="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-rotation">
              {formatLogsOverviewRotationAt(overview?.lastRotationAt ?? null)}
            </div>
            <div className="small text-body-secondary">
              {logsOverviewBatchesNote(overview)}
            </div>
          </div>
        </div>

        <div className="col">
          <div
            className={`border rounded-3 p-3 h-100${thresholdPassed ? ' border-danger' : ''}`}
            data-id="hilos-logs-growth-tile"
          >
            <div className="d-flex align-items-center gap-2 mb-1">
              <i
                className={`bi bi-graph-up-arrow ${thresholdPassed ? 'text-danger' : 'text-body-secondary'}`}
                aria-hidden="true"
              />
              <span className="small text-body-secondary">Written per day</span>
            </div>
            <div className="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-growth">
              {formatLogsOverviewGrowth(overview)}
            </div>
            {growthNote ? (
              <div
                className="small text-body-secondary"
                data-id="hilos-logs-growth-note"
              >
                {growthNote}
              </div>
            ) : null}
            {/* Last of the three on purpose: the note above qualifies the
                figure, and this line says what the figure MEANS for the disk. The
                consequence is read after the caveat, and it is shown even while
                the caveat stands - the rate is there to divide by either way. */}
            {growthForecast ? (
              <div
                className={`small ${thresholdPassed ? 'text-danger-emphasis' : 'text-body-secondary'}`}
                data-id="hilos-logs-growth-forecast"
              >
                {thresholdPassed ? (
                  <i
                    className="bi bi-exclamation-triangle me-1"
                    aria-hidden="true"
                  />
                ) : null}
                <HilosHideable value={growthForecast} />
              </div>
            ) : null}
          </div>
        </div>
      </div>

      {/* One set of the three stream classes, in the order of the filters on the
          streams screen: daemon, agents, workers. A row of its own rather than a fifth
          tile in the row above: a class missing from a set of three is seen at once,
          and at the tail of a list of five it is not - which is how the daemon streams
          stayed off this screen. */}
      <div
        className="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4"
        data-id="hilos-logs-class-tiles"
      >
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-hdd-stack" aria-hidden="true" />
              <span className="small">Daemon streams</span>
            </div>
            <div className="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-daemon">
              {formatLogsOverviewCount(overview?.logKeysPerDaemon ?? null)}{' '}
              <span className="fs-6 text-body-secondary">
                ·{' '}
                {formatLogsOverviewBytes(
                  overview?.totalWeightDaemonKeysBytes ?? null,
                )}
              </span>
            </div>
            <div className="small text-body-secondary">
              The raw pair counted in
            </div>
          </div>
        </div>

        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-cpu" aria-hidden="true" />
              <span className="small">Agent streams</span>
            </div>
            <div className="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-agents">
              {formatLogsOverviewCount(overview?.logKeysPerAgent ?? null)}{' '}
              <span className="fs-6 text-body-secondary">
                ·{' '}
                {formatLogsOverviewBytes(
                  overview?.totalWeightAgentKeysBytes ?? null,
                )}
              </span>
            </div>
            <div className="small text-body-secondary">
              Live and archived together
            </div>
          </div>
        </div>

        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-diagram-3" aria-hidden="true" />
              <span className="small">Worker streams</span>
            </div>
            <div className="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-workers">
              {formatLogsOverviewCount(overview?.logKeysPerWorker ?? null)}{' '}
              <span className="fs-6 text-body-secondary">
                ·{' '}
                {formatLogsOverviewBytes(
                  overview?.totalWeightWorkerKeysBytes ?? null,
                )}
              </span>
            </div>
            <div className="small text-body-secondary">
              Monopolistic ones included
            </div>
          </div>
        </div>
      </div>

      {batchesDue > 0 ? (
        <div
          className="alert alert-warning d-flex align-items-start gap-3 py-3"
          data-id="hilos-logs-takeout"
        >
          <i className="bi bi-box-arrow-up fs-5" aria-hidden="true" />
          <div className="flex-grow-1">
            <div className="fw-semibold small mb-1">
              {logsOverviewTakeoutHeadline(batchesDue)}
              {nodesDue.length > 0 ? ` — on ${nodesDue.join(', ')}` : null}
            </div>
            <div className="small">
              They are past the retention you set. Until you take them and
              confirm it, they free no space — nothing is deleted here on its
              own.
            </div>
          </div>
          <HilosLink
            to={ROTATIONS_PATH}
            className="btn btn-sm btn-warning text-nowrap"
            data-id="hilos-logs-takeout-open"
          >
            Show them
          </HilosLink>
        </div>
      ) : null}

      {state === 'figures' ? (
        <>
          <div className="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
            <h2 className="h6 text-uppercase text-body-secondary mb-0">
              Recent failures
            </h2>
          </div>
          <p className="small text-body-secondary">
            {logsOverviewRecentLead(recentTab)}
          </p>
          {/* Two tabs and never one feed: warnings always outnumber errors and
              would bury them. */}
          <div
            className="border rounded-3 overflow-hidden mb-2"
            data-id="hilos-logs-recent"
          >
            <ul
              className="nav nav-tabs px-2 pt-2 bg-body-tertiary"
              role="tablist"
              data-id="hilos-logs-recent-tabs"
            >
              {RECENT_TABS.map((tab) => (
                <li key={tab} className="nav-item" role="presentation">
                  <button
                    type="button"
                    role="tab"
                    className={`nav-link py-1 px-3 small${recentTab === tab ? ' active' : ''}`}
                    aria-selected={recentTab === tab}
                    aria-controls="hilos-logs-recent-panel"
                    data-id={`hilos-logs-recent-tab-${tab}`}
                    onClick={() => setRecentTab(tab)}
                  >
                    {logsOverviewRecentTabLabel(tab)}
                    <span
                      className={`badge ms-1 text-bg-${logLevelVariant(logsOverviewRecentLevel(tab))}`}
                      data-id={`hilos-logs-recent-count-${tab}`}
                    >
                      {logsOverviewRecentBadge(overview, tab)}
                    </span>
                  </button>
                </li>
              ))}
              <li
                className="ms-auto small text-body-secondary py-1"
                role="presentation"
              >
                Over the last hour
              </li>
            </ul>
            <div id="hilos-logs-recent-panel" role="tabpanel">
              {hasRecent ? (
                recentEntries.map((entry, index) => (
                  <HilosLink
                    key={index}
                    to={logsOverviewRecentPath(entry)}
                    className="d-flex align-items-start gap-2 py-2 px-2 border-bottom text-decoration-none link-body-emphasis"
                    data-id="hilos-logs-recent-row"
                  >
                    <span
                      className={`small fw-semibold flex-shrink-0 text-${logLevelVariant(recentLevel)}`}
                    >
                      {recentLevel}
                    </span>
                    <span className="small text-body-secondary flex-shrink-0">
                      {formatLogsOverviewRecentAt(entry.at)}
                    </span>
                    <span className="flex-grow-1 small">
                      <HilosHideable value={entry.message} />
                      <span className="d-block text-body-secondary">
                        {logsOverviewRecentOrigin(overview, entry)}
                      </span>
                    </span>
                    {entry.traceFrames === null ? null : (
                      <span
                        className="badge bg-body-tertiary text-body-emphasis border flex-shrink-0"
                        title="This entry carries a stack trace"
                        data-id="hilos-logs-recent-row-trace"
                      >
                        <i
                          className="bi bi-info-circle me-1"
                          aria-hidden="true"
                        />
                        {entry.traceFrames}
                      </span>
                    )}
                    <i
                      className="bi bi-chevron-right text-body-secondary flex-shrink-0"
                      aria-hidden="true"
                    />
                  </HilosLink>
                ))
              ) : (
                /* Good news, and it must not read as "no data": the picture IS
                    here, and it says nothing of this kind happened in the window. */
                <div
                  className="p-3 d-flex align-items-center gap-3 bg-body-tertiary"
                  data-id="hilos-logs-recent-empty"
                >
                  <i
                    className="bi bi-check2-circle fs-4 text-success"
                    aria-hidden="true"
                  />
                  <div>
                    <div className="fw-semibold small">
                      {logsOverviewRecentEmptyTitle(recentTab)}
                    </div>
                    <div className="small text-body-secondary">
                      {logsOverviewRecentEmptyLead(recentTab)}
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </>
      ) : null}

      {clustered ? (
        <div className="mt-4">
          <HilosViewportTable
            controller={nodesHandle.controller}
            cells={{
              [NODE_ID_FIELD]: (row: HilosLogNodeRow) => (
                <span
                  className="badge bg-body-tertiary text-body-emphasis border"
                  data-id={`hilos-logs-node-${row.nodeId}`}
                >
                  <HilosNodeName nodeId={row.nodeId} nodeName={row.nodeName} />
                </span>
              ),
              [NODE_LAST_ROTATION_AT_FIELD]: (row: HilosLogNodeRow) => (
                <span data-id={`hilos-logs-node-rotation-${row.nodeId}`}>
                  {row.lastRotationAt === null
                    ? '—'
                    : (formatMoment(row.lastRotationAt, {
                        kind: 'dateTime',
                      }) ?? '—')}
                </span>
              ),
              [NODE_LIVE_BYTES_FIELD]: (row: HilosLogNodeRow) => (
                <span data-id={`hilos-logs-node-live-${row.nodeId}`}>
                  {formatLogsOverviewBytes(row.liveBytes)}
                </span>
              ),
              [NODE_ARCHIVE_BYTES_FIELD]: (row: HilosLogNodeRow) => (
                <span data-id={`hilos-logs-node-archive-${row.nodeId}`}>
                  {formatLogsOverviewBytes(row.archiveBytes)}
                </span>
              ),
              [NODE_GROWTH_BYTES_PER_DAY_FIELD]: (row: HilosLogNodeRow) => (
                <span data-id={`hilos-logs-node-growth-${row.nodeId}`}>
                  {formatLogsOverviewBytes(row.growthBytesPerDay)}
                </span>
              ),
              [NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD]: (row: HilosLogNodeRow) =>
                (row.batchesDueForTakeout ?? 0) > 0 ? (
                  <HilosLink
                    to={ROTATIONS_PATH}
                    className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
                    data-id={`hilos-logs-node-due-${row.nodeId}`}
                  >
                    {row.batchesDueForTakeout}
                  </HilosLink>
                ) : (
                  <span className="text-body-secondary small">—</span>
                ),
            }}
          />
        </div>
      ) : null}

      {state === 'unknown' ? (
        <div
          className="alert alert-secondary small py-3 mt-4"
          data-id="hilos-logs-empty-unknown"
        >
          <div className="fw-semibold mb-1">
            <i className="bi bi-hourglass-split me-1" aria-hidden="true" />
            The cluster picture has not arrived yet
          </div>
          Nobody has reported yet, so the tiles are empty rather than zero: a
          zero would say nothing has ever rotated, and here we simply do not
          know.
        </div>
      ) : null}
      {state === 'unreadable' ? (
        <div
          className="alert alert-secondary small py-3 mt-4"
          data-id="hilos-logs-empty-unreadable"
        >
          <div className="fw-semibold mb-1">
            <i className="bi bi-exclamation-triangle me-1" aria-hidden="true" />
            The log directory cannot be read
          </div>
          {clustered
            ? 'No node could read its log store.'
            : 'The log store could not be read.'}{' '}
          Check the log directory setting and the permissions on it. The tiles
          stay empty rather than zero — a zero would be a measurement nobody
          took.
        </div>
      ) : null}
    </HilosAdminPage>
  )
}
