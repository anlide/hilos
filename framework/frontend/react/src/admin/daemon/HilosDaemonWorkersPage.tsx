// Workers of the route's node, using the existing server viewport and report verdict.
import { useContext, useEffect, useMemo } from 'react'
import {
  createHilosDaemonWorkersTable,
  DAEMON_WORKER_INDEX_FIELD,
  DAEMON_WORKER_KIND_FIELD,
  DAEMON_WORKER_PID_FIELD,
  DAEMON_WORKER_AGENT_COUNT_FIELD,
  DAEMON_WORKER_MEMORY_FIELD,
  DAEMON_WORKER_AGENT_IDS_FIELD,
  daemonProcessesEmptyState,
  daemonWorkerLogPath,
  formatDaemonNodeSilentSince,
  formatDaemonWorkerAgentCount,
  formatDaemonWorkerKind,
  formatDaemonWorkerMemory,
  formatDaemonWorkerName,
  formatDaemonWorkerPid,
  HilosPages,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonWorkersContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeLine } from './HilosDaemonNodeLine.js'

/** Props for the framework's workers page. */
export interface HilosDaemonWorkersPageProps {
  context: HilosDaemonWorkersContext
  showLogLinks?: boolean
}

/** @param props The project's connection and page scope. */
export function HilosDaemonWorkersPage({
  context,
  showLogLinks = true,
}: HilosDaemonWorkersPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonWorkersPage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  const workers = useMemo(
    () => createHilosDaemonWorkersTable(context, nodeId),
    [context, nodeId],
  )
  const heading = useSignal(
    useMemo(() => readHilosDaemonNodeHeading(context.scopes), [context]),
  )
  const reported = useSignal(
    useMemo(() => readDaemonProcessesReported(context.scopes), [context]),
  )
  const rows = useSignal(workers.controller.rows)
  const emptyState = daemonProcessesEmptyState(heading, reported)
  const silentSince = formatDaemonNodeSilentSince(heading, Date.now())
  useEffect(() => {
    workers.start()
    return () => workers.dispose()
  }, [workers])
  return (
    <HilosAdminPage page={HilosPages.DAEMON_WORKERS}>
      <HilosDaemonNodeLine nodeId={nodeId} heading={heading} />
      <div className="position-relative">
        <p className="small text-body-secondary invisible" aria-hidden="true">
          This node has been silent since September 30, 2026, 11:59 PM. The
          workers below are from its last report.
        </p>
        {silentSince !== null && rows.length > 0 && (
          <p
            className="small text-body-secondary position-absolute top-0 start-0 w-100"
            data-id="hilos-daemon-workers-silent"
          >
            This node has been silent since {silentSince}. The workers below are
            from its last report.
          </p>
        )}
      </div>
      <HilosViewportTable
        controller={workers.controller}
        cells={{
          [DAEMON_WORKER_INDEX_FIELD]: (row) => (
            <code className="fw-semibold">
              {formatDaemonWorkerName(row.index, row.kind)}
            </code>
          ),
          [DAEMON_WORKER_KIND_FIELD]: (row) => (
            <span className="small">{formatDaemonWorkerKind(row)}</span>
          ),
          [DAEMON_WORKER_PID_FIELD]: (row) => (
            <code>{formatDaemonWorkerPid(row)}</code>
          ),
          [DAEMON_WORKER_AGENT_COUNT_FIELD]: (row) => (
            <span
              className={`d-block text-center ${row.agentCount === 0 ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonWorkerAgentCount(row)}
            </span>
          ),
          [DAEMON_WORKER_MEMORY_FIELD]: (row) => formatDaemonWorkerMemory(row),
          actions: (row) =>
            showLogLinks ? (
              <HilosLink
                to={daemonWorkerLogPath(nodeId, row)}
                className="btn btn-sm btn-outline-secondary text-nowrap"
                data-id={`hilos-daemon-worker-log-${row.rowKey}`}
              >
                Log
              </HilosLink>
            ) : null,
        }}
        details={{
          [DAEMON_WORKER_AGENT_IDS_FIELD]: (row) => (
            <div
              className="d-flex flex-wrap gap-1"
              data-id={`hilos-daemon-worker-agents-${row.rowKey}`}
            >
              {row.agentIds.map((id) => (
                <span
                  key={id}
                  className="badge bg-body-tertiary text-body border font-monospace"
                >
                  {id}
                </span>
              ))}
              {row.agentIds.length === 0 && (
                <span className="text-body-secondary">—</span>
              )}
            </div>
          ),
        }}
        empty={
          emptyState === 'waiting' ? (
            <div data-id="hilos-daemon-workers-empty-waiting">
              <div className="fw-semibold">
                The node has not reported its workers yet
              </div>
              <p className="mb-0">
                Nothing has arrived from this node yet — not zero workers.
              </p>
            </div>
          ) : emptyState === 'silent' ? (
            <div data-id="hilos-daemon-workers-empty-silent">
              <div className="fw-semibold">
                The node is silent and never reported its workers
              </div>
              <p className="mb-0">
                Its workers will appear when it reports again.
              </p>
            </div>
          ) : (
            <div data-id="hilos-daemon-workers-empty-none">
              <div className="fw-semibold">The node reports no workers</div>
            </div>
          )
        }
      />
    </HilosAdminPage>
  )
}
