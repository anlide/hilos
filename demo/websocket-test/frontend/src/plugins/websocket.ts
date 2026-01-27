import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { Event } from '@/types'

type JsonRecord = Record<string, unknown>

type UserPayload = {
  id: number
  name: string
  lastActivity?: string | null
}

type RawMessage = {
  type: string
  data?: unknown
}

type SubscriptionEvent = {
  id: number
  userId: number | null
  type: string
  timestamp: number
  data: Record<string, unknown> | string | null
}

type SubscriptionResponseData = {
  events: SubscriptionEvent[]
  users: UserPayload[]
  userId: number
  username: string
}

type EventUsersData = {
  users?: UserPayload[]
}

type ChatEventDataByType = {
  chat_started: EventUsersData
  chat_stopped: EventUsersData
  chat_cleared: EventUsersData
  user_joined: { userId: number } & EventUsersData
  user_left: { userId: number } & EventUsersData
  user_renamed: { userId: number; username: string } & EventUsersData
  message_sent: { userId: number; message: string } & EventUsersData
}

type KnownChatEventPayload = {
  [K in keyof ChatEventDataByType]: {
    id: number
    type: K
    timestamp: number
    data: ChatEventDataByType[K]
  }
}[keyof ChatEventDataByType]

type UnknownChatEventPayload = {
  id: number
  type: string
  timestamp: number
  data: JsonRecord
}

type ChatEventPayload = KnownChatEventPayload | UnknownChatEventPayload

const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isUserPayload = (value: unknown): value is UserPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.name !== 'string') {
    return false
  }

  if (value.lastActivity === undefined || value.lastActivity === null) {
    return true
  }

  return typeof value.lastActivity === 'string'
}

const parseUserPayloads = (value: unknown): UserPayload[] | null | undefined => {
  if (value === undefined) {
    return undefined
  }

  if (!Array.isArray(value)) {
    return null
  }

  return value.every(isUserPayload) ? value : null
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

const isSubscriptionResponseData = (data: unknown): data is SubscriptionResponseData => {
  if (!isRecord(data)) {
    return false
  }

  const users = parseUserPayloads(data.users)

  return Array.isArray(data.events)
    && users !== null
    && users !== undefined
    && typeof data.userId === 'number'
    && typeof data.username === 'string'
}

const toChatEventPayload = (data: unknown): ChatEventPayload | null => {
  if (!isRecord(data)) {
    return null
  }

  const { id, type, timestamp } = data
  if (typeof id !== 'number' || typeof type !== 'string' || typeof timestamp !== 'number') {
    return null
  }

  const payloadData = isRecord(data.data) ? data.data : {}
  const users = parseUserPayloads(payloadData.users)
  if (users === null) {
    return null
  }

  const normalizedPayloadData = users === undefined
    ? payloadData
    : { ...payloadData, users }
  const base = { id, type, timestamp, data: normalizedPayloadData }

  switch (type) {
    case 'user_joined':
    case 'user_left':
      if (typeof payloadData.userId === 'number') {
        return base as ChatEventPayload
      }
      break
    case 'message_sent':
      if (typeof payloadData.userId === 'number' && typeof payloadData.message === 'string') {
        return base as ChatEventPayload
      }
      break
    case 'user_renamed':
      if (typeof payloadData.userId === 'number' && typeof payloadData.username === 'string') {
        return base as ChatEventPayload
      }
      break
    case 'chat_started':
    case 'chat_stopped':
    case 'chat_cleared':
      return base as ChatEventPayload
    default:
      return base
  }

  return base
}

/**
 * WebSocket plugin configuration for chat application
 * This is a project-specific implementation of the universal WebSocket plugin
 */
export function createChatWebSocketPlugin() {
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
  
  // Get session token with initialization (always returns non-null)
  const sessionToken = localStorageService.getSessionWithInit()
  
  const plugin = createWebSocketPlugin({
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

      switch (message.type) {
        case 'subscription_response': {
          if (!isSubscriptionResponseData(message.data)) {
            throw new Error('Invalid subscription_response payload')
          }

          chatStore.upsertUsers(message.data.users)
          chatStore.handleSubscriptionResponse(
            message.data.events,
            message.data.userId,
            message.data.username,
          )
          return
        }
        case 'new_event': {
          const eventData = toChatEventPayload(message.data)
          if (!eventData) {
            throw new Error('Invalid new_event payload')
          }

          let eventPayloadData: JsonRecord = eventData.data
          switch (eventData.type) {
            case 'user_joined':
            case 'user_left':
              eventPayloadData = { ...eventPayloadData, userId: eventData.data.userId }
              break
            case 'message_sent':
              eventPayloadData = {
                ...eventPayloadData,
                userId: eventData.data.userId,
                message: eventData.data.message,
              }
              break
            case 'user_renamed':
              eventPayloadData = {
                ...eventPayloadData,
                userId: eventData.data.userId,
                username: eventData.data.username,
              }
              break
            default:
              break
          }

          if (Array.isArray(eventPayloadData.users)) {
            chatStore.upsertUsers(eventPayloadData.users as UserPayload[])
          }

          const userId = (eventPayloadData.userId as number | null) ?? chatStore.currentUserId
          const timestampString = new Date(eventData.timestamp * 1000).toISOString().slice(0, 19).replace('T', ' ')

          const event = Event.fromObject({
            id: eventData.id,
            userId: userId,
            type: eventData.type,
            timestamp: timestampString,
            data: eventPayloadData,
          })

          if (event.type === 'chat_cleared') {
            chatStore.clearEvents()
          }
          chatStore.addEvent(event)
          return
        }
        default: {
          throw new Error(`Unhandled websocket message type: ${message.type}`)
        }
      }
    },
  })
  
  return plugin
}
