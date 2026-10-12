// Page-scoped HTTP summary of one Daemon node; route rows remain in the
// existing server viewport. Counting hours belong to the last server report.

import { z } from 'zod'
import { type HilosDaemonHttpRoutesContext } from './hilosDaemonHttpRoutes.js'
import {
  DAEMON_NODE_STATE_SILENT,
  type HilosDaemonNodeHeading,
} from './hilosDaemonNode.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** Page-data key of the HTTP summary, null until the node reports it. */
export const DAEMON_HTTP_DATA = 'http'

export const daemonHttpSummarySchema = z.strictObject({
  host: z.string(),
  port: z.number().int().min(0).max(65535),
  countingHours: z.number().int().min(0).max(24),
  requests: z.number().int().nonnegative(),
  serverErrors: z.number().int().nonnegative(),
  slow: z.number().int().nonnegative(),
})

/** Listener address and totals over all routes, including unrouted requests. */
export type HilosDaemonHttpSummary = z.infer<typeof daemonHttpSummarySchema>

/** Explanation for a node whose HTTP summary has not arrived. */
export type HilosDaemonHttpEmptyState = 'waiting' | 'silent' | 'none'

/** Connection and page scope shared by the summary and route table. */
export type HilosDaemonHttpContext = HilosDaemonHttpRoutesContext

/**
 * Read the current page's summary, including whole-page replacements.
 *
 * @param scopes The page scope manager.
 */
export function readHilosDaemonHttpSummary(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonHttpSummary | null> {
  const source = scopes.pageDataSignal(DAEMON_HTTP_DATA)
  return computedSignal(() => {
    const parsed = daemonHttpSummarySchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/**
 * Distinguish silence without a report from waiting and known metrics.
 *
 * @param heading The current node heading, if it has arrived.
 * @param http The current HTTP summary, if it has arrived.
 */
export function daemonHttpEmptyState(
  heading: HilosDaemonNodeHeading | null,
  http: HilosDaemonHttpSummary | null,
): HilosDaemonHttpEmptyState {
  if (http !== null) {
    return 'none'
  }
  return heading?.state === DAEMON_NODE_STATE_SILENT ? 'silent' : 'waiting'
}

/**
 * Format the configured listener address without implying an external URL.
 *
 * @param http The reported listener and counters.
 */
export function formatDaemonHttpAddress(http: HilosDaemonHttpSummary): string {
  const host =
    http.host.includes(':') &&
    !(http.host.startsWith('[') && http.host.endsWith(']'))
      ? `[${http.host}]`
      : http.host
  return `${host}:${http.port}`
}

/**
 * Label the counter period at the time of the report, without a browser clock.
 *
 * @param http The reported listener and counters.
 */
export function formatDaemonHttpPeriod(http: HilosDaemonHttpSummary): string {
  if (http.countingHours === 24) {
    return 'Last 24 h'
  }
  return http.countingHours === 0
    ? 'Since start, <1 h'
    : `Since start, ${http.countingHours.toLocaleString()} h`
}
