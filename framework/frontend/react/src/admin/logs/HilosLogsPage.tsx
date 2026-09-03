// HilosLogsPage — the framework Hilos logs page (HilosPages.LOGS): the root of the
// section and its overview. It answers two questions at once, "is anything wrong with
// the journals" and "where do I go from here", so it keeps the shell's cards to its
// child pages and puts its own figures underneath them, in the shell's body.
// Everything on it arrives in ONE frame of the page's own signal and refreshes itself
// by push; there is no table viewport, because the per-node rows are one per node that
// reported and fit in that frame whole. The screen commands nothing: the takeout banner
// and the per-node badge are ordinary navigation into the rotations history. Which of
// the two empty states it is in, and the wording of every figure, are the core
// headless's (hilosLogsOverview); this view owns only the markup, so a project mounts
// it by passing its HilosLogsOverviewContext. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo } from 'react'
import {
  HILOS_PAGE_ROUTES,
  HilosPages,
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  logsOverviewBatchesNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
} from '@hilos/core'
import type { HilosLogsOverviewContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosLogsPage}. */
export interface HilosLogsPageProps {
  /** The project context: the connection the screen's frames arrive on. */
  context: HilosLogsOverviewContext
}

const ROTATIONS_PATH = HILOS_PAGE_ROUTES[HilosPages.LOGS_ROTATIONS]

/**
 * The framework logs overview: four tiles, the takeout banner, the per-node table
 * and the two empty states, all off one pushed frame.
 *
 * @param props The project context (the connection the frames arrive on).
 */
export function HilosLogsPage({ context }: HilosLogsPageProps) {
  const handle = useMemo(() => createHilosLogsOverview(context), [context])
  const overview = useSignal(handle.overview)

  // The frame arrives once as the answer to the subscription and again on every tick
  // where the cluster picture moved; nothing is ever re-requested.
  useEffect(() => {
    handle.start()

    return () => handle.dispose()
  }, [handle])

  // The per-node table exists only where nodes have names: in a single-node
  // installation the whole idea of a node is absent, and a table of one row about
  // "this machine" would be furniture for a distinction that does not exist.
  const clustered = hasLogsOverviewNodes(overview)
  const nodes = overview?.nodes ?? []

  // Which of the two empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const state = logsOverviewState(overview)

  // The banner is about batches that already exist; at zero there is nothing to say,
  // and a banner saying so would be a warning about nothing.
  const batchesDue = overview?.batchesDueForTakeout ?? 0
  const nodesDue = logsOverviewNodesDue(overview)
  const growthNote = logsOverviewGrowthNote(overview)

  return (
    <HilosAdminPage page={HilosPages.LOGS}>
      <div
        className="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4 mt-1"
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
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-graph-up-arrow" aria-hidden="true" />
              <span className="small">Written per day</span>
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

      {clustered ? (
        <>
          <div className="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
            <h2 className="h6 text-uppercase text-body-secondary mb-0">
              By node
            </h2>
            <span className="badge text-bg-success-subtle text-success-emphasis border border-success-subtle">
              <i className="bi bi-broadcast me-1" aria-hidden="true" />
              Updates itself
            </span>
          </div>
          <p className="small text-body-secondary">
            A journal is written on the node the event happened on and stays
            there. The tiles above add every node together; here you can see
            whose journal is growing faster.
          </p>
          {/* An ordinary table and not the viewport one: the rows are one per node
          that reported and ride the same frame as the tiles, so a descriptor, a
          pager and a busy state would all be paid for a list that never pages. */}
          <div className="table-responsive">
            <table className="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Node</th>
                  <th scope="col">Last rotation</th>
                  <th scope="col" className="text-end d-none d-lg-table-cell">
                    Live
                  </th>
                  <th scope="col" className="text-end d-none d-lg-table-cell">
                    Archive
                  </th>
                  <th scope="col" className="text-end d-none d-lg-table-cell">
                    Per day
                  </th>
                  <th scope="col">To take out</th>
                </tr>
              </thead>
              <tbody>
                {nodes.map((node) => (
                  <tr
                    key={node.nodeId}
                    data-id={`hilos-logs-node-${node.nodeId}`}
                  >
                    <td>
                      <span className="badge text-bg-light border">
                        {node.nodeId}
                      </span>
                      {/* The sub-line carries whatever the hidden columns were
                      carrying, so a narrow screen loses the layout and not the
                      figures. */}
                      {node.available ? (
                        <div className="small text-body-secondary d-lg-none">
                          {formatLogsOverviewBytes(node.liveBytes)} ·{' '}
                          {formatLogsOverviewBytes(node.archiveBytes)} ·{' '}
                          {formatLogsOverviewBytes(node.growthBytesPerDay)}
                        </div>
                      ) : null}
                    </td>
                    {/* A node that could not read its own store says so once, in
                    place of its figures. Dashes across every column would read as
                    six separate unknowns instead of one node that did not answer. */}
                    {node.available ? (
                      <>
                        <td className="small">
                          {formatLogsOverviewRotationAt(node.lastRotationAt)}
                        </td>
                        <td className="text-end d-none d-lg-table-cell">
                          {formatLogsOverviewBytes(node.liveBytes)}
                        </td>
                        <td className="text-end d-none d-lg-table-cell">
                          {formatLogsOverviewBytes(node.archiveBytes)}
                        </td>
                        <td className="text-end d-none d-lg-table-cell">
                          {formatLogsOverviewBytes(node.growthBytesPerDay)}
                        </td>
                        <td>
                          {(node.batchesDueForTakeout ?? 0) > 0 ? (
                            <HilosLink
                              to={ROTATIONS_PATH}
                              className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
                              data-id={`hilos-logs-node-due-${node.nodeId}`}
                            >
                              {node.batchesDueForTakeout}
                            </HilosLink>
                          ) : (
                            <span className="text-body-secondary small">—</span>
                          )}
                        </td>
                      </>
                    ) : (
                      <td
                        colSpan={5}
                        className="small text-body-secondary"
                        data-id={`hilos-logs-node-nodata-${node.nodeId}`}
                      >
                        No data — this node could not read its log store
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
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
          No node could read its log store. Check the log directory setting and
          the permissions on it. The tiles stay empty rather than zero — a zero
          would be a measurement nobody took.
        </div>
      ) : null}
    </HilosAdminPage>
  )
}
