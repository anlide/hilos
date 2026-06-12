// The chat domain types and their entity collections, re-exported for the page
// selectors. Entities (User/Bot/Event/EventAttachment) carry an id and resolve
// through their collection; presence and the inline event details are plain
// value types projected from a slot.
export { type Presence, toPresence } from './Presence'
export { type User, USER_TYPE, userFromFields, Users } from './User'
export { type Bot, BOT_TYPE, botFromFields, Bots } from './Bot'
export {
  type Event,
  type EventAttachment,
  type EventMessage,
  type EventUserRegistration,
  type EventUserRename,
  EVENT_TYPE,
  EVENT_ATTACHMENT_TYPE,
  eventFromFields,
  eventAttachmentFromFields,
  eventMessageFrom,
  eventRegistrationFrom,
  eventRenameFrom,
  Events,
  EventAttachments,
} from './Event'
