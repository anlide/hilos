// HTTP summary and route counters from the selected node's existing page answer.
import { useContext, useEffect, useMemo } from 'react'
import {
  createHilosDaemonHttpRoutesTable,
  daemonHttpEmptyState,
  DAEMON_NODE_STATE_SILENT,
  DAEMON_HTTP_ROUTE_PATH_FIELD,
  DAEMON_HTTP_ROUTE_REQUESTS_FIELD,
  DAEMON_HTTP_ROUTE_ERRORS_FIELD,
  DAEMON_HTTP_ROUTE_SLOWEST_FIELD,
  formatDaemonHttpAddress,
  formatDaemonHttpCount,
  formatDaemonHttpPeriod,
  formatDaemonHttpRoute,
  formatDaemonHttpSlowest,
  formatDaemonNodeSilentSince,
  readHilosDaemonHttpSummary,
  readHilosDaemonNodeHeading,
  type HilosDaemonHttpContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeLine } from './HilosDaemonNodeLine.js'

/** Props for the framework's HTTP server page. */
export interface HilosDaemonHttpServerPageProps {
  context: HilosDaemonHttpContext
}

/** @param props The project's connection and page scope. */
export function HilosDaemonHttpServerPage({
  context,
}: HilosDaemonHttpServerPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosDaemonHttpServerPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  const routes = useMemo(
    () => createHilosDaemonHttpRoutesTable(context, nodeId),
    [context, nodeId],
  )
  const heading = useSignal(
    useMemo(() => readHilosDaemonNodeHeading(context.scopes), [context]),
  )
  const summary = useSignal(
    useMemo(() => readHilosDaemonHttpSummary(context.scopes), [context]),
  )
  const emptyState = daemonHttpEmptyState(heading, summary)
  const silent = heading?.state === DAEMON_NODE_STATE_SILENT
  const silentSince = formatDaemonNodeSilentSince(heading, Date.now())
  const period = summary === null ? '—' : formatDaemonHttpPeriod(summary)
  const status =
    emptyState !== 'none' ? emptyState : silent ? 'last-report' : null
  const waitingMessage = 'The node has not reported HTTP metrics yet'
  const silentMessage = 'The node is silent and has not reported HTTP metrics'
  const lastReportReserve =
    'This node has been silent since September 30, 2026, 11:59 PM. The values below are from its last report.'
  const statusMessage =
    status === 'waiting'
      ? waitingMessage
      : status === 'silent'
        ? silentMessage
        : status === 'last-report'
          ? `This node has been silent since ${silentSince ?? '—'}. The values below are from its last report.`
          : ''
  useEffect(() => {
    routes.start()
    return () => routes.dispose()
  }, [routes])
  return (
    <HilosAdminPage page={HilosPages.DAEMON_HTTP_SERVER}>
      <HilosDaemonNodeLine nodeId={nodeId} heading={heading} />
      <div className="hilos-stack mb-3">
        <p
          className="small text-body-secondary mb-0 invisible"
          aria-hidden="true"
        >
          {waitingMessage}
        </p>
        <p
          className="small text-body-secondary mb-0 invisible"
          aria-hidden="true"
        >
          {silentMessage}
        </p>
        <p
          className="small text-body-secondary mb-0 invisible"
          aria-hidden="true"
        >
          {lastReportReserve}
        </p>
        {status !== null && (
          <p
            className="small text-body-secondary mb-0"
            data-id="hilos-daemon-http-status"
            data-status={status}
          >
            {statusMessage}
          </p>
        )}
      </div>
      <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-globe" aria-hidden="true" />
              <span className="small">Address</span>
            </div>
            <div
              className="fs-6 font-monospace lh-1 mb-1 text-break"
              data-id="hilos-daemon-http-address"
            >
              {summary === null ? '—' : formatDaemonHttpAddress(summary)}
            </div>
            <div className="small text-body-secondary">
              {summary === null ? '—' : silent ? 'Last report' : 'Listening'}
            </div>
          </div>
        </div>
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-arrow-down-up" aria-hidden="true" />
              <span className="small">Requests</span>
            </div>
            <div
              className="fs-5 lh-1 mb-1 text-break"
              data-id="hilos-daemon-http-requests"
            >
              {summary === null ? '—' : formatDaemonHttpCount(summary.requests)}
            </div>
            <div
              className="small text-body-secondary"
              data-id="hilos-daemon-http-period"
            >
              {period}
            </div>
          </div>
        </div>
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-exclamation-triangle" aria-hidden="true" />
              <span className="small">Errors</span>
            </div>
            <div
              className="fs-5 lh-1 mb-1 text-break"
              data-id="hilos-daemon-http-errors"
            >
              {summary === null
                ? '—'
                : formatDaemonHttpCount(summary.serverErrors)}
            </div>
            <div className="small text-body-secondary">{'5xx · ' + period}</div>
          </div>
        </div>
        <div className="col">
          <div className="border rounded-3 p-3 h-100">
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-hourglass-split" aria-hidden="true" />
              <span className="small">Slow</span>
            </div>
            <div
              className="fs-5 lh-1 mb-1 text-break"
              data-id="hilos-daemon-http-slow"
            >
              {summary === null ? '—' : formatDaemonHttpCount(summary.slow)}
            </div>
            <div className="small text-body-secondary">
              {'Over 1 s · ' + period}
            </div>
          </div>
        </div>
      </div>
      <HilosViewportTable
        key={nodeId}
        controller={routes.controller}
        cells={{
          [DAEMON_HTTP_ROUTE_PATH_FIELD]: (row) => (
            <>
              <code className="text-break">{formatDaemonHttpRoute(row)}</code>
              {row.agentType !== null && (
                <>
                  <span className="small text-body-secondary ms-1">agent</span>
                  <span className="badge bg-body-tertiary text-body border font-monospace ms-1 text-wrap text-break">
                    {row.agentType}
                  </span>
                </>
              )}
            </>
          ),
          [DAEMON_HTTP_ROUTE_REQUESTS_FIELD]: (row) => (
            <span
              className={row.requests === 0 ? 'text-body-secondary' : undefined}
            >
              {formatDaemonHttpCount(row.requests)}
            </span>
          ),
          [DAEMON_HTTP_ROUTE_ERRORS_FIELD]: (row) => (
            <span
              className={
                row.serverErrors === 0 ? 'text-body-secondary' : undefined
              }
            >
              {formatDaemonHttpCount(row.serverErrors)}
            </span>
          ),
          [DAEMON_HTTP_ROUTE_SLOWEST_FIELD]: (row) => (
            <span
              className={`small ${row.slowestMs === null ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonHttpSlowest(row)}
            </span>
          ),
        }}
        empty={
          <div>
            {emptyState === 'waiting'
              ? waitingMessage
              : emptyState === 'silent'
                ? silentMessage
                : 'Loading…'}
          </div>
        }
      />
    </HilosAdminPage>
  )
}
