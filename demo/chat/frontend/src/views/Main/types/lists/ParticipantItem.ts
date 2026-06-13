// A participant of the main page roster — the view-model of one `mainUsers`
// list item: the referenced user's resolved name plus the inline connection
// presence. A view-model, not an entity (it bears no id of its own); the page
// roster selector builds it from the list item's slots.
import { type Presence } from '../../../../types/Presence'

/** One participant of the main page roster. */
export interface ParticipantItem {
  /** The list item key (the user id), stable for keyed rendering. */
  readonly key: string
  /** The user's display name, resolved from the referenced entity. */
  readonly name: string
  /** The user's connection presence. */
  readonly presence: Presence
}
