import { FrontendStateReceiver } from '@hilos/sdk/entities'
import { parseUserPayloads } from './parsers'
import { parsePartialUserPayloads } from './partialUserPayload'
import {
  parseAttachmentDraftPayloads,
  parseBotPresencePayloads,
  parseSelfConnectionPayloads,
  parseUserConnectionStatsPayloads,
  parseUserPresencePayloads,
  type AttachmentDraftPayload,
  type BotPresencePayload,
  type SelfConnectionPayload,
  type UserConnectionStatsPayload,
  type UserPresencePayload,
} from './frontendStateParsers'

interface ChatStoreForFrontendState {
  setSelfConnection(value: SelfConnectionPayload): void
  upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null }>, replace?: boolean): void
  patchUsers(partials: Array<{ id: number; name?: string; lastActivity?: string | null }>): void
  removeUsers(ids: number[]): void
  upsertUserPresence(items: UserPresencePayload[], replace?: boolean): void
  removeUserPresence(ids: number[]): void
  upsertUserConnectionStats(items: UserConnectionStatsPayload[], replace?: boolean): void
  removeUserConnectionStats(ids: number[]): void
  upsertBotPresence(items: BotPresencePayload[], replace?: boolean): void
  removeBotPresence(ids: number[]): void
  upsertAttachmentDrafts(items: AttachmentDraftPayload[], replace?: boolean): void
  removeAttachmentDraft(draftId: string): void
}

export class ChatFrontendStateReceiver extends FrontendStateReceiver {
  protected override applyFull(
    collectionKey: string,
    rawItems: unknown[],
    context?: unknown,
    replace?: boolean,
  ): void {
    const store = context as ChatStoreForFrontendState | undefined
    if (!store) return

    if (collectionKey === 'selfConnection') {
      const selfConnections = parseSelfConnectionPayloads(rawItems)
      if (selfConnections !== null && selfConnections[0] !== undefined) {
        store.setSelfConnection(selfConnections[0])
      }
      return
    }

    if (collectionKey === 'users') {
      const users = parseUserPayloads(rawItems)
      if (users !== null) {
        store.upsertUsers(users, replace)
      }
      return
    }

    if (collectionKey === 'userPresence') {
      const presence = parseUserPresencePayloads(rawItems)
      if (presence !== null) {
        store.upsertUserPresence(presence, replace)
      }
      return
    }

    if (collectionKey === 'userConnectionStats') {
      const stats = parseUserConnectionStatsPayloads(rawItems)
      if (stats !== null) {
        store.upsertUserConnectionStats(stats, replace)
      }
      return
    }

    if (collectionKey === 'botPresence') {
      const presence = parseBotPresencePayloads(rawItems)
      if (presence !== null) {
        store.upsertBotPresence(presence, replace)
      }
      return
    }

    if (collectionKey === 'attachmentDrafts') {
      const drafts = parseAttachmentDraftPayloads(rawItems)
      if (drafts !== null) {
        store.upsertAttachmentDrafts(drafts, replace)
      }
    }
  }

  protected override applyUpdates(collectionKey: string, rawItems: unknown[], context?: unknown): void {
    const store = context as ChatStoreForFrontendState | undefined
    if (!store) return

    if (collectionKey === 'selfConnection') {
      const selfConnections = parseSelfConnectionPayloads(rawItems)
      if (selfConnections !== null && selfConnections[0] !== undefined) {
        store.setSelfConnection(selfConnections[0])
      }
      return
    }

    if (collectionKey === 'users') {
      const users = parseUserPayloads(rawItems)
      if (users !== null) {
        store.upsertUsers(users)
      } else {
        const partials = parsePartialUserPayloads(rawItems)
        if (partials !== null) {
          store.patchUsers(partials)
        }
      }
      return
    }

    if (collectionKey === 'userPresence') {
      const presence = parseUserPresencePayloads(rawItems)
      if (presence !== null) {
        store.upsertUserPresence(presence)
      }
      return
    }

    if (collectionKey === 'userConnectionStats') {
      const stats = parseUserConnectionStatsPayloads(rawItems)
      if (stats !== null) {
        store.upsertUserConnectionStats(stats)
      }
      return
    }

    if (collectionKey === 'botPresence') {
      const presence = parseBotPresencePayloads(rawItems)
      if (presence !== null) {
        store.upsertBotPresence(presence)
      }
      return
    }

    if (collectionKey === 'attachmentDrafts') {
      const drafts = parseAttachmentDraftPayloads(rawItems)
      if (drafts !== null) {
        store.upsertAttachmentDrafts(drafts)
      }
    }
  }

  protected override applyDeleted(collectionKey: string, ids: number[], context?: unknown): void {
    const store = context as ChatStoreForFrontendState | undefined
    if (!store) return

    if (collectionKey === 'users') {
      store.removeUsers(ids)
    } else if (collectionKey === 'userPresence') {
      store.removeUserPresence(ids)
    } else if (collectionKey === 'userConnectionStats') {
      store.removeUserConnectionStats(ids)
    } else if (collectionKey === 'botPresence') {
      store.removeBotPresence(ids)
    } else if (collectionKey === 'attachmentDrafts') {
      for (const id of ids) {
        store.removeAttachmentDraft(String(id))
      }
    }
  }
}
