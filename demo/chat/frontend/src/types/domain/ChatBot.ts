import { DomainObject } from '@hilos/sdk/types'

/**
 * ChatBot - bot as chat participant
 *
 * Minimal fields (id, name) for message display.
 * Full profile fields for bot page.
 */
export class ChatBot extends DomainObject {
  id: number
  name: string
  description: string | null
  personality: string | null
  style: string | null
  topics: string | null
  active: boolean

  constructor(
    id: number,
    name: string,
    description: string | null = null,
    personality: string | null = null,
    style: string | null = null,
    topics: string | null = null,
    active = true
  ) {
    super()
    this.id = id
    this.name = name
    this.description = description
    this.personality = personality
    this.style = style
    this.topics = topics
    this.active = active
  }

  static fromObject(data: {
    id?: number
    name?: string
    description?: string | null
    personality?: string | null
    style?: string | null
    topics?: string | null
    active?: boolean
  }): ChatBot {
    return new ChatBot(
      data.id ?? 0,
      data.name ?? '',
      data.description ?? null,
      data.personality ?? null,
      data.style ?? null,
      data.topics ?? null,
      data.active ?? true
    )
  }
}
