// Shared node heading for the node-specific Daemon pages.

import { z } from 'zod'
import { formatDaemonCronMoment } from './hilosDaemonCron.js'
import { type HilosPathResolver } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** Page-data key of the node heading. */
export const DAEMON_NODE_DATA = 'node'

export const DAEMON_NODE_STATE_LEADER = 'leader'
export const DAEMON_NODE_STATE_STANDBY = 'standby'
export const DAEMON_NODE_STATE_DATA = 'data'
export const DAEMON_NODE_STATE_SILENT = 'silent'

export const daemonNodeHeadingSchema = z.strictObject({
  clustered: z.boolean(),
  state: z.string().nullable(),
  silentSince: z.number().int().nullable(),
})

export type HilosDaemonNodeHeading = z.infer<typeof daemonNodeHeadingSchema>

/**
 * Read the current page's node heading, including whole-page resends.
 *
 * @param scopes The page scope manager.
 */
export function readHilosDaemonNodeHeading(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonNodeHeading | null> {
  const source = scopes.pageDataSignal(DAEMON_NODE_DATA)
  return computedSignal(() => {
    const parsed = daemonNodeHeadingSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/** @param state State word from the cluster picture. */
export function formatDaemonNodeState(state: string): string {
  switch (state) {
    case DAEMON_NODE_STATE_LEADER:
      return 'Leader'
    case DAEMON_NODE_STATE_STANDBY:
      return 'Standby'
    case DAEMON_NODE_STATE_DATA:
      return 'Data'
    case DAEMON_NODE_STATE_SILENT:
      return 'Silent'
    default:
      return state
  }
}

/**
 * Format the last report's arrival only while the selected node is silent.
 *
 * @param heading The current node heading, if it has arrived.
 * @param nowMs Current browser time in milliseconds.
 */
export function formatDaemonNodeSilentSince(
  heading: HilosDaemonNodeHeading | null,
  nowMs: number,
): string | null {
  return heading?.state === DAEMON_NODE_STATE_SILENT &&
    heading.silentSince !== null
    ? formatDaemonCronMoment(heading.silentSince, nowMs)
    : null
}

/** @param resolvePath The application's page path resolver. */
export function daemonNodeDiagramPath(
  resolvePath: HilosPathResolver,
): string | undefined {
  return resolvePath(HilosPages.DAEMON, {})
}
