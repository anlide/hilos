import {createWebSocketPlugin} from '@hilos/sdk/plugins/websocket'
import {extractEntitiesEnvelope, hasEntities} from '@hilos/sdk/types'
import {config} from '@/config'
import {useChatStore} from '@/stores'
import {
  BOT_JOINED,
  BOT_LEFT,
  BOT_UPDATED,
  HANDSHAKE_RESPONSE,
  MODERATION_STATE_UPDATE,
  SUBSCRIPTION_PAGE_MAIN,
  SUBSCRIPTION_PAGE_PROFILE,
  SUBSCRIPTION_PAGE_ADMIN,
  SUBSCRIPTION_PAGE_ADMIN_USERS,
  SUBSCRIPTION_PAGE_ADMIN_BOTS,
  SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
  SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
  SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT,
  SUBSCRIPTION_PAGE_HILOS_SETTINGS,
  SUBSCRIPTION_PAGE_BOT,
  SUBSCRIPTION_PAGE_USER,
  TABLE_DATA,
  TABLE_MUTATION,
  TABLE_ACTION_ERROR,
} from '@/constants'
import {localStorageService} from '@/services/LocalStorageService'
import {ChatEntitiesReceiver} from '@/entities/ChatEntitiesReceiver'
import {eventPayloadToEvent, isRecord, parseEventPayloads} from '@/entities/parsers'
import type { User } from '@/types'
import type { TableMutationEntry } from '@hilos/sdk/types'

type RawMessage = {
  type: string
  data?: unknown
}

type HandshakeResponseData = {
  userId: number
  moderationState?: string | null
  pageCatalog?: Record<string, unknown>
} & Record<string, unknown>

const parseIncomingMessage = (data: string | object): RawMessage | null => {
  if (typeof data === 'string') {
    try {
      const parsed = JSON.parse(data)
      return toRawMessage(parsed)
    } catch {
      return null
    }
  }
  return toRawMessage(data)
}

const toRawMessage = (value: unknown): RawMessage | null => {
  if (!isRecord(value) || typeof value.type !== 'string') {
    return null
  }
  return {
    type: value.type,
    data: value.data,
  }
}

const isSubscriptionResponseData = (data: unknown): data is HandshakeResponseData => {
  return isRecord(data) && typeof data.userId === 'number' && hasEntities(data)
}

const entitiesReceiver = new ChatEntitiesReceiver()

/**
 * WebSocket plugin configuration for chat application.
 * Entity changes (full/updates/deleted) are applied via framework EntitiesReceiver.
 */
export function createChatWebSocketPlugin() {
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`

  const sessionToken = localStorageService.getSessionWithInit()

  return createWebSocketPlugin({
    url: websocketUrl,
    queryParams: {
      'Hilos-Session-Token': sessionToken,
    },
    autoConnect: true,
    reconnectDelay: 3000,
    pingInterval: 40000,
    pingMessage: 'ping',
    onOpen: () => {
      const chatStore = useChatStore()
      chatStore.setConnected(true)
      chatStore.setConnecting(false)
      chatStore.setError(null)
    },
    onClose: () => {
      const chatStore = useChatStore()
      chatStore.setConnected(false)
      chatStore.setConnecting(false)
    },
    onError: () => {
      const chatStore = useChatStore()
      chatStore.setError('Connection error occurred')
      chatStore.setConnecting(false)
    },
    onMessage: (data: string | object) => {
      const chatStore = useChatStore()

      const message = parseIncomingMessage(data)
      if (!message) {
        throw new Error('Invalid websocket message payload')
      }

      if (hasEntities(message.data)) {
        entitiesReceiver.apply(message.data, chatStore)
      }

      switch (message.type) {
        case HANDSHAKE_RESPONSE: {
          if (!isSubscriptionResponseData(message.data)) {
            throw new Error('Invalid handshake_response payload')
          }
          if (isRecord(message.data.pageCatalog)) {
            chatStore.setPageCatalog(message.data.pageCatalog)
          }
          const currentUserId = message.data.userId
          const currentUser = chatStore.users.find((u: User) => u.id === currentUserId)
          const moderationState =
            message.data.moderationState !== undefined ? message.data.moderationState : undefined
          chatStore.handleSubscriptionResponse(
            currentUserId,
            currentUser?.name ?? '',
            moderationState as string | null | undefined
          )
          return
        }
        case MODERATION_STATE_UPDATE: {
          if (message.data && typeof message.data === 'object' && 'moderationState' in message.data) {
            const value = (message.data as { moderationState: string | null }).moderationState
            chatStore.setModerationState(value ?? null)
          }
          return
        }
        case SUBSCRIPTION_PAGE_MAIN: {
          return
        }
        case SUBSCRIPTION_PAGE_PROFILE: {
          return
        }
        case SUBSCRIPTION_PAGE_USER:
        case SUBSCRIPTION_PAGE_BOT: {
          return
        }
        case SUBSCRIPTION_PAGE_ADMIN: {
          return
        }
        case SUBSCRIPTION_PAGE_ADMIN_USERS:
        case SUBSCRIPTION_PAGE_ADMIN_BOTS:
        case SUBSCRIPTION_PAGE_ADMIN_MODERATOR:
        case SUBSCRIPTION_PAGE_HILOS_GUARDIAN:
        case SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT:
        case SUBSCRIPTION_PAGE_HILOS_SETTINGS: {
          if (message.data && typeof message.data === 'object' && 'tables' in message.data && message.data.tables) {
            chatStore.applyTablesPayload(message.data.tables as Record<string, unknown>)
          }
          return
        }
        case TABLE_DATA: {
          if (message.data && typeof message.data === 'object' && 'tables' in message.data && message.data.tables) {
            const tables = message.data.tables as Record<string, unknown>
            chatStore.applyTablesPayload(tables)
            for (const key of Object.keys(tables)) {
              chatStore.completeTableRefreshForKey(key)
            }
          }
          return
        }
        case TABLE_MUTATION: {
          if (message.data && typeof message.data === 'object') {
            const d = message.data as Record<string, unknown>
            const tableKey = d['tableKey'] as string | undefined
            const mutation = d['mutation'] as TableMutationEntry | undefined
            if (tableKey && mutation) {
              chatStore.applyTableMutation(tableKey, mutation)
            }
          }
          return
        }
        case TABLE_ACTION_ERROR: {
          if (message.data && typeof message.data === 'object') {
            const d = message.data as Record<string, unknown>
            const tableKeyErr = d['tableKey'] as string | undefined
            if (tableKeyErr) {
              chatStore.completeTableRefreshForKey(tableKeyErr)
            }
            const msg = d['message'] as string | undefined
            if (msg) {
              console.error(`[Table action error] ${d['tableKey'] ?? ''}: ${msg}`)
            }
          }
          return
        }
        case 'subscription_updated': {
          return
        }
        case BOT_JOINED:
        case BOT_LEFT:
        case BOT_UPDATED: {
          return
        }
        case 'new_event': {
          const envelope = extractEntitiesEnvelope(message.data)
          const fromUpdates = parseEventPayloads(envelope?.updates?.events) ?? []
          const fromFull = parseEventPayloads(envelope?.full?.events) ?? []
          const eventPayloads = fromUpdates.length > 0 ? fromUpdates : fromFull

          for (const eventPayload of eventPayloads) {
            const event = eventPayloadToEvent(eventPayload)
            if (event.type === 'user_renamed' || event.type === 'user_renamed_by_admin') {
              const newName =
                typeof event.data.newName === 'string'
                  ? event.data.newName
                  : typeof event.data.username === 'string'
                    ? event.data.username
                    : undefined
              const oldName = event.data.oldName as string | undefined
              if (
                newName &&
                (event.userId === chatStore.currentUserId ||
                  (oldName && oldName === chatStore.currentUsername))
              ) {
                chatStore.currentUsername = newName
              }
            }
          }
          return
        }
        default: {
          throw new Error(`Unhandled websocket message type: ${message.type}`)
        }
      }
    },
  })
}
