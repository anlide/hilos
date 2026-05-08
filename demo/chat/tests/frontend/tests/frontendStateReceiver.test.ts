import { describe, expect, it } from 'vitest'
import { ChatFrontendStateReceiver } from '@/entities/ChatFrontendStateReceiver'

const selfConnectionPayload = {
  id: 'self',
  userId: 7,
  connectedAt: 1710000000,
  messageRateLimitSecondsRemaining: 5,
  outboundModerationState: null,
  fileUploadState: null,
  fileUploadProgress: null,
}

describe('ChatFrontendStateReceiver', () => {
  it('applies normalized user state collections', () => {
    const receiver = new ChatFrontendStateReceiver()
    const store = {
      selfConnection: null as null | Omit<typeof selfConnectionPayload, 'id'>,
      users: [] as Array<{ id: number; name: string; lastActivity?: string | null }>,
      presence: [] as Array<{ userId: number; presence: string }>,
      stats: [] as Array<{ userId: number; onlineSessionCount: number }>,
      botPresence: [] as Array<{ botId: number; presence: string }>,
      setSelfConnection(selfConnection: Omit<typeof selfConnectionPayload, 'id'>) {
        this.selfConnection = selfConnection
      },
      upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null }>) {
        this.users = users
      },
      patchUsers() {},
      removeUsers() {},
      upsertUserPresence(items: Array<{ userId: number; presence: string }>) {
        this.presence = items
      },
      removeUserPresence() {},
      upsertUserConnectionStats(items: Array<{ userId: number; onlineSessionCount: number }>) {
        this.stats = items
      },
      removeUserConnectionStats() {},
      upsertBotPresence(items: Array<{ botId: number; presence: string }>) {
        this.botPresence = items
      },
      removeBotPresence() {},
    }

    receiver.apply({
      frontend: {
        full: {
          selfConnection: [selfConnectionPayload],
          users: [{ id: 7, name: 'Ada', lastActivity: null }],
          userPresence: [{ userId: 7, presence: 'online' }],
          userConnectionStats: [{ userId: 7, onlineSessionCount: 2 }],
          botPresence: [{ botId: 9, presence: 'online' }],
        },
      },
    }, store)

    expect(store.selfConnection).toEqual({
      userId: 7,
      connectedAt: 1710000000,
      messageRateLimitSecondsRemaining: 5,
      outboundModerationState: null,
      fileUploadState: null,
      fileUploadProgress: null,
    })
    expect(store.users).toEqual([{ id: 7, name: 'Ada', lastActivity: null }])
    expect(store.presence).toEqual([{ userId: 7, presence: 'online' }])
    expect(store.stats).toEqual([{ userId: 7, onlineSessionCount: 2 }])
    expect(store.botPresence).toEqual([{ botId: 9, presence: 'online' }])
  })

  it('rejects invalid normalized user state payloads', () => {
    const receiver = new ChatFrontendStateReceiver()
    const store = {
      usersCalled: false,
      presenceCalled: false,
      statsCalled: false,
      botPresenceCalled: false,
      selfConnectionCalled: false,
      setSelfConnection() {
        this.selfConnectionCalled = true
      },
      upsertUsers() {
        this.usersCalled = true
      },
      patchUsers() {},
      removeUsers() {},
      upsertUserPresence() {
        this.presenceCalled = true
      },
      removeUserPresence() {},
      upsertUserConnectionStats() {
        this.statsCalled = true
      },
      removeUserConnectionStats() {},
      upsertBotPresence() {
        this.botPresenceCalled = true
      },
      removeBotPresence() {},
    }

    receiver.apply({
      frontend: {
        full: {
          selfConnection: [{ id: 'self', userId: 7 }],
          users: [{ id: 7, name: 'Ada', presence: 'online' }],
          userPresence: [{ userId: 7, presence: 'away' }],
          userConnectionStats: [{ userId: 7, onlineSessionCount: '2' }],
          botPresence: [{ botId: 9, presence: 'away' }],
        },
      },
    }, store)

    expect(store.selfConnectionCalled).toBe(false)
    expect(store.usersCalled).toBe(false)
    expect(store.presenceCalled).toBe(false)
    expect(store.statsCalled).toBe(false)
    expect(store.botPresenceCalled).toBe(false)
  })
})
