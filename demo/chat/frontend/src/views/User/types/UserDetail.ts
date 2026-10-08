// The user detail page view-model: the referenced user's profile resolved for
// display plus the runtime presence, and whether the account was folded into
// another one (HIL-1292). A view-model, not an entity; the user detail selector
// builds it from the profile entity, the presence slot and the merge slot.
import { type Presence } from '../../../types/Presence'

/** The chat user detail shown on the user page. */
export interface UserDetail {
  /** The user's display name. */
  readonly name: string
  /** The last activity timestamp, empty when never recorded. */
  readonly lastActivity: string
  /** The user's connection presence. */
  readonly presence: Presence
  /** The number of the user's active online sessions. */
  readonly onlineSessionCount: number
  /** Whether the account was folded into another one by a merge (HIL-1292). */
  readonly merged: boolean
  /** The account it went into, down the chain to its live end, or null when none is left to point at. */
  readonly mergedInto: number | null
  /** That account's name, or null when there is none to name. */
  readonly mergedIntoName: string | null
}
