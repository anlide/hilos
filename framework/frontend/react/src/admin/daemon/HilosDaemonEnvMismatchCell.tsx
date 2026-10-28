// A node's comparison cell is rendered only through the core's permitted view.
import {
  daemonEnvMismatchCellView,
  daemonEnvMismatchFromExample,
  daemonEnvMismatchStandsOut,
  DAEMON_ENV_MISMATCH_SOURCES_DIFFER,
  type HilosDaemonEnvMismatchRow,
} from '@hilos/core/admin/daemon'
import { HilosDaemonEnvSourceBadge } from './HilosDaemonEnvSourceBadge.js'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'

/** @param props The compared row, addressed node and comparison bucket. */
export function HilosDaemonEnvMismatchCell({
  row,
  nodeId,
  servingNodeId,
}: {
  row: HilosDaemonEnvMismatchRow
  nodeId: string
  servingNodeId: string | null
}) {
  const view = daemonEnvMismatchCellView(row, nodeId, servingNodeId)
  const standsOut =
    row.kind !== DAEMON_ENV_MISMATCH_SOURCES_DIFFER &&
    daemonEnvMismatchStandsOut(row, nodeId)
  const fromExample =
    row.kind === DAEMON_ENV_MISMATCH_SOURCES_DIFFER &&
    daemonEnvMismatchFromExample(row, nodeId)
  return (
    <div
      className={`d-flex flex-column gap-1 ${fromExample ? 'bg-warning-subtle' : ''}`}
      data-id={`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}`}
      data-kind={view.kind}
    >
      {view.kind === 'unknown' ? (
        <>
          <span
            className="text-body-secondary"
            title="Silent or no environment report: unknown, not a match"
          >
            ?
          </span>
          <span className="visually-hidden">
            Silent or no environment report: unknown, not a match
          </span>
        </>
      ) : view.kind === 'undeclared' ? (
        <span className="text-danger-emphasis">{view.text}</span>
      ) : (
        <>
          {view.kind === 'value' ? (
            <HilosDaemonEnvValue view={view.view} />
          ) : view.kind === 'digest' ? (
            <code className="small text-break">{view.text}</code>
          ) : (
            <span>{view.text}</span>
          )}
          <HilosDaemonEnvSourceBadge source={view.source} />
        </>
      )}
      {standsOut && (
        <span
          className="small text-danger-emphasis"
          data-id={`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}-stands-out`}
        >
          <i className="bi bi-exclamation-circle me-1" aria-hidden="true" />
          Differs from the others
        </span>
      )}
      {fromExample && (
        <span
          className="small text-warning-emphasis"
          data-id={`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}-from-example`}
        >
          <i className="bi bi-exclamation-triangle me-1" aria-hidden="true" />
          From .env.example
        </span>
      )}
    </div>
  )
}
