import { defineStore } from 'pinia'
import { ChatBot, Event, User } from '@/types'
import type { Presence } from '@/types/domain/Presence'
import type {
  UserConnectionStatsPayload,
  UserPresencePayload,
} from '@/entities/frontendStateParsers'

export type UserViewModel = User & {
  presence: Presence
  onlineSessionCount: number
}

/**
 * Chat-specific store — domain entities and user session state.
 * Connection, table, pageCatalog, and guardian state live in framework stores.
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    events: [] as Event[],
    usersById: {} as Record<number, User>,
    userPresenceById: {} as Record<number, UserPresencePayload>,
    userConnectionStatsById: {} as Record<number, UserConnectionStatsPayload>,
    bots: [] as ChatBot[],
    currentUserId: null as number | null,
    messageError: null as string | null,
  }),

  getters: {
    users(): User[] {
      return Object.values(this.usersById).sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
    },
    userViewModels(): UserViewModel[] {
      return this.users.map((user) => {
        const userId = user.id ?? 0
        return {
          ...user,
          presence: this.userPresenceById[userId]?.presence ?? 'offline',
          onlineSessionCount: this.userConnectionStatsById[userId]?.onlineSessionCount ?? 0,
        }
      })
    },
    onlineUsers(): UserViewModel[] {
      return this.userViewModels.filter((user) => user.presence === 'online')
    },
    currentUser(): UserViewModel | null {
      if (this.currentUserId === null) {
        return null
      }
      return this.userViewModels.find((user) => user.id === this.currentUserId) ?? null
    },
  },

  actions: {
    handleSubscriptionResponse(userId: number) {
      this.currentUserId = userId
    },

    setMessageError(value: string | null) {
      this.messageError = value
    },

    addEvent(event: Event) {
      this.events.push(event)
      if (this.events.length > 1000) {
        this.events.shift()
      }
    },

    upsertEvents(events: Event[]) {
      for (const event of events) {
        const id = event.id
        if (id === null) {
          this.addEvent(event)
          continue
        }
        const existingIndex = this.events.findIndex((ev) => ev.id === id)
        if (existingIndex >= 0) {
          this.events[existingIndex] = event
        } else {
          this.addEvent(event)
        }
      }
    },

    addUser(user: User) {
      if (user.id === null) {
        return
      }
      this.usersById[user.id] = user
    },

    upsertBots(bots: ChatBot[]) {
      for (const bot of bots) {
        const existingIndex = this.bots.findIndex((b) => b.id === bot.id)
        if (existingIndex >= 0) {
          this.bots[existingIndex] = bot
        } else {
          this.bots.push(bot)
        }
      }
    },

    upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null }>, replace = false) {
      if (replace) {
        this.usersById = {}
      }
      for (const user of users) {
        this.addUser(User.fromObject({
          id: user.id,
          name: user.name,
          lastActivity: user.lastActivity ?? null,
        }))
      }
    },

    patchUsers(partials: Array<{ id: number; name?: string; lastActivity?: string | null }>) {
      for (const p of partials) {
        const existing = this.usersById[p.id]
        if (existing !== undefined) {
          this.usersById[p.id] = User.fromObject({
            id: existing.id,
            name: p.name ?? existing.name,
            lastActivity: p.lastActivity ?? existing.lastActivity ?? null,
          })
        } else if (p.name !== undefined) {
          this.addUser(
            User.fromObject({
              id: p.id,
              name: p.name,
              lastActivity: p.lastActivity ?? null,
            })
          )
        }
      }
    },

    removeUsers(userIds: number[]) {
      if (userIds.length === 0) {
        return
      }
      for (const id of userIds) {
        delete this.usersById[id]
        delete this.userPresenceById[id]
        delete this.userConnectionStatsById[id]
      }
    },

    upsertUserPresence(items: UserPresencePayload[], replace = false) {
      if (replace) {
        this.userPresenceById = {}
      }
      for (const item of items) {
        this.userPresenceById[item.userId] = item
      }
    },

    removeUserPresence(userIds: number[]) {
      for (const id of userIds) {
        delete this.userPresenceById[id]
      }
    },

    upsertUserConnectionStats(items: UserConnectionStatsPayload[], replace = false) {
      if (replace) {
        this.userConnectionStatsById = {}
      }
      for (const item of items) {
        this.userConnectionStatsById[item.userId] = item
      }
    },

    removeUserConnectionStats(userIds: number[]) {
      for (const id of userIds) {
        delete this.userConnectionStatsById[id]
      }
    },

    clearEvents() {
      this.events = []
    },

    removeEventsById(eventIds: number[]) {
      if (eventIds.length === 0) {
        return
      }
      const ids = new Set(eventIds)
      this.events = this.events.filter(ev => ev.id === null || !ids.has(ev.id))
    },

  }
})
