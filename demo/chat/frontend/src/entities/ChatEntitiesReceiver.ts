import { ChatBot } from '@/types'
import { EntitiesReceiver } from '@hilos/sdk/entities'
import { parseEventPayloads, eventPayloadToEvent, parseBotPayloads } from './parsers'

/** Store interface required for applying entity changes (avoids importing store here). */
interface ChatStoreForEntities {
  upsertBots(bots: ChatBot[]): void
  upsertEvents(events: ReturnType<typeof eventPayloadToEvent>[]): void
  addEvent(event: ReturnType<typeof eventPayloadToEvent>): void
  clearEvents(): void
  removeEventsById(ids: number[]): void
}

/**
 * Chat-specific entities receiver. Public users are handled by ChatFrontendStateReceiver.
 */
export class ChatEntitiesReceiver extends EntitiesReceiver {
  protected override applyFull(
    collectionKey: string,
    rawItems: unknown[],
    context?: unknown,
    replace?: boolean,
  ): void {
    const store = context as ChatStoreForEntities | undefined
    if (!store) return

    if (collectionKey === 'bots') {
      const bots = parseBotPayloads(rawItems)
      if (bots !== null) {
        store.upsertBots(bots.map((b) => ChatBot.fromObject(b)))
      }
      return
    }

    if (collectionKey === 'events') {
      const events = parseEventPayloads(rawItems)
      if (events !== null) {
        const mapped = events.map(eventPayloadToEvent)
        if (replace) {
          store.clearEvents()
          store.upsertEvents(mapped)
        } else {
          store.upsertEvents(mapped)
        }
      }
    }
  }

  protected override applyUpdates(collectionKey: string, rawItems: unknown[], context?: unknown): void {
    const store = context as ChatStoreForEntities | undefined
    if (!store) return

    if (collectionKey === 'bots') {
      const bots = parseBotPayloads(rawItems)
      if (bots !== null) {
        store.upsertBots(bots.map((b) => ChatBot.fromObject(b)))
      }
      return
    }

    if (collectionKey === 'events') {
      const events = parseEventPayloads(rawItems)
      if (events !== null) {
        for (const p of events) {
          store.addEvent(eventPayloadToEvent(p))
        }
      }
    }
  }

  protected override applyDeleted(collectionKey: string, ids: number[], context?: unknown): void {
    const store = context as ChatStoreForEntities | undefined
    if (!store) return

    if (collectionKey === 'events') {
      store.removeEventsById(ids)
    }
  }
}
