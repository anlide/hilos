import { DomainObject } from '@hilos/sdk/types'
import type { Presence } from '@/types'

/**
 * User - соответствует структуре User из БД
 * 
 * Поля идентичны структуре из backend/Database/Object/User.php:
 * - id: ?int (в TypeScript: number | null)
 * - name: string
 * - sessionToken: ?string (в TypeScript: string | null)
 * - lastActivity: ?string (в TypeScript: string | null)
 */
export class User extends DomainObject {
  id: number | null
  name: string
  sessionToken: string | null
  lastActivity: string | null
  presence: Presence
  moderationState: string | null

  constructor(
    id: number | null,
    name: string,
    sessionToken: string | null,
    lastActivity: string | null,
    presence: Presence,
    moderationState: string | null
  ) {
    super()
    this.id = id
    this.name = name
    this.sessionToken = sessionToken
    this.lastActivity = lastActivity
    this.presence = presence
    this.moderationState = moderationState
  }

  /**
   * Создать User из объекта данных (например, из JSON)
   */
  static fromObject(data: {
    id?: number | null
    name: string
    sessionToken?: string | null
    lastActivity?: string | null
    presence?: Presence
    moderationState?: string | null
  }): User {
    return new User(
      data.id ?? null,
      data.name,
      data.sessionToken ?? null,
      data.lastActivity ?? null,
      data.presence ?? 'offline',
      data.moderationState ?? null
    )
  }
}
