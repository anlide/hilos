/**
 * Domain types for chat application
 * These classes extend DomainObject from framework
 */

export { ChatBot } from './ChatBot'
export { Event, type EventAttachment } from './Event'
export { User } from './User'
export type { Presence } from './Presence'
export { PRESENCE_VALUES, isPresence } from './Presence'
export type { BotEntity } from './Bot'
export type { ModeratorPromptPieceEntity, ModeratorPromptPieceSection } from './ModeratorPromptPiece'

