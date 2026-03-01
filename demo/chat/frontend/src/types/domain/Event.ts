import { DomainObject } from '@hilos/sdk/types'

/**
 * Event - matches Event structure from database
 *
 * Fields are identical to backend/Database/Object/Event.php:
 * - id: ?int (in TypeScript: number | null)
 * - userId: ?int (in TypeScript: number | null) - null for system/bot events
 * - botId: ?int (in TypeScript: number | null) - null for system/user events
 * - type: string
 * - timestamp: string
 *
 * Additional field for UI:
 * - data: Record<string, unknown> - additional event data (message, oldName, newName, etc.)
 */
export class Event extends DomainObject {
  id: number | null
  userId: number | null
  botId: number | null
  type: string
  timestamp: string
  data: Record<string, unknown>

  constructor(
    id: number | null,
    userId: number | null,
    botId: number | null,
    type: string,
    timestamp: string,
    data: Record<string, unknown> = {}
  ) {
    super()
    this.id = id
    this.userId = userId
    this.botId = botId
    this.type = type
    this.timestamp = timestamp
    this.data = data
  }

  /**
   * Create Event from data object (e.g., from JSON)
   */
  static fromObject(data: {
    id?: number | null
    userId?: number | null
    botId?: number | null
    type: string
    timestamp: string
    data?: Record<string, unknown>
  }): Event {
    return new Event(
      data.id ?? null,
      data.userId ?? null,
      data.botId ?? null,
      data.type,
      data.timestamp,
      data.data ?? {}
    )
  }
}
