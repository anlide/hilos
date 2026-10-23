import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatMoment } from '../../format/moment.js'
import { type ScopeManager } from '../../state/ScopeManager.js'

/** Shared connection and page scope for the Analytics viewports. */
export interface HilosAnalyticsContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Label for an absent page name or browser description. */
export const ANALYTICS_NO_VALUE = '—'

/**
 * Show a source moment in the reader's local date and time, including seconds.
 *
 * @param ms Source moment in Unix milliseconds.
 */
export function formatAnalyticsTime(ms: number): string {
  return formatMoment(ms, { kind: 'dateTime' }) ?? 'Invalid Date'
}

/**
 * Read the number an Analytics address names — a browser session or a person:
 * a positive safe integer, or null for anything else.
 *
 * @param raw Route parameter as the router decoded it, if present.
 */
export function readAnalyticsRouteId(raw: string | undefined): number | null {
  if (raw === undefined || !/^\+?\d+$/.test(raw)) return null
  const id = Number(raw)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}
