import { describe, expect, it } from 'vitest'
import { userDetailFromBrowserRow, connectionUserIdFromBrowserRow } from '@/entities/browserUserDetail'

describe('browser user detail rows', () => {
  it('parses user detail rows with runtime connection stats', () => {
    expect(userDetailFromBrowserRow({
      rowKey: 7,
      sources: {
        users: { id: 7, name: 'Ada', lastActivity: null },
        connections: { presence: 'online', onlineSessionCount: 2 },
      },
    })).toEqual({
      id: 7,
      name: 'Ada',
      lastActivity: null,
      presence: 'online',
      onlineSessionCount: 2,
    })
  })

  it('defaults absent runtime source to offline state', () => {
    expect(userDetailFromBrowserRow({
      rowKey: 7,
      sources: {
        users: { id: 7, name: 'Ada', lastActivity: '2026-05-13 10:15:00' },
      },
    })).toEqual({
      id: 7,
      name: 'Ada',
      lastActivity: '2026-05-13 10:15:00',
      presence: 'offline',
      onlineSessionCount: 0,
    })
  })

  it('extracts the current user id from self-connection rows', () => {
    expect(connectionUserIdFromBrowserRow({
      rowKey: 'accept-key',
      sources: {
        connections: { userId: 12 },
      },
    })).toBe(12)
  })

  it('rejects malformed rows', () => {
    expect(userDetailFromBrowserRow(undefined)).toBeNull()
    expect(userDetailFromBrowserRow({ rowKey: 7, sources: {} })).toBeNull()
    expect(userDetailFromBrowserRow({
      rowKey: 7,
      sources: {
        users: { id: '7', name: 'Ada' },
      },
    })).toBeNull()
    expect(connectionUserIdFromBrowserRow({
      rowKey: 'accept-key',
      sources: {
        connections: { userId: '12' },
      },
    })).toBeNull()
  })
})
