import { ChatEventBase } from '@/types'

/**
 * Notification types
 */
export type NotificationType = 'user_joined' | 'user_left' | 'connection_lost' | 'user_renamed'

/**
 * Chat notification class
 * Extends ChatEventBase which extends DomainObject from framework
 */
export class ChatNotification extends ChatEventBase {
  content: string
  notificationType: NotificationType

  constructor(id: string, timestamp: number, content: string, notificationType: NotificationType) {
    super(id, timestamp, 'notification')
    this.content = content
    this.notificationType = notificationType
  }
}

