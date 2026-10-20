// Shared heading of a node page; all state words come from the core reader.
import { useContext } from 'react'
import {
  daemonNodeDiagramPath,
  formatDaemonNodeState,
  type HilosDaemonNodeHeading,
} from '@hilos/core/admin/daemon'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** Props for the node heading shared by Daemon children. */
interface HilosDaemonNodeLineProps {
  nodeId: string
  heading: HilosDaemonNodeHeading | null
}

/** @param props The route's node id and its reported standing. */
export function HilosDaemonNodeLine({
  nodeId,
  heading,
}: HilosDaemonNodeLineProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonNodeLine requires a provided Hilos router.')
  const diagramPath = heading?.clustered
    ? daemonNodeDiagramPath(router.resolvePath)
    : undefined
  const state = heading?.state
  const stateClass =
    state === 'leader'
      ? 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
      : state === 'standby'
        ? 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle'
        : state === 'data'
          ? 'bg-info-subtle text-info-emphasis border border-info-subtle'
          : state === 'silent'
            ? 'text-bg-danger'
            : 'bg-body-tertiary text-body border'
  const stateIcon =
    state === 'leader'
      ? 'bi-star-fill'
      : state === 'standby'
        ? 'bi-pause-circle'
        : state === 'data'
          ? 'bi-hdd'
          : state === 'silent'
            ? 'bi-plug'
            : null
  return (
    <div
      className="d-flex flex-wrap align-items-center gap-2 mb-3 small"
      data-id="hilos-daemon-node-line"
    >
      <span className="text-body-secondary">Node</span>
      <span
        className="badge bg-body-tertiary text-body border font-monospace"
        data-id="hilos-daemon-node-id"
      >
        {nodeId}
      </span>
      {heading?.clustered ? (
        <>
          {state != null && (
            <span
              className={`badge ${stateClass}`}
              data-state={state}
              data-id="hilos-daemon-node-state"
            >
              {stateIcon && (
                <i className={`bi me-1 ${stateIcon}`} aria-hidden="true" />
              )}
              {formatDaemonNodeState(state)}
            </span>
          )}
          {diagramPath && (
            <HilosLink
              to={diagramPath}
              className="ms-2"
              data-id="hilos-daemon-node-diagram"
            >
              Change on the diagram
            </HilosLink>
          )}
        </>
      ) : (
        heading && (
          <span
            className="badge bg-body-tertiary text-body border"
            data-id="hilos-daemon-node-solo"
          >
            <i className="bi bi-hdd me-1" aria-hidden="true" />
            Single installation
          </span>
        )
      )}
    </div>
  )
}
