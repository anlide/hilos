import { DomainObject } from '@hilos/sdk/types'

/**
 * Base chat event class
 * Extends abstract DomainObject from framework
 */
export class ChatEventBase extends DomainObject {
  id: string
  timestamp: number
  type: string

  constructor(id: string, timestamp: number, type: string) {
    super()
    this.id = id
    this.timestamp = timestamp
    this.type = type
  }
}

