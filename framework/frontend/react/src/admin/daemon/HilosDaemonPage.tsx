// One page answer feeds the overview and the placement table. Switching these
// local diagrams keeps their handles and never navigates or resubscribes.
import { useEffect, useMemo, useState, type CSSProperties } from 'react'
import {
  createHilosDaemonOverview,
  createHilosDaemonPlacementView,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  daemonWorkerOccupancy,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonDatabaseAddress,
  formatDaemonNodeState,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonWorkerName,
  HilosPages,
  type HilosDaemonContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeInspector } from './HilosDaemonNodeInspector.js'
import { HilosDaemonPlacement } from './HilosDaemonPlacement.js'

/** Props for the Daemon section root. */
export interface HilosDaemonPageProps {
  context: HilosDaemonContext
}

/** @param props The project's connection and page scope. */
export function HilosDaemonPage({ context }: HilosDaemonPageProps) {
  const daemon = useMemo(() => createHilosDaemonOverview(context), [context])
  const placement = useMemo(
    () => createHilosDaemonPlacementView(context, daemon.overview),
    [context, daemon],
  )
  const [placementActive, setPlacementActive] = useState(false)
  const [placementOpened, setPlacementOpened] = useState(false)
  const overview = useSignal(daemon.overview)
  const database = useSignal(daemon.database)
  const selectedNodeId = useSignal(daemon.selectedNodeId)
  const selectedNode = useSignal(daemon.selectedNode)
  useEffect(() => {
    daemon.start()
    placement.start()
    return () => {
      placement.dispose()
      daemon.dispose()
    }
  }, [daemon, placement])
  return (
    <HilosAdminPage
      page={HilosPages.DAEMON}
      childParams={daemonNodeChildParams(selectedNodeId)}
    >
      <div className="nav nav-tabs mb-3" aria-label="Daemon diagrams">
        <button
          type="button"
          className={`nav-link ${!placementActive ? 'active' : ''}`}
          aria-pressed={!placementActive}
          data-id="hilos-daemon-nodes-tab"
          onClick={() => setPlacementActive(false)}
        >
          Nodes and processes
        </button>
        <button
          type="button"
          className={`nav-link ${placementActive ? 'active' : ''}`}
          aria-pressed={placementActive}
          data-id="hilos-daemon-placement-tab"
          onClick={() => {
            setPlacementOpened(true)
            setPlacementActive(true)
          }}
        >
          Agent placement
        </button>
      </div>
      {placementOpened && (
        <div hidden={!placementActive}>
          <HilosDaemonPlacement view={placement} overview={overview} />
        </div>
      )}
      <div hidden={placementActive} data-id="hilos-daemon-nodes-view">
        {overview === null ? (
          <div data-id="hilos-daemon-overview-waiting">
            <p className="text-body-secondary">Waiting for the node picture…</p>
            <HilosSkeleton />
          </div>
        ) : (
          <>
            {overview.clustered && (
              <div
                className="d-flex flex-wrap align-items-center gap-2 mb-3 small"
                data-id="hilos-daemon-consensus"
              >
                <span
                  className={`badge border ${overview.consensus === null ? 'bg-body-tertiary text-body' : overview.consensus.onlineMasters >= overview.consensus.quorumSize ? 'bg-success-subtle text-success-emphasis border-success-subtle' : 'bg-danger-subtle text-danger-emphasis border-danger-subtle'}`}
                  data-id="hilos-daemon-quorum"
                >
                  Quorum:{' '}
                  {overview.consensus === null
                    ? '—'
                    : `${overview.consensus.onlineMasters} of ${overview.consensus.masters}`}
                  {overview.consensus &&
                    ` · ${overview.consensus.onlineMasters >= overview.consensus.quorumSize ? 'Available' : 'Unavailable'}`}
                </span>
                <span
                  className="badge bg-body-tertiary text-body border"
                  data-id="hilos-daemon-term"
                >
                  Term: {formatDaemonNumber(overview.consensus?.term)}
                </span>
                {overview.consensus && (
                  <span className="text-body-secondary text-break">
                    Report: {overview.consensus.nodeId} · Majority:{' '}
                    {overview.consensus.quorumSize}
                  </span>
                )}
              </div>
            )}
            {overview.nodes.length === 0 ? (
              <p
                className="border rounded p-4 text-center text-body-secondary"
                data-id="hilos-daemon-overview-empty"
              >
                No nodes in the picture
              </p>
            ) : (
              <div className="row g-3 mb-3" data-id="hilos-daemon-nodes">
                {overview.nodes.map((node, nodeIndex) => (
                  <div
                    key={node.nodeId}
                    className="col-12 col-md-6 col-xl-4 d-flex flex-column flex-md-row gap-2"
                  >
                    {overview.clustered && nodeIndex > 0 && (
                      <div
                        className="d-flex flex-column align-items-center justify-content-center small text-body-secondary"
                        data-id={`hilos-daemon-link-${node.nodeId}`}
                        data-silent={
                          !node.online || !overview.nodes[nodeIndex - 1]?.online
                        }
                      >
                        <i
                          className={`bi ${!node.online || !overview.nodes[nodeIndex - 1]?.online ? 'bi-link-45deg text-danger-emphasis' : 'bi-arrow-left-right'}`}
                          aria-hidden="true"
                        />
                        <span className="visually-hidden">
                          {overview.nodes[nodeIndex - 1]?.nodeId} ↔{' '}
                          {node.nodeId} ·{' '}
                          {!node.online ||
                          !overview.nodes[nodeIndex - 1]?.online
                            ? 'Silent member'
                            : 'Membership link'}
                        </span>
                      </div>
                    )}
                    <button
                      type="button"
                      className={`btn bg-body text-body border rounded shadow-sm w-100 p-3 text-start ${selectedNodeId === node.nodeId ? 'border-primary border-2' : node.state === 'silent' ? 'border-danger-subtle' : ''}`}
                      aria-pressed={selectedNodeId === node.nodeId}
                      aria-label={`Select node ${node.nodeId}`}
                      data-id={`hilos-daemon-node-${node.nodeId}`}
                      data-state={node.state}
                      onClick={() => daemon.selectNode(node.nodeId)}
                    >
                      <span className="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <i className="bi bi-hdd-network" aria-hidden="true" />
                        <span className="fw-semibold font-monospace text-break">
                          {node.nodeId}
                        </span>
                        {overview.clustered ? (
                          <span
                            className={`badge border ${node.state === 'silent' ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle' : 'bg-body-tertiary text-body'}`}
                          >
                            {node.state === null
                              ? '—'
                              : formatDaemonNodeState(node.state)}
                          </span>
                        ) : (
                          <span className="badge bg-body-tertiary text-body border">
                            Single installation
                          </span>
                        )}
                      </span>
                      <span
                        className="d-flex flex-wrap gap-1 mb-3"
                        data-id={`hilos-daemon-workers-${node.nodeId}`}
                      >
                        {node.workers === null ? (
                          <span className="small text-body-secondary">
                            Workers: —
                          </span>
                        ) : node.workers.length === 0 ? (
                          <span className="small text-body-secondary">
                            No workers
                          </span>
                        ) : (
                          node.workers.map((worker) => (
                            <span
                              key={`${worker.kind}:${worker.index}`}
                              className={`badge border font-monospace ${daemonWorkerOccupancy(worker) === 'free' ? 'bg-body-tertiary text-body border-secondary-subtle' : 'bg-primary-subtle text-primary-emphasis border-primary-subtle'}`}
                              data-id={`hilos-daemon-worker-${node.nodeId}-${worker.kind}-${worker.index}`}
                              data-occupancy={daemonWorkerOccupancy(worker)}
                              title={`${worker.kind === 'monopolistic' ? 'Monopolistic' : 'Ordinary'} worker · ${daemonWorkerOccupancy(worker)} · ${worker.agentCount} agents`}
                            >
                              {formatDaemonWorkerName(
                                worker.index,
                                worker.kind,
                              )}
                              <span className="visually-hidden">
                                {' '}
                                · {daemonWorkerOccupancy(worker)} ·{' '}
                                {worker.agentCount} agents
                              </span>
                            </span>
                          ))
                        )}
                      </span>
                      <span className="d-block small">
                        <span className="d-flex justify-content-between gap-2 mb-1">
                          <span>Daemon CPU</span>
                          <span>
                            {formatDaemonCores(node.measurements?.daemonCores)}{' '}
                            / {formatDaemonCores(node.measurements?.cores)}{' '}
                            cores
                          </span>
                        </span>
                        <span
                          className="d-block progress hilos-progress-track"
                          aria-hidden="true"
                        >
                          <span
                            className="progress-bar hilos-progress"
                            style={
                              {
                                '--hilos-progress': daemonProgressWidth(
                                  daemonPercentage(
                                    node.measurements?.daemonCores,
                                    node.measurements?.cores,
                                  ),
                                ),
                              } as CSSProperties
                            }
                          />
                        </span>
                        <span className="d-block text-body-secondary mb-2">
                          {formatDaemonPercentage(
                            daemonPercentage(
                              node.measurements?.daemonCores,
                              node.measurements?.cores,
                            ),
                          )}{' '}
                          of available CPU
                        </span>
                        <span className="d-flex justify-content-between gap-2 mb-1">
                          <span>Measured memory</span>
                          <span>
                            {formatDaemonBytes(daemonTotalMemory(node))} /{' '}
                            {formatDaemonBytes(
                              node.measurements?.memoryCeiling,
                            )}
                          </span>
                        </span>
                        <span
                          className="d-block progress hilos-progress-track"
                          aria-hidden="true"
                        >
                          <span
                            className="progress-bar hilos-progress"
                            style={
                              {
                                '--hilos-progress': daemonProgressWidth(
                                  daemonPercentage(
                                    daemonTotalMemory(node),
                                    node.measurements?.memoryCeiling,
                                  ),
                                ),
                              } as CSSProperties
                            }
                          />
                        </span>
                        <span className="d-block text-body-secondary mb-2">
                          {formatDaemonNumber(
                            node.measurements?.workersMeasured,
                          )}{' '}
                          worker processes measured
                        </span>
                        <span className="d-block">
                          Monopolistic:{' '}
                          {formatDaemonNumber(daemonMonopolisticCount(node))}
                        </span>
                        <span className="d-block text-body-secondary mb-2">
                          Pool grows on demand
                        </span>
                        <span
                          className="d-block"
                          data-id={`hilos-daemon-browsers-${node.nodeId}`}
                        >
                          {node.role === 'slave'
                            ? 'Does not accept browsers'
                            : `Browser connections: ${formatDaemonNumber(node.standing?.connections)}`}
                        </span>
                      </span>
                    </button>
                  </div>
                ))}
              </div>
            )}
          </>
        )}
        <section
          className="border rounded bg-body-tertiary p-3 mb-3"
          data-id="hilos-daemon-database"
        >
          <div className="d-flex flex-wrap align-items-center gap-2">
            <i className="bi bi-database" aria-hidden="true" />
            <h2 className="h6 mb-0">Project database</h2>
            <span
              className="small font-monospace text-break"
              data-id="hilos-daemon-database-address"
            >
              {formatDaemonDatabaseAddress(database)}
            </span>
            <span
              className="badge bg-body text-body border"
              data-id="hilos-daemon-database-migration"
            >
              Migration: {formatDaemonNumber(database?.migrationIndex)}
            </span>
          </div>
          {database?.socket != null && (
            <p
              className="small text-break mb-0 mt-2"
              data-id="hilos-daemon-database-socket"
            >
              Unix socket: <code>{database.socket}</code>
            </p>
          )}
          <p className="small text-body-secondary mb-0 mt-2">
            Shared primary connection
          </p>
        </section>
        {overview && selectedNode && (
          <HilosDaemonNodeInspector overview={overview} node={selectedNode} />
        )}
      </div>
    </HilosAdminPage>
  )
}
