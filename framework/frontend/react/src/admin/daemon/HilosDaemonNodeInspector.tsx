// Presentation of the selected node's last report, without another subscription.
import { useContext, useState, type CSSProperties } from 'react'
import {
  daemonCoresBoundHint,
  daemonEnvironmentAttention,
  daemonEnvironmentDifferences,
  daemonMemoryBoundHint,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonNodeLogPath,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonUptime,
  HilosPages,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
} from '@hilos/core'
import { HilosLink } from '../../HilosLink.js'
import { HilosLongText } from '../../HilosLongText.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** The complete picture and its locally selected node. */
interface HilosDaemonNodeInspectorProps {
  overview: HilosDaemonOverviewData
  node: HilosDaemonOverviewNode
  showLogLinks?: boolean
}

/** @param props The selected node and the picture containing it. */
export function HilosDaemonNodeInspector({
  overview,
  node,
  showLogLinks = true,
}: HilosDaemonNodeInspectorProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosDaemonNodeInspector requires a provided Hilos router.',
    )
  const [noticeOpen, setNoticeOpen] = useState(false)
  const measurements = node.measurements
  const cpuPercent = daemonPercentage(
    measurements?.daemonCores,
    measurements?.cores,
  )
  const memory = daemonTotalMemory(node)
  const memoryPercent = daemonPercentage(memory, measurements?.memoryCeiling)
  const silentSince = formatDaemonNodeSilentSince(
    {
      clustered: overview.clustered,
      state: node.state,
      silentSince: node.silentSince,
    },
    Date.now(),
  )
  const envPath = router.resolvePath(
    HilosPages.DAEMON_ENV,
    daemonNodeChildParams(node.nodeId),
  )
  const mismatchPath = overview.clustered
    ? router.resolvePath(HilosPages.DAEMON_ENV_MISMATCH, {})
    : undefined
  const logPath = daemonNodeLogPath(router.resolvePath, node.nodeId)
  const reportNotice =
    node.state === 'silent'
      ? node.role === null
        ? 'Silent · this node has not reported yet.'
        : `Silent · values from the last report. Last arrival: ${silentSince ?? '—'}.`
      : node.role === null
        ? 'This node has not reported yet.'
        : null
  return (
    <>
      <section
        className="border border-primary-subtle rounded p-3"
        data-id="hilos-daemon-inspector"
        data-node-id={node.nodeId}
      >
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <i className="bi bi-hdd-network" aria-hidden="true" />
          <h2 className="h6 font-monospace text-break mb-0">{node.nodeId}</h2>
          {overview.clustered ? (
            <>
              <span
                className={`badge border ${node.state === 'silent' ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle' : 'bg-body-tertiary text-body'}`}
                data-id="hilos-daemon-inspector-state"
              >
                {node.state === null ? '—' : formatDaemonNodeState(node.state)}
              </span>
              <span className="badge bg-body-tertiary text-body border">
                Role: {node.role ?? '—'}
              </span>
            </>
          ) : (
            <span className="badge bg-body-tertiary text-body border">
              Single installation
            </span>
          )}
          {showLogLinks && logPath && (
            <HilosLink
              to={logPath}
              className="small ms-auto"
              data-id="hilos-daemon-node-log"
            >
              Node log{' '}
              <i className="bi bi-box-arrow-up-right" aria-hidden="true" />
            </HilosLink>
          )}
        </div>
        <div className="hilos-stack mb-3 small text-body-secondary">
          <div
            className="invisible d-flex align-items-center gap-2"
            aria-hidden="true"
            inert
          >
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            <span className="btn btn-link btn-sm p-0 flex-shrink-0">
              <i className="bi bi-info-circle" aria-hidden="true" />
            </span>
          </div>
          <div
            className={`d-flex align-items-center gap-2 ${reportNotice === null ? 'invisible' : ''}`}
            aria-hidden={reportNotice === null ? true : undefined}
            inert={reportNotice === null}
            data-id="hilos-daemon-report-notice"
          >
            <span className="flex-grow-1 text-truncate">
              {reportNotice ?? '\u00a0'}
            </span>
            <button
              type="button"
              className="btn btn-link btn-sm p-0 flex-shrink-0"
              aria-label="Show report details"
              data-id="hilos-daemon-report-details"
              onClick={() => setNoticeOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true" />
            </button>
          </div>
        </div>
        <h3 className="h6">Processor</h3>
        <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Node cores
              </dt>
              <dd className="fs-5 mb-1" data-id="hilos-daemon-cores">
                {formatDaemonCores(measurements?.cores)}
              </dd>
              <dd className="small mb-0">
                {daemonCoresBoundHint(measurements?.coresBound)}
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Daemon CPU
              </dt>
              <dd className="fs-5 mb-1" data-id="hilos-daemon-daemon-cores">
                {formatDaemonCores(measurements?.daemonCores)}{' '}
                <span className="small">cores</span>
              </dd>
              <dd
                className="small mb-0"
                data-id="hilos-daemon-cpu-percent"
              >{`${formatDaemonPercentage(cpuPercent)} of available CPU`}</dd>
              <dd className="mb-0">
                <div
                  className="progress hilos-progress-track mt-2"
                  aria-hidden="true"
                >
                  <div
                    className="progress-bar hilos-progress"
                    style={
                      {
                        '--hilos-progress': daemonProgressWidth(cpuPercent),
                      } as CSSProperties
                    }
                  />
                </div>
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Master CPU
              </dt>
              <dd className="fs-5 mb-1">
                {formatDaemonCores(measurements?.masterCores)}{' '}
                <span className="small">cores</span>
              </dd>
              <dd className="small mb-0">Daemon process without workers</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Machine CPU
              </dt>
              <dd className="fs-5 mb-1">
                {formatDaemonPercentage(measurements?.machineLoad)}
              </dd>
              <dd className="small mb-0">
                Whole machine, including other processes
              </dd>
            </dl>
          </div>
        </div>
        <h3 className="h6">Memory</h3>
        <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">Master</dt>
              <dd className="fs-5 mb-1">
                {formatDaemonBytes(measurements?.masterMemory)}
              </dd>
              <dd className="small mb-0">Daemon process</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">Workers</dt>
              <dd className="fs-5 mb-1">
                {formatDaemonBytes(measurements?.workersMemory)}
              </dd>
              <dd
                className="small mb-0"
                data-id="hilos-daemon-workers-measured"
              >{`${formatDaemonNumber(measurements?.workersMeasured)} of ${formatDaemonNumber(node.workers?.length)} worker processes measured`}</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Measured total
              </dt>
              <dd className="fs-5 mb-1" data-id="hilos-daemon-total-memory">
                {formatDaemonBytes(memory)}
              </dd>
              <dd
                className="small mb-0"
                data-id="hilos-daemon-memory-percent"
              >{`${formatDaemonPercentage(memoryPercent)} of ceiling`}</dd>
              <dd className="mb-0">
                <div
                  className="progress hilos-progress-track mt-2"
                  aria-hidden="true"
                >
                  <div
                    className="progress-bar hilos-progress"
                    style={
                      {
                        '--hilos-progress': daemonProgressWidth(memoryPercent),
                      } as CSSProperties
                    }
                  />
                </div>
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">Ceiling</dt>
              <dd className="fs-5 mb-1">
                {formatDaemonBytes(measurements?.memoryCeiling)}
              </dd>
              <dd className="small mb-0">
                {daemonMemoryBoundHint(measurements?.memoryCeilingBound)}
              </dd>
            </dl>
          </div>
        </div>
        <p className="small text-body-secondary">
          PHP memory limit per process:{' '}
          {formatDaemonBytes(measurements?.phpMemoryLimit)}
        </p>
        <h3 className="h6">Node</h3>
        <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">Uptime</dt>
              <dd className="fs-5 mb-1" data-id="hilos-daemon-uptime">
                {formatDaemonUptime(node.uptimeMinutes)}
              </dd>
              <dd className="small mb-0">At the last report</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Monopolistic
              </dt>
              <dd
                className="fs-5 mb-1"
                data-id="hilos-daemon-monopolistic-count"
              >
                {formatDaemonNumber(daemonMonopolisticCount(node))}
              </dd>
              <dd className="small mb-0">Pool grows on demand</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Worker replacements / 24 h
              </dt>
              <dd className="fs-5 mb-1">
                {formatDaemonNumber(node.workerRestarts24h)}
              </dd>
              <dd className="small mb-0">After unexpected worker loss</dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Sessions on node
              </dt>
              <dd className="fs-5 mb-1">
                {node.role === 'slave'
                  ? '—'
                  : formatDaemonNumber(node.standing?.sessions)}
              </dd>
              <dd
                className="small mb-0"
                data-id="hilos-daemon-inspector-connections"
              >
                {node.role === 'slave'
                  ? 'Does not accept browsers'
                  : `${formatDaemonNumber(node.standing?.connections)} connections`}
              </dd>
            </dl>
          </div>
        </div>
        <h3 className="h6">Environment</h3>
        <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">APP_ENV</dt>
              <dd className="small mb-0 mt-2">
                {envPath ? (
                  <HilosLink
                    to={envPath}
                    data-id="hilos-daemon-environment-link"
                  >
                    On the environment page
                  </HilosLink>
                ) : (
                  <span data-id="hilos-daemon-environment-unbuilt">
                    Environment page is not available yet
                  </span>
                )}
              </dd>
              <dd className="small text-body-secondary mb-0 mt-1">
                Application environment
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">Node ID</dt>
              <dd className="small font-monospace text-break mb-0 mt-2">
                {node.nodeId}
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Requires attention
              </dt>
              <dd
                className="fs-5 mb-1"
                data-id="hilos-daemon-environment-attention"
              >
                {formatDaemonNumber(daemonEnvironmentAttention(node))}
              </dd>
              <dd className="small mb-0">
                Missing, example, disk drift and orphan flags
              </dd>
            </dl>
          </div>
          <div className="col">
            <dl className="bg-body-tertiary rounded p-3 h-100 mb-0">
              <dt className="small text-body-secondary fw-normal">
                Differs from neighbors
              </dt>
              <dd
                className="fs-5 mb-1"
                data-id="hilos-daemon-environment-differences"
              >
                {formatDaemonNumber(
                  daemonEnvironmentDifferences(overview, node),
                )}
              </dd>
              <dd className="small mb-0">
                {overview.clustered
                  ? 'Diverged values and differing sources'
                  : 'No other nodes to compare'}
              </dd>
            </dl>
          </div>
        </div>
        {mismatchPath && (
          <p className="small">
            <HilosLink to={mismatchPath} data-id="hilos-daemon-mismatch-link">
              Environment differences
            </HilosLink>
          </p>
        )}
        <h3 className="h6">Node maintenance</h3>
        <p
          id="hilos-daemon-maintenance-unavailable"
          className="small text-body-secondary"
        >
          Node maintenance is not available yet.
        </p>
        <div
          className="d-flex flex-column flex-md-row flex-wrap gap-2"
          data-id="hilos-daemon-maintenance"
        >
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            disabled
            aria-describedby="hilos-daemon-maintenance-unavailable"
          >
            Drain node
          </button>
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            disabled
            aria-describedby="hilos-daemon-maintenance-unavailable"
          >
            Reload configuration
          </button>
          <button
            type="button"
            className="btn btn-sm btn-outline-danger"
            disabled
            aria-describedby="hilos-daemon-maintenance-unavailable"
          >
            Force disconnect node
          </button>
        </div>
      </section>
      <HilosModal
        open={noticeOpen}
        onClose={() => setNoticeOpen(false)}
        title="Node report"
        initialFocus="dialog"
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <HilosLongText
          kind="prose"
          text={reportNotice ?? ''}
          dataId="hilos-daemon-report-full"
        />
      </HilosModal>
    </>
  )
}
