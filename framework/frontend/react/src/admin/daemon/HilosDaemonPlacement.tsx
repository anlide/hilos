// The root owns the subscription. This component draws the current window and
// lets the existing controller own expansion in both rows and narrow cards.
import type { ReactNode } from 'react'
import {
  DAEMON_PLACEMENT_TYPE_FIELD,
  DAEMON_PLACEMENT_PLACEMENT_FIELD,
  DAEMON_PLACEMENT_NODES_FIELD,
  DAEMON_PLACEMENT_UNPLACED_FIELD,
  daemonPlacementCellIds,
  daemonPlacementNodeColumnKey,
  formatDaemonNumber,
  formatDaemonPlacementKind,
  formatDaemonPlacementState,
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
  type HilosDaemonPlacementView,
} from '@hilos/core/admin/daemon'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Props of the root's locally retained placement view. */
interface HilosDaemonPlacementProps {
  view: HilosDaemonPlacementView
  overview: HilosDaemonOverviewData | null
}

/** @param props The existing table handle and complete, unfiltered picture. */
export function HilosDaemonPlacement({
  view,
  overview,
}: HilosDaemonPlacementProps) {
  const nodes = useSignal(view.nodes)
  const selectedCell = useSignal(view.selectedCell)
  const placement = overview?.placement
  const unplacedTypes = placement?.unplacedTypes ?? null
  const knownNodes = new Set(
    (overview?.nodes ?? [])
      .filter((node) => node.online && node.workers !== null)
      .map((node) => node.nodeId),
  )
  const cells: Record<string, (row: HilosDaemonPlacementRow) => ReactNode> = {
    [DAEMON_PLACEMENT_TYPE_FIELD]: (row) => <code>{row.agentType}</code>,
    [DAEMON_PLACEMENT_PLACEMENT_FIELD]: (row) =>
      formatDaemonPlacementKind(row, overview?.clustered === true),
    [DAEMON_PLACEMENT_UNPLACED_FIELD]: (row) =>
      formatDaemonPlacementState(row) ? (
        <span className="badge text-bg-danger">
          {formatDaemonPlacementState(row)}
        </span>
      ) : null,
  }
  for (const node of nodes) {
    cells[daemonPlacementNodeColumnKey(node.nodeId)] = (row) => {
      const ids = knownNodes.has(node.nodeId)
        ? daemonPlacementCellIds(row, node.nodeId)
        : null
      return ids !== null && ids.length > 0 ? (
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          aria-label={`${row.agentType} on ${node.nodeId}: ${ids.length} instances`}
          aria-expanded={
            selectedCell?.rowKey === row.rowKey &&
            selectedCell?.nodeId === node.nodeId
          }
          data-id={`hilos-daemon-placement-cell-${row.rowKey}-${node.nodeId}`}
          onClick={() => view.openCell(row.rowKey, node.nodeId)}
        >
          {ids.length}
        </button>
      ) : (
        <span
          className="text-body-secondary"
          data-id={`hilos-daemon-placement-cell-${row.rowKey}-${node.nodeId}`}
          title={
            ids === null
              ? 'Node is silent or has not reported its agents'
              : undefined
          }
        >
          {ids === null ? '?' : '·'}
        </span>
      )
    }
  }
  return (
    <div data-id="hilos-daemon-placement-view">
      {overview === null ? (
        <>
          <p className="text-body-secondary">Waiting for the node picture…</p>
          <HilosSkeleton />
        </>
      ) : (
        <>
          <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
            <div className="col">
              <section className="border rounded p-3 h-100">
                <h2 className="h6">Running instances</h2>
                <div
                  className="fs-3"
                  data-id="hilos-daemon-placement-instances"
                >
                  {formatDaemonNumber(placement?.instances)}
                </div>
                <p className="small text-body-secondary mb-0">
                  Instances, not types
                </p>
              </section>
            </div>
            <div className="col">
              <section className="border rounded p-3 h-100">
                <h2 className="h6">Agent types</h2>
                <div className="fs-3" data-id="hilos-daemon-placement-types">
                  {formatDaemonNumber(placement?.types)}
                </div>
                <p className="small text-body-secondary mb-0">
                  Declared in the project
                </p>
              </section>
            </div>
            <div className="col">
              <section className="border rounded p-3 h-100">
                <h2 className="h6">One per cluster</h2>
                <div
                  className="fs-3"
                  data-id="hilos-daemon-placement-cluster-types"
                >
                  {formatDaemonNumber(placement?.clusterTypes)}
                </div>
                <p className="small text-body-secondary mb-0">
                  Declared in the project
                </p>
              </section>
            </div>
            <div className="col">
              <section className="border rounded p-3 h-100">
                <h2 className="h6">Unplaced</h2>
                <div
                  className={`fs-3 ${(unplacedTypes?.length ?? 0) > 0 ? 'text-danger-emphasis' : ''}`}
                  data-id="hilos-daemon-placement-unplaced"
                >
                  {formatDaemonNumber(unplacedTypes?.length)}
                </div>
                <p className="small text-body-secondary mb-0">
                  {unplacedTypes === null
                    ? 'Placement status unknown'
                    : unplacedTypes.length === 0
                      ? 'All placed'
                      : overview.clustered
                        ? 'Leader could not place these types'
                        : 'Could not place these types'}
                </p>
              </section>
            </div>
          </div>
          <HilosViewportTable
            controller={view.controller}
            cells={cells}
            details={{
              [DAEMON_PLACEMENT_NODES_FIELD]: (row) =>
                selectedCell?.rowKey === row.rowKey ? (
                  <section
                    className="rounded bg-body-tertiary p-2"
                    data-id="hilos-daemon-placement-selection"
                    aria-label="Selected agent instances"
                  >
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                      <h2 className="h6 mb-0 text-break">
                        <code>{selectedCell.agentType}</code>
                      </h2>
                      <span>
                        on{' '}
                        <code className="text-break">
                          {selectedCell.nodeId}
                        </code>
                      </span>
                      <span className="badge bg-body text-body border">
                        {selectedCell.agentIds.length} instances
                      </span>
                      <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary ms-auto"
                        data-id="hilos-daemon-placement-collapse"
                        onClick={() => view.closeCell()}
                      >
                        Collapse
                      </button>
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                      {selectedCell.agentIds.map((id) => (
                        <code key={id} className="text-break">
                          {id}
                        </code>
                      ))}
                    </div>
                  </section>
                ) : null,
            }}
          />
          <div data-id="hilos-daemon-placement-alerts" className="mt-3">
            {(unplacedTypes ?? []).map((agentType) => (
              <p
                key={agentType}
                className="alert alert-danger py-2 small text-break"
              >
                <code>{agentType}</code> is unplaced
              </p>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
