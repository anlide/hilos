import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { useConnectionStore, usePageCatalogStore, useHilosLogsStore } from '@hilos/sdk/stores'
import { extractEntitiesEnvelope, hasEntities } from '@hilos/sdk/types'
import { createHilosSignalRouter } from '@hilos/sdk/services/hilosSignalHandlers'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { ChatEntitiesReceiver } from '@/entities/ChatEntitiesReceiver'
import { eventPayloadToEvent, isRecord, parseEventPayloads } from '@/entities/parsers'
import type { User } from '@/types'
import { parseHilosLogsOverviewPayload } from '@/types/hilosLogsOverview'

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
 * Build the signal router with framework + chat-specific handlers.
 */
function buildSignalRouter() {
  const signalRouter = createHilosSignalRouter()

  signalRouter.on('handshake_response', (data: unknown) => {
    if (!isSubscriptionResponseData(data)) {
      throw new Error('Invalid handshake_response payload')
    }
    if (isRecord(data.pageCatalog)) {
      const pageCatalogStore = usePageCatalogStore()
      pageCatalogStore.setPageCatalog(data.pageCatalog)
    }
    const chatStore = useChatStore()
    const currentUserId = data.userId
    const currentUser = chatStore.users.find((u: User) => u.id === currentUserId)
    const moderationState =
      data.moderationState !== undefined ? data.moderationState : undefined
    chatStore.handleSubscriptionResponse(
      currentUserId,
      currentUser?.name ?? '',
      moderationState as string | null | undefined
    )
  })

  signalRouter.on('moderation_state_update', (data: unknown) => {
    if (data && typeof data === 'object' && 'moderationState' in data) {
      const value = (data as { moderationState: string | null }).moderationState
      const chatStore = useChatStore()
      chatStore.setModerationState(value ?? null)
    }
  })

  signalRouter.on('subscription_page_hilos_logs', (data: unknown) => {
    const parsed = parseHilosLogsOverviewPayload(data)
    if (parsed) {
      const hilosLogsStore = useHilosLogsStore()
      hilosLogsStore.setHilosLogsOverview(parsed)
    }
  })

  signalRouter.on('bot_joined', () => {})
  signalRouter.on('bot_left', () => {})
  signalRouter.on('bot_updated', () => {})

  signalRouter.on('new_event', (data: unknown) => {
    const chatStore = useChatStore()
    const envelope = extractEntitiesEnvelope(data)
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
  })

  return signalRouter
}

/**
 * WebSocket plugin configuration for chat application.
 * Entity changes (full/updates/deleted) are applied via framework EntitiesReceiver.
 */
export function createChatWebSocketPlugin() {
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
  const sessionToken = localStorageService.getSessionWithInit()
  const signalRouter = buildSignalRouter()

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
      const connectionStore = useConnectionStore()
      connectionStore.setConnected(true)
      connectionStore.setConnecting(false)
      connectionStore.setError(null)
    },
    onClose: () => {
      const connectionStore = useConnectionStore()
      connectionStore.setConnected(false)
      connectionStore.setConnecting(false)
    },
    onError: () => {
      const connectionStore = useConnectionStore()
      connectionStore.setError('Connection error occurred')
      connectionStore.setConnecting(false)
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

      signalRouter.dispatch(message.type, message.data)
    },
  })
}
