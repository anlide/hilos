import { EntitiesReceiver } from '@hilos/sdk/entities'
import { parseUserPayloads, parseEventPayloads, eventPayloadToEvent } from './parsers'
import { parsePartialUserPayloads } from './partialUserPayload'

/** Store interface required for applying entity changes (avoids importing store here). */
interface ChatStoreForEntities {
  upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null; presence?: 'online' | 'offline' }>): void
  patchUsers(partials: Array<{ id: number; name?: string; lastActivity?: string | null; presence?: 'online' | 'offline' }>): void
  upsertEvents(events: ReturnType<typeof eventPayloadToEvent>[]): void
  addEvent(event: ReturnType<typeof eventPayloadToEvent>): void
  clearEvents(): void
  removeEventsById(ids: number[]): void
  removeUsers(ids: number[]): void
}

/**
 * Chat-specific entities receiver. Parses users/events from transport and applies to chat store.
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

    if (collectionKey === 'users') {
      const users = parseUserPayloads(rawItems)
      if (users !== null) {
        store.upsertUsers(users)
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

    if (collectionKey === 'users') {
      const partials = parsePartialUserPayloads(rawItems)
      if (partials !== null) {
        store.patchUsers(partials)
      } else {
        const users = parseUserPayloads(rawItems)
        if (users !== null) {
          store.upsertUsers(users)
        }
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

    if (collectionKey === 'users') {
      store.removeUsers(ids)
    } else if (collectionKey === 'events') {
      store.removeEventsById(ids)
    }
  }
}
