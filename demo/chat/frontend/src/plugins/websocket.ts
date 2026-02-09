import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { HANDSHAKE_RESPONSE } from '@/constants'
import { localStorageService } from '@/services/LocalStorageService'
import { Event } from '@/types/domain/Event'

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

type SubscriptionResponseData = {
  entities: EntitiesPayload
  userId: number
}

type EventPayload = {
  id: number
  userId: number | null
  type: string
  timestamp: number | string
  data: Record<string, unknown> | string | null
}

type EntitiesPayload = {
  full?: {
    users?: UserPayload[]
    events?: EventPayload[]
  }
  updates?: {
    users?: UserPayload[]
    events?: EventPayload[]
  }
  deleted?: {
    users?: number[]
    events?: number[]
  }
}

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

const parseUserIds = (value: unknown): number[] | null | undefined => {
  if (value === undefined) {
    return undefined
  }

  if (!Array.isArray(value)) {
    return null
  }

  return value.every((item) => typeof item === 'number') ? value : null
}

const isEventPayload = (value: unknown): value is EventPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.type !== 'string') {
    return false
  }
  if (value.timestamp !== undefined && typeof value.timestamp !== 'number' && typeof value.timestamp !== 'string') {
    return false
  }
  return true
}

const parseEventPayloads = (value: unknown): EventPayload[] | null | undefined => {
  if (value === undefined) {
    return undefined
  }
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isEventPayload) ? value : null
}

const toEntitiesPayload = (data: unknown): EntitiesPayload | null => {
  if (!isRecord(data) || data.entities === undefined) {
    return null
  }

  if (!isRecord(data.entities)) {
    return null
  }

  const entities = data.entities
  const full = isRecord(entities.full) ? entities.full : undefined
  const updates = isRecord(entities.updates) ? entities.updates : undefined
  const deleted = isRecord(entities.deleted) ? entities.deleted : undefined

  const fullUsers = parseUserPayloads(full?.users)
  const updateUsers = parseUserPayloads(updates?.users)
  const deletedUsers = parseUserIds(deleted?.users)
  const fullEvents = parseEventPayloads(full?.events)
  const updateEvents = parseEventPayloads(updates?.events)
  const deletedEventIds = parseUserIds(deleted?.events)

  if (
    fullUsers === null || updateUsers === null || deletedUsers === null
    || fullEvents === null || updateEvents === null || deletedEventIds === null
  ) {
    return null
  }

  return {
    full: fullUsers || fullEvents ? { users: fullUsers ?? undefined, events: fullEvents ?? undefined } : undefined,
    updates: updateUsers || updateEvents ? { users: updateUsers ?? undefined, events: updateEvents ?? undefined } : undefined,
    deleted: deletedUsers || deletedEventIds ? { users: deletedUsers ?? undefined, events: deletedEventIds ?? undefined } : undefined,
  }
}

const applyEntitiesPayload = (entitiesPayload: EntitiesPayload | null, chatStore: ReturnType<typeof useChatStore>) => {
  if (!entitiesPayload) {
    return
  }

  if (entitiesPayload.full?.users) {
    chatStore.upsertUsers(entitiesPayload.full.users)
  }
  if (entitiesPayload.updates?.users) {
    chatStore.upsertUsers(entitiesPayload.updates.users)
  }
  if (entitiesPayload.deleted?.users) {
    chatStore.removeUsers(entitiesPayload.deleted.users)
  }

  if (entitiesPayload.full?.events) {
    chatStore.clearEvents()
    for (const event of entitiesPayload.full.events) {
      const eventData = typeof event.data === 'string' ? (() => { try { return JSON.parse(event.data) } catch { return {} } })() : (event.data ?? {})
      const timestampSeconds = typeof event.timestamp === 'string'
        ? Math.floor(Date.parse(event.timestamp) / 1000)
        : event.timestamp
      const timestampString = Number.isFinite(timestampSeconds)
        ? new Date(timestampSeconds * 1000).toISOString().slice(0, 19).replace('T', ' ')
        : ''
      chatStore.addEvent(Event.fromObject({
        id: event.id,
        userId: event.userId,
        type: event.type,
        timestamp: timestampString,
        data: eventData,
      }))
    }
  }
  if (entitiesPayload.updates?.events) {
    for (const event of entitiesPayload.updates.events) {
      const eventData = typeof event.data === 'string' ? (() => { try { return JSON.parse(event.data) } catch { return {} } })() : (event.data ?? {})
      const timestampSeconds = typeof event.timestamp === 'string'
        ? Math.floor(Date.parse(event.timestamp) / 1000)
        : event.timestamp
      const timestampString = Number.isFinite(timestampSeconds)
        ? new Date(timestampSeconds * 1000).toISOString().slice(0, 19).replace('T', ' ')
        : ''
      chatStore.addEvent(Event.fromObject({
        id: event.id,
        userId: event.userId,
        type: event.type,
        timestamp: timestampString,
        data: eventData,
      }))
    }
  }
  if (entitiesPayload.deleted?.events && entitiesPayload.deleted.events.length > 0) {
    chatStore.removeEventsById(entitiesPayload.deleted.events)
  }
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

  const entitiesPayload = toEntitiesPayload(data)
  return entitiesPayload !== null && typeof data.userId === 'number'
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
        case HANDSHAKE_RESPONSE: {
          if (!isSubscriptionResponseData(message.data)) {
            throw new Error('Invalid handshake_response payload')
          }

          const entitiesPayload = toEntitiesPayload(message.data)
          applyEntitiesPayload(entitiesPayload, chatStore)
          const currentUserId = message.data.userId
          const currentUser = entitiesPayload?.full?.users?.find(u => u.id === currentUserId)
          chatStore.handleSubscriptionResponse(currentUserId, currentUser?.name ?? '')
          return
        }
        case 'subscription_updated': {
          return
        }
        case 'new_event': {
          const entitiesPayload = toEntitiesPayload(message.data)
          if (!entitiesPayload?.updates?.events?.length) {
            throw new Error('Invalid new_event payload: entities.updates.events required')
          }
          applyEntitiesPayload(entitiesPayload, chatStore)

          for (const eventPayload of entitiesPayload.updates.events) {
            const eventData = typeof eventPayload.data === 'string'
              ? (() => { try { return JSON.parse(eventPayload.data) } catch { return {} } })()
              : (eventPayload.data ?? {}) as JsonRecord
            const timestampSeconds = typeof eventPayload.timestamp === 'string'
              ? Math.floor(Date.parse(eventPayload.timestamp) / 1000)
              : eventPayload.timestamp
            const timestampString = Number.isFinite(timestampSeconds)
              ? new Date(timestampSeconds * 1000).toISOString().slice(0, 19).replace('T', ' ')
              : ''
            const event = Event.fromObject({
              id: eventPayload.id,
              userId: eventPayload.userId ?? null,
              type: eventPayload.type,
              timestamp: timestampString,
              data: eventData,
            })

            if (event.type === 'chat_cleared') {
              chatStore.clearEvents()
            }
            if (event.type === 'user_renamed') {
              const newName = typeof event.data.newName === 'string'
                ? event.data.newName
                : (typeof event.data.username === 'string' ? event.data.username : undefined)
              const oldName = event.data.oldName as string | undefined
              if (newName && event.userId !== null) {
                const hasEntityUpdate = Boolean(
                  entitiesPayload.full?.users?.some(u => u.id === event.userId)
                  || entitiesPayload.updates?.users?.some(u => u.id === event.userId)
                )
                if (!hasEntityUpdate) {
                  chatStore.upsertUsers([{ id: event.userId, name: newName }])
                }
                if (event.userId === chatStore.currentUserId || (oldName && oldName === chatStore.currentUsername)) {
                  chatStore.currentUsername = newName
                }
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
  
  return plugin
}
