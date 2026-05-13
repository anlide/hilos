import type { BrowserPageRow } from '@hilos/sdk/types'
import { type Presence, isPresence } from '@/types/domain/Presence'

export const BROWSER_TABLE_USER_DETAIL = 'userDetail'
export const BROWSER_TABLE_SELF_CONNECTION = 'selfConnection'
export const BROWSER_SOURCE_USERS = 'users'
export const BROWSER_SOURCE_CONNECTIONS = 'connections'

export type BrowserUserDetail = {
  id: number
  name: string
  lastActivity: string | null
  presence: Presence
  onlineSessionCount: number
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const nullableString = (value: unknown): string | null => {
  return typeof value === 'string' ? value : null
}

export const userDetailFromBrowserRow = (
  row: BrowserPageRow | undefined,
): BrowserUserDetail | null => {
  if (row === undefined || !isRecord(row.sources)) {
    return null
  }

  const user = row.sources[BROWSER_SOURCE_USERS]
  if (!isRecord(user) || typeof user.id !== 'number' || typeof user.name !== 'string') {
    return null
  }

  const connection = row.sources[BROWSER_SOURCE_CONNECTIONS]
  const presence = isRecord(connection) && isPresence(connection.presence)
    ? connection.presence
    : 'offline'
  const onlineSessionCount = isRecord(connection) && typeof connection.onlineSessionCount === 'number'
    ? connection.onlineSessionCount
    : 0

  return {
    id: user.id,
    name: user.name,
    lastActivity: nullableString(user.lastActivity),
    presence,
    onlineSessionCount,
  }
}

export const connectionUserIdFromBrowserRow = (
  row: BrowserPageRow | undefined,
): number | null => {
  if (row === undefined || !isRecord(row.sources)) {
    return null
  }

  const connection = row.sources[BROWSER_SOURCE_CONNECTIONS]

  return isRecord(connection) && typeof connection.userId === 'number'
    ? connection.userId
    : null
}
