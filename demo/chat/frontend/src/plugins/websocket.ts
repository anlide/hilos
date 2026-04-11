import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { useConnectionStore, usePageCatalogStore, useHilosLogsStore } from '@hilos/sdk/stores'
import { extractEntitiesEnvelope, hasEntities } from '@hilos/sdk/types'
import { createHilosSignalRouter } from '@hilos/sdk/services/hilosSignalHandlers'
import { config } from '@/config'
import { useChatStore, type FileUploadProgressPayload } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { ChatEntitiesReceiver } from '@/entities/ChatEntitiesReceiver'
import { eventPayloadToEvent, isRecord, parseEventPayloads } from '@/entities/parsers'
import type { User } from '@/types'
import { parseHilosLogsOverviewPayload } from '@/types/hilosLogsOverview'
import {
  rejectFileUploadPending,
  resolveFileUploadOutcome,
} from '@/services/chatFileUpload'

type RawMessage = {
  type: string
  data?: unknown
}

type HandshakeResponseData = {
  userId: number
  moderationState?: string | null
  fileModerationState?: Record<string, unknown> | null
  fileUploadProgress?: unknown
  pageCatalog?: Record<string, unknown>
} & Record<string, unknown>

const parseFileUploadProgress = (raw: unknown): FileUploadProgressPayload | null => {
  if (raw === null || raw === undefined) {
    return null
  }
  if (!isRecord(raw)) {
    return null
  }
  const { filename, uploadedBytes, totalBytes } = raw
  if (typeof filename !== 'string') {
    return null
  }
  if (typeof uploadedBytes !== 'number' || typeof totalBytes !== 'number') {
    return null
  }
  return { filename, uploadedBytes, totalBytes }
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
    const rawFile = data.fileModerationState
    const fileModerationState =
      rawFile !== null && rawFile !== undefined && typeof rawFile === 'object' && !Array.isArray(rawFile)
        ? (rawFile as Record<string, unknown>)
        : null
    const fileUploadProgress = parseFileUploadProgress(data.fileUploadProgress)
    chatStore.handleSubscriptionResponse(
      currentUserId,
      currentUser?.name ?? '',
      moderationState as string | null | undefined,
      fileModerationState,
      fileUploadProgress,
    )
  })

  signalRouter.on('moderation_state_update', (data: unknown) => {
    if (data && typeof data === 'object' && 'moderationState' in data) {
      const value = (data as { moderationState: string | null }).moderationState
      const chatStore = useChatStore()
      chatStore.setModerationState(value ?? null)
    }
  })

  signalRouter.on('file_moderation_state_update', (data: unknown) => {
    if (!isRecord(data) || !('fileModerationState' in data)) {
      return
    }
    const v = (data as { fileModerationState: unknown }).fileModerationState
    const chatStore = useChatStore()
    if (v === null || v === undefined) {
      chatStore.setFileModerationState(null)
    } else if (typeof v === 'object' && !Array.isArray(v)) {
      chatStore.setFileModerationState(v as Record<string, unknown>)
    }
  })

  signalRouter.on('file_upload_progress_update', (data: unknown) => {
    if (!isRecord(data) || !('fileUploadProgress' in data)) {
      return
    }
    const v = (data as { fileUploadProgress: unknown }).fileUploadProgress
    const chatStore = useChatStore()
    if (v === null || v === undefined) {
      chatStore.setFileUploadProgress(null)
      return
    }
    chatStore.setFileUploadProgress(parseFileUploadProgress(v))
  })

  signalRouter.on('file_upload_ready', (data: unknown) => {
    // Baseline progress bar (0 / size); server throttles file_upload_progress_update from the first binary chunks.
    if (isRecord(data)) {
      const filename = data.filename
      const size = data.size
      if (typeof filename === 'string' && typeof size === 'number') {
        useChatStore().setFileUploadProgress({
          filename,
          uploadedBytes: 0,
          totalBytes: size,
        })
      }
    }
    resolveFileUploadOutcome({ ok: true })
  })

  signalRouter.on('file_upload_rejected', (data: unknown) => {
    if (!isRecord(data)) {
      return
    }
    resolveFileUploadOutcome({
      ok: false,
      code: String(data.code ?? ''),
      message: typeof data.message === 'string' ? data.message : undefined,
    })
  })

  signalRouter.on('file_upload_aborted', () => {
    rejectFileUploadPending('aborted')
    useChatStore().setFileUploadProgress(null)
  })

  signalRouter.on('file_upload_invalid', () => {
    rejectFileUploadPending('invalid')
    useChatStore().setFileUploadProgress(null)
  })

  signalRouter.on('file_upload_complete', () => {
    // UI state comes from file_moderation_state_update (moderating)
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

      signalRouter.dispatch(message.type, message.data)
    },
  })
}
