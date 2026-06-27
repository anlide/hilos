// The bots admin table row view-model: one bot as the admin table renders it. The
// backend bots table carries the bot entity in the `bots` slot (resolved through
// the Bots collection) and the runtime agent status in the inline
// `botAgentStatuses` slot; the selector that folds them (adminBotsPage.ts) lives
// with the page (data-model.md — table-row view-models live in types/tables/).
import { type HilosPresence } from '@hilos/core'

/** One row of the bots admin table. */
export interface BotRow {
  /** Bot id; also the table row key. */
  readonly id: number
  /** Display name. */
  readonly name: string
  /** Description, or null when unset. */
  readonly description: string | null
  /** Writing style, or null when unset. */
  readonly style: string | null
  /** Preferred topics, or null when unset. */
  readonly topics: string | null
  /** Personality traits, or null when unset. */
  readonly personality: string | null
  /** Whether the bot is active (its agent should run). */
  readonly active: boolean
  /** Live agent presence (from the inline `botAgentStatuses` slot). */
  readonly presence: HilosPresence
}
