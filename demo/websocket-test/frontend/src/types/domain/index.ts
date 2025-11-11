/**
 * Domain types for chat application
 * These classes extend DomainObject from framework
 */

import {ChatMessage, ChatNotification} from "@/types";

export { ChatEventBase } from './ChatEvent'
export { ChatMessage } from './ChatMessage'
export { ChatNotification, type NotificationType } from './ChatNotification'

/**
 * Union type for all chat events
 * Used in stores and components
 */
export type ChatEvent = ChatMessage | ChatNotification

