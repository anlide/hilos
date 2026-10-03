// The chat domain types and their entity collections, re-exported for the page
// selectors. Entities (User/Bot/Event/EventAttachment) carry an id and resolve
// through their collection; presence and the inline event details are plain
// value types projected from a slot. The composer's page-local SelfConnection
// value type lives with the main page module that uses it, not here.
export { type Presence, toPresence } from './Presence'
export { Users } from './User'
export {
  type Session,
  SESSION_TYPE,
  sessionFromFields,
  Sessions,
} from './Session.js'
export {
  type PushSubscription,
  PUSH_SUBSCRIPTION_TYPE,
  pushSubscriptionFromFields,
  PushSubscriptions,
} from './PushSubscription.js'
export { type Bot, BOT_TYPE, botFromFields, Bots } from './Bot'
export {
  type ModeratorPiece,
  MODERATOR_PIECE_TYPE,
  moderatorPieceFromFields,
  ModeratorPieces,
} from './ModeratorPiece'
export {
  type Event,
  type EventAttachment,
  type EventMessage,
  type EventUserRegistration,
  type UserRename,
  EVENT_TYPE,
  EVENT_ATTACHMENT_TYPE,
  eventFromFields,
  eventAttachmentFromFields,
  eventMessageFrom,
  eventRegistrationFrom,
  userRenameFrom,
  Events,
  EventAttachments,
} from './Event'
