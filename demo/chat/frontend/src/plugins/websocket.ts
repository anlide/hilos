import {createWebSocketPlugin} from '@hilos/sdk/plugins/websocket'
import {extractEntitiesEnvelope, hasEntities} from '@hilos/sdk/types'
import {config} from '@/config'
import {useChatStore} from '@/stores'
import {HANDSHAKE_RESPONSE, SUBSCRIPTION_PAGE_MAIN, SUBSCRIPTION_PAGE_PROFILE} from '@/constants'
import {localStorageService} from '@/services/LocalStorageService'
import {ChatEntitiesReceiver} from '@/entities/ChatEntitiesReceiver'
import {eventPayloadToEvent, isRecord, parseEventPayloads} from '@/entities/parsers'

type RawMessage = {
  type: string
  data?: unknown
}

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

const isSubscriptionResponseData = (data: unknown): data is { userId: number } & Record<string, unknown> => {
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
      'X-Session-Token': sessionToken,
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
          const currentUserId = message.data.userId
          const currentUser = chatStore.users.find((u) => u.id === currentUserId)
          chatStore.handleSubscriptionResponse(currentUserId, currentUser?.name ?? '')
          return
        }
        case SUBSCRIPTION_PAGE_MAIN: {
          // Entities (events + users) already applied above via hasEntities
          return
        }
        case SUBSCRIPTION_PAGE_PROFILE: {
          // Empty subscription response; entities already applied above if present
          return
        }
        case 'subscription_updated': {
          return
        }
        case 'new_event': {
          const envelope = extractEntitiesEnvelope(message.data)
          const fromUpdates = parseEventPayloads(envelope?.updates?.events) ?? []
          const fromFull = parseEventPayloads(envelope?.full?.events) ?? []
          const eventPayloads = fromUpdates.length > 0 ? fromUpdates : fromFull

          for (const eventPayload of eventPayloads) {
            const event = eventPayloadToEvent(eventPayload)
            if (event.type === 'user_renamed') {
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
