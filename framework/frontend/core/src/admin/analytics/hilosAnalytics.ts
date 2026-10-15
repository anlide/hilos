import { type HilosConnection } from '../../connection/HilosConnection.js'
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
  return new Date(ms).toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
  })
}
