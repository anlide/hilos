import { DomainObject } from '@hilos/sdk/types'

/**
 * User - соответствует структуре User из БД
 * 
 * Поля идентичны структуре из backend/Database/Object/User.php:
 * - id: ?int (в TypeScript: number | null)
 * - name: string
 * - lastActivity: ?string (в TypeScript: string | null)
 */
export class User extends DomainObject {
  id: number | null
  name: string
  lastActivity: string | null

  constructor(
    id: number | null,
    name: string,
    lastActivity: string | null
  ) {
    super()
    this.id = id
    this.name = name
    this.lastActivity = lastActivity
  }

  /**
   * Создать User из объекта данных (например, из JSON)
   */
  static fromObject(data: {
    id?: number | null
    name: string
    lastActivity?: string | null
  }): User {
    return new User(
      data.id ?? null,
      data.name,
      data.lastActivity ?? null
    )
  }
}
