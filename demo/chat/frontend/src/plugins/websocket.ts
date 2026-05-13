import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import {
  useBrowserStore,
  useConnectionStore,
  usePageCatalogStore,
  useHilosLogsStore,
} from '@hilos/sdk/stores'
import { extractBrowserPagePayload, hasEntities, hasFrontendChanges } from '@hilos/sdk/types'
import { actionError } from '@hilos/sdk/signals'
import { createHilosSignalRouter } from '@hilos/sdk/services/hilosSignalHandlers'
import type { VueSignalRouter } from '@hilos/sdk/services/VueSignalRouter'
import type { WebSocketOutcome } from '@hilos/sdk/types/websocket-messages'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { ChatEntitiesReceiver } from '@/entities/ChatEntitiesReceiver'
import { ChatFrontendStateReceiver } from '@/entities/ChatFrontendStateReceiver'
import { isRecord } from '@/entities/parsers'
import {
  rejectFileUploadPending,
  resolveFileUploadOutcome,
} from '@/services/chatFileUpload'
import {
  handshakeResponse,
  botJoined,
  botLeft,
  botUpdated,
  newEvent,
  subscriptionPageHilosLogs,
} from '@/signals'

/**
 * Shape of a raw parsed WebSocket envelope: `{type, data?, time?, outcome?}`.
 * `time` is accepted but not yet consumed (reserved for clock-sync in future
 * interactive apps). `outcome` is the action-ack marker — router uses it to
 * dispatch to `<type>::success` / `<type>::fail` handlers.
 */
type RawMessage = {
  type: string
  data?: unknown
  time?: number
  outcome?: WebSocketOutcome
}

const isOutcome = (value: unknown): value is WebSocketOutcome => {
  return value === 'success' || value === 'fail'
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
  const message: RawMessage = {
    type: value.type,
    data: value.data,
  }
  if (typeof value.time === 'number') {
    message.time = value.time
  }
  if (isOutcome(value.outcome)) {
    message.outcome = value.outcome
  }
  return message
}

const entitiesReceiver = new ChatEntitiesReceiver()
const frontendStateReceiver = new ChatFrontendStateReceiver()

/**
 * Module-level reference to the signal router, set by
 * {@link createChatWebSocketPlugin} at app bootstrap.
 *
 * Components can use {@link useSignalRouter} to register additional typed
 * handlers (e.g. per-view action-ack handlers like rename_success/rename_fail)
 * on top of the baseline set wired inside this plugin.
 */
let signalRouterInstance: VueSignalRouter | null = null

/**
 * Access the chat signal router. Throws if the plugin has not been installed yet.
 */
export function useSignalRouter(): VueSignalRouter {
  if (signalRouterInstance === null) {
    throw new Error('Signal router is not available. Make sure createChatWebSocketPlugin() ran.')
  }
  return signalRouterInstance
}

/**
 * Build the signal router with framework + chat-specific typed handlers.
 */
function buildSignalRouter() {
  const signalRouter = createHilosSignalRouter()

  signalRouter.on(handshakeResponse, ({ self, pageCatalog }) => {
    if (pageCatalog !== null && pageCatalog !== undefined) {
      const pageCatalogStore = usePageCatalogStore()
      pageCatalogStore.setPageCatalog(pageCatalog)
    }
    useChatStore().handleSubscriptionResponse(self.id, self.name)
  })

  signalRouter.on(actionError, ({ action, reason }) => {
    const chatStore = useChatStore()
    switch (action) {
      case 'message':
        if (
          chatStore.outboundModerationState?.phase === 'rejected'
          || chatStore.outboundModerationState?.phase === 'unavailable'
        ) {
          break
        }
        chatStore.setMessageError(reason)
        break

      case 'file_upload_init':
        resolveFileUploadOutcome({ ok: false, code: 'action_error', message: reason })
        break

      default:
        console.error(`[Action error] ${action}: ${reason}`)
    }
  })

  signalRouter.on(subscriptionPageHilosLogs, (snapshot) => {
    useHilosLogsStore().setHilosLogsOverview(snapshot)
  })

  signalRouter.on(botJoined, ({ botId }) => {
    useChatStore().setBotPresence(botId, 'online')
  })
  signalRouter.on(botLeft, ({ botId }) => {
    useChatStore().setBotPresence(botId, 'offline')
  })
  signalRouter.on(botUpdated, () => {})

  signalRouter.on(newEvent, ({ events }) => {
    const chatStore = useChatStore()
    for (const event of events) {
      if (event.type === 'user_renamed' || event.type === 'user_renamed_by_admin') {
        const newName = event.eventUserRename?.newName ?? undefined
        const oldName = event.eventUserRename?.oldName ?? undefined
        if (
          newName &&
          (event.eventUserRename?.targetUserId === chatStore.currentUserId ||
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
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}${config.websocketPath}`
  const sessionToken = localStorageService.getSessionWithInit()
  const signalRouter = buildSignalRouter()
  signalRouterInstance = signalRouter

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
      rejectFileUploadPending('disconnected')
      useChatStore().clearSelfConnection()
      const connectionStore = useConnectionStore()
      connectionStore.setConnected(false)
      connectionStore.setConnecting(false)
    },
    onError: () => {
      const connectionStore = useConnectionStore()
      connectionStore.setError('Connection error occurred')
      connectionStore.setConnecting(false)
    },
    onMessage: (data: string | object | Blob | ArrayBuffer) => {
      if (data instanceof Blob || data instanceof ArrayBuffer) {
        return
      }
      const chatStore = useChatStore()
      const message = parseIncomingMessage(data)
      if (!message) {
        throw new Error('Invalid websocket message payload')
      }

      if (hasEntities(message.data)) {
        entitiesReceiver.apply(message.data, chatStore)
      }
      if (hasFrontendChanges(message.data)) {
        frontendStateReceiver.apply(message.data, chatStore)
      }
      const browserPayload = extractBrowserPagePayload(message.data)
      if (browserPayload !== null) {
        useBrowserStore().applyPagePayload(message.type, browserPayload)
        return
      }

      signalRouter.dispatch(message.type, message.data, message.outcome)
    },
  })
}
