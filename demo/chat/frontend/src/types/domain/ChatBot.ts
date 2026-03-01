import { DomainObject } from '@hilos/sdk/types'

/**
 * ChatBot - bot as chat participant (minimal fields for display)
 *
 * Used in chat store for resolving bot names in messages.
 */
export class ChatBot extends DomainObject {
  id: number
  name: string

  constructor(id: number, name: string) {
    super()
    this.id = id
    this.name = name
  }

  static fromObject(data: { id?: number; name?: string }): ChatBot {
    return new ChatBot(
      data.id ?? 0,
      data.name ?? ''
    )
  }
}
