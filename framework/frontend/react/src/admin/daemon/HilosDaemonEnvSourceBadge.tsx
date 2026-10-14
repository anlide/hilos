// One source vocabulary for environment cells and the reading ladder.
import { daemonEnvSourceBadge, type HilosDaemonEnvSource } from '@hilos/core'

/** @param props The source reported by the node. */
export function HilosDaemonEnvSourceBadge({
  source,
}: {
  source: HilosDaemonEnvSource
}) {
  const badge = daemonEnvSourceBadge(source)
  return (
    <span
      className={`badge rounded-pill flex-shrink-0 bg-${badge.tone}-subtle text-${badge.tone}-emphasis border border-${badge.tone}-subtle`}
      title={badge.hint}
    >
      {badge.label}
    </span>
  )
}
