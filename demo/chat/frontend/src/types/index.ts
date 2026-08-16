// The chat domain types and their entity collections, re-exported for the page
// selectors. Entities (User/Bot/Event/EventAttachment) carry an id and resolve
// through their collection; presence and the inline event details are plain
// value types projected from a slot. The composer's page-local SelfConnection
// value type lives with the main page module that uses it, not here.
export { type Presence, toPresence } from './Presence'
export { type User, USER_TYPE, userFromFields, Users } from './User'
export {
  type Identity,
  IDENTITY_TYPE,
  identityFromFields,
  Identities,
} from './Identity'
export {
  type PasskeyCredential,
  PASSKEY_CREDENTIAL_TYPE,
  passkeyCredentialFromFields,
  PasskeyCredentials,
} from './PasskeyCredential'
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
