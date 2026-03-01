import { defineStore } from 'pinia'
import { ChatBot, Event, User } from '@/types'
import type { Presence } from '@/types/domain/Presence'
import { applyTableMutations } from '@hilos/sdk/composables'
import type { TableDataState, TableMutationEntry } from '@hilos/sdk/types'

/**
 * WebSocket chat store - uses base connection store pattern from framework
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    connected: false,
    connecting: false,
    error: null as string | null,
    events: [] as Event[],
    users: [] as User[],
    bots: [] as ChatBot[],
    tableData: {} as Record<string, TableDataState>,
    currentUserId: null as number | null,
    currentUsername: null as string | null,
    currentUserModerationState: null as string | null,
    reconnectAttempts: 0,
    maxReconnectAttempts: Infinity,
  }),
  
  getters: {
    isConnected(): boolean {
      return this.connected
    },
    isConnecting(): boolean {
      return this.connecting
    },
    hasError(): boolean {
      return this.error !== null
    },
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
    setConnected(value: boolean) {
      this.connected = value
      if (value) {
        this.connecting = false
        this.error = null
        this.reconnectAttempts = 0
      }
    },
    
    setConnecting(value: boolean) {
      this.connecting = value
    },
    
    setError(error: string | null) {
      this.error = error
    },
    
    incrementReconnectAttempts() {
      this.reconnectAttempts++
    },
    
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

    // ── Table data ──────────────────────────────────────────────────

    /**
     * Apply tables payload from subscription or refresh response.
     * Payload: { [tableKey]: { rows, totalCount, offset, limit } }
     * Resets mutations for each table key received.
     */
    applyTablesPayload(tables: Record<string, unknown>) {
      if (typeof tables !== 'object' || tables === null) return

      for (const [key, raw] of Object.entries(tables)) {
        if (typeof raw !== 'object' || raw === null) continue
        const r = raw as Record<string, unknown>

        this.tableData = {
          ...this.tableData,
          [key]: {
            rows: Array.isArray(r['rows']) ? r['rows'] as Record<string, unknown>[] : [],
            totalCount: Number(r['totalCount'] ?? 0),
            offset: Number(r['offset'] ?? 0),
            limit: Number(r['limit'] ?? 0),
            mutations: [],
          },
        }
      }
    },

    /**
     * Append a single table mutation (real-time broadcast).
     */
    applyTableMutation(tableKey: string, mutation: TableMutationEntry) {
      const existing = this.tableData[tableKey]
      if (!existing) return

      this.tableData = {
        ...this.tableData,
        [tableKey]: {
          ...existing,
          mutations: [...existing.mutations, mutation],
        },
      }
    },

    /**
     * Apply accumulated pending mutations to the table state.
     * Returns whether any deletes were processed (caller should refresh if so).
     */
    applyPendingMutations(tableKey: string): { hasDeletes: boolean } {
      const existing = this.tableData[tableKey]
      if (!existing || existing.mutations.length === 0) return { hasDeletes: false }

      const result = applyTableMutations(existing)

      this.tableData = {
        ...this.tableData,
        [tableKey]: result.state,
      }

      return { hasDeletes: result.hasDeletes }
    },
  }
})
