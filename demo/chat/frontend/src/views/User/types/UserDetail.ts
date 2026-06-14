// The user detail page view-model: the referenced user's profile resolved for
// display plus the runtime presence. A view-model, not an entity; the user
// detail selector builds it from the profile entity and the presence slot.
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
}
