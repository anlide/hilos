import { defineStore } from 'pinia'
import { ChatBot, Event, User } from '@/types'
import type { Presence } from '@/types/domain/Presence'

/**
 * Chat-specific store — domain entities and user session state.
 * Connection, table, pageCatalog, and guardian state live in framework stores.
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    events: [] as Event[],
    users: [] as User[],
    bots: [] as ChatBot[],
    currentUserId: null as number | null,
    currentUsername: null as string | null,
    currentUserModerationState: null as string | null,
  }),

  getters: {
    onlineUsers(): User[] {
      return this.users.filter((user) => user.presence === 'online')
    },
    currentUser(): User | null {
      if (this.currentUserId === null) {
        return null
      }
      return this.users.find((user) => user.id === this.currentUserId) ?? null
    },
    isModeratingMessage(): boolean {
      return this.currentUserModerationState !== null
    },
  },

  actions: {
    handleSubscriptionResponse(userId: number, username: string, moderationState?: string | null) {
      this.currentUserId = userId
      this.currentUsername = username
      this.currentUserModerationState = moderationState ?? null
    },

    setModerationState(value: string | null) {
      this.currentUserModerationState = value
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
      const existingIndex = this.users.findIndex(u => u.id === user.id)
      if (existingIndex >= 0) {
        this.users[existingIndex] = user
      } else {
        this.users.push(user)
      }
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

    upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null; presence?: Presence }>) {
      for (const user of users) {
        this.addUser(User.fromObject({
          id: user.id,
          name: user.name,
          lastActivity: user.lastActivity ?? null,
          presence: user.presence ?? 'offline',
          moderationState: null,
        }))
      }
    },

    patchUsers(partials: Array<{ id: number; name?: string; lastActivity?: string | null; presence?: Presence }>) {
      for (const p of partials) {
        const idx = this.users.findIndex((user) => user.id === p.id)
        if (idx >= 0) {
          const existing = this.users[idx]!
          this.users[idx] = User.fromObject({
            id: existing.id,
            name: p.name ?? existing.name,
            sessionToken: existing.sessionToken,
            lastActivity: p.lastActivity ?? existing.lastActivity ?? null,
            presence: p.presence ?? existing.presence,
            moderationState: null,
          })
        } else if (p.name !== undefined) {
          this.addUser(
            User.fromObject({
              id: p.id,
              name: p.name,
              lastActivity: p.lastActivity ?? null,
              presence: p.presence ?? 'offline',
              moderationState: null,
            })
          )
        }
      }
    },

    removeUsers(userIds: number[]) {
      if (userIds.length === 0) {
        return
      }
      const ids = new Set(userIds)
      this.users = this.users.filter(user => user.id === null || !ids.has(user.id))
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
