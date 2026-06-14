// The Hilos users table row view-model: the heavy windowed primitive's row shape
// (data-model.md — table-row view-models live in types/tables/). One row composes
// the user entity's profile fields with the live runtime presence the row's
// `connections` slot carries; the selector that builds it lives with the page
// (usersPage.ts), not here.
import { type Presence } from '../Presence'

/** One row of the Hilos users table. */
export interface HilosUserRow {
  /** User id; also the table row key. */
  readonly id: number
  /** Display name (from the `user` entity, so a rename fans out for free). */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
  /** Live connection presence. */
  readonly presence: Presence
  /** Count of the user's currently open sessions. */
  readonly onlineSessionCount: number
}
