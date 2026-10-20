// The existing cron picture in its reported order, with no search or sorting.
import { useContext, useEffect, useMemo } from 'react'
import {
  createHilosDaemonCronTable,
  DAEMON_CRON_NAME_FIELD,
  DAEMON_CRON_AGENT_FIELD,
  DAEMON_CRON_EXPRESSION_FIELD,
  DAEMON_CRON_LAST_RUN_FIELD,
  DAEMON_CRON_NEXT_RUN_FIELD,
  daemonCronEmptyState,
  daemonCronHoldsIdleNotice,
  daemonCronIdleNotice,
  formatDaemonCronExecutor,
  formatDaemonCronLastRun,
  formatDaemonCronNextRun,
  formatDaemonNodeSilentSince,
  readDaemonCronReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonCronContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeLine } from './HilosDaemonNodeLine.js'

/** Props for the framework's cron page. */
export interface HilosDaemonCronPageProps {
  context: HilosDaemonCronContext
}

/** @param props The project's connection and page scope. */
export function HilosDaemonCronPage({ context }: HilosDaemonCronPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonCronPage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  const cron = useMemo(
    () => createHilosDaemonCronTable(context, nodeId),
    [context, nodeId],
  )
  const heading = useSignal(
    useMemo(() => readHilosDaemonNodeHeading(context.scopes), [context]),
  )
  const reported = useSignal(
    useMemo(() => readDaemonCronReported(context.scopes), [context]),
  )
  const rows = useSignal(cron.controller.rows)
  const rules = rows.flatMap(({ row }) => (row === null ? [] : [row]))
  const emptyState = daemonCronEmptyState(heading, reported)
  const notice = daemonCronIdleNotice(rules, heading)
  const holdsNotice = daemonCronHoldsIdleNotice(rules, heading)
  const silentSince = formatDaemonNodeSilentSince(heading, Date.now())
  const clustered = heading?.clustered === true
  useEffect(() => {
    cron.start()
    return () => cron.dispose()
  }, [cron])
  return (
    <HilosAdminPage page={HilosPages.DAEMON_CRON}>
      <HilosDaemonNodeLine nodeId={nodeId} heading={heading} />
      <div className="position-relative">
        <p className="small text-body-secondary invisible" aria-hidden="true">
          This node has been silent since September 30, 2026, 11:59 PM. The
          rules below are from its last report.
        </p>
        {silentSince !== null && rows.length > 0 && (
          <p
            className="small text-body-secondary position-absolute top-0 start-0 w-100"
            data-id="hilos-daemon-cron-silent"
          >
            This node has been silent since {silentSince}. The rules below are
            from its last report.
          </p>
        )}
      </div>
      {holdsNotice && (
        <div className="position-relative">
          <div
            className="alert alert-warning d-flex align-items-start gap-3 py-2 small invisible"
            aria-hidden="true"
          >
            <i className="bi bi-pause-circle fs-5" aria-hidden="true" />
            <div>
              Daemon rules do not run on this node: only the leader runs them.
              They will start here if leadership moves to this node.
            </div>
          </div>
          {notice !== 'none' && (
            <div
              className="alert alert-warning d-flex align-items-start gap-3 py-2 small position-absolute top-0 start-0 w-100"
              data-id="hilos-daemon-cron-not-leader"
              data-notice={notice}
            >
              <i className="bi bi-pause-circle fs-5" aria-hidden="true" />
              <div>
                Daemon rules do not run on this node: only the leader runs them.
                {notice === 'idle_until_leader' &&
                  ' They will start here if leadership moves to this node.'}
              </div>
            </div>
          )}
        </div>
      )}
      <HilosViewportTable
        controller={cron.controller}
        cells={{
          [DAEMON_CRON_NAME_FIELD]: (row) => (
            <code className="fw-semibold text-break">{row.name}</code>
          ),
          [DAEMON_CRON_AGENT_FIELD]: (row) => (
            <span
              className={`badge bg-body-tertiary text-body border text-wrap text-break ${row.agentId === null ? '' : 'font-monospace'}`}
              data-id={`hilos-daemon-cron-executor-${row.rowKey}`}
            >
              {row.agentId === null && (
                <i className="bi bi-cpu me-1" aria-hidden="true" />
              )}
              {formatDaemonCronExecutor(row, clustered)}
            </span>
          ),
          [DAEMON_CRON_EXPRESSION_FIELD]: (row) => (
            <code>{row.expression}</code>
          ),
          [DAEMON_CRON_LAST_RUN_FIELD]: (row) => (
            <span
              className={`small ${row.idleReason !== null || row.lastRunAt === null ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonCronLastRun(row, Date.now())}
            </span>
          ),
          [DAEMON_CRON_NEXT_RUN_FIELD]: (row) => (
            <span
              className={`small ${row.idleReason !== null || row.nextRunAt === null ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonCronNextRun(row, Date.now())}
            </span>
          ),
        }}
        empty={
          emptyState === 'waiting' ? (
            <div data-id="hilos-daemon-cron-empty-waiting">
              <div className="fw-semibold">
                The node has not reported its schedule yet
              </div>
              <p className="mb-0">
                Nothing has arrived from this node yet — not zero rules.
              </p>
            </div>
          ) : emptyState === 'silent' ? (
            <div data-id="hilos-daemon-cron-empty-silent">
              <div className="fw-semibold">
                The node is silent and never reported its schedule
              </div>
              <p className="mb-0">
                Its rules will appear when it reports again.
              </p>
            </div>
          ) : (
            <div data-id="hilos-daemon-cron-empty-none">
              <div className="fw-semibold">The node reports no cron rules</div>
            </div>
          )
        }
      />
    </HilosAdminPage>
  )
}
