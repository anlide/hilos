import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { useConnectionStore, usePageCatalogStore, useHilosLogsStore } from '@hilos/sdk/stores'
import { hasEntities } from '@hilos/sdk/types'
import { createHilosSignalRouter } from '@hilos/sdk/services/hilosSignalHandlers'
import type { VueSignalRouter } from '@hilos/sdk/services/VueSignalRouter'
import type { WebSocketOutcome } from '@hilos/sdk/types/websocket-messages'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { ChatEntitiesReceiver } from '@/entities/ChatEntitiesReceiver'
import { isRecord } from '@/entities/parsers'
import {
  rejectFileUploadPending,
  resolveFileUploadOutcome,
} from '@/services/chatFileUpload'
import {
  handshakeResponse,
  subscriptionPageMain,
  moderationStateUpdate,
  fileModerationStateUpdate,
  fileUploadProgressUpdate,
  fileUploadReady,
  fileUploadRejected,
  fileUploadAborted,
  fileUploadInvalid,
  fileUploadComplete,
  botJoined,
  botLeft,
  botUpdated,
  newEvent,
  subscriptionPageHilosLogs,
  subscriptionPageHilosUser,
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

  signalRouter.on(subscriptionPageMain, ({ moderationState, fileModerationState, fileUploadProgress }) => {
    const chatStore = useChatStore()
    if (moderationState !== undefined) {
      chatStore.setModerationState(moderationState)
    }
    if (fileModerationState !== undefined) {
      chatStore.setFileModerationState(fileModerationState)
    }
    if (fileUploadProgress !== undefined) {
      chatStore.setFileUploadProgress(fileUploadProgress)
    }
  })

  signalRouter.on(moderationStateUpdate, ({ moderationState }) => {
    useChatStore().setModerationState(moderationState)
  })

  signalRouter.on(fileModerationStateUpdate, ({ fileModerationState }) => {
    useChatStore().setFileModerationState(fileModerationState)
  })

  signalRouter.on(fileUploadProgressUpdate, ({ fileUploadProgress }) => {
    useChatStore().setFileUploadProgress(fileUploadProgress)
  })

  signalRouter.on(fileUploadReady, ({ filename, size }) => {
    // Baseline progress bar (0 / size); server throttles file_upload_progress_update from the first binary chunks.
    useChatStore().setFileUploadProgress({
      filename,
      uploadedBytes: 0,
      totalBytes: size,
    })
    resolveFileUploadOutcome({ ok: true })
  })

  signalRouter.on(fileUploadRejected, ({ code, message }) => {
    resolveFileUploadOutcome({ ok: false, code, message })
  })

  signalRouter.on(fileUploadAborted, () => {
    rejectFileUploadPending('aborted')
    useChatStore().setFileUploadProgress(null)
  })

  signalRouter.on(fileUploadInvalid, () => {
    rejectFileUploadPending('invalid')
    useChatStore().setFileUploadProgress(null)
  })

  signalRouter.on(fileUploadComplete, () => {
    // UI state comes from file_moderation_state_update (moderating)
  })

  signalRouter.on(subscriptionPageHilosLogs, (snapshot) => {
    useHilosLogsStore().setHilosLogsOverview(snapshot)
  })

  // The user entity is applied by ChatEntitiesReceiver before this handler runs.
  signalRouter.on(subscriptionPageHilosUser, ({ userId }) => {
    useChatStore().setLastHilosUserSubscribeAckId(userId)
  })

  signalRouter.on(botJoined, () => {})
  signalRouter.on(botLeft, () => {})
  signalRouter.on(botUpdated, () => {})

  signalRouter.on(newEvent, ({ events }) => {
    const chatStore = useChatStore()
    for (const event of events) {
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
      useChatStore().setFileUploadProgress(null)
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

      signalRouter.dispatch(message.type, message.data, message.outcome)
    },
  })
}
