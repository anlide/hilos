import { ChatEventBase } from '@/types'

/**
 * Chat message class
 * Extends ChatEventBase which extends DomainObject from framework
 */
export class ChatMessage extends ChatEventBase {
  username: string
  content: string

  constructor(id: string, timestamp: number, username: string, content: string) {
    super(id, timestamp, 'message')
    this.username = username
    this.content = content
  }
}

