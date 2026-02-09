import { defineStore } from 'pinia'
import { Event, User } from '@/types'

/**
 * WebSocket chat store - uses base connection store pattern from framework
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    // Inherit base connection state
    connected: false,
    connecting: false,
    error: null as string | null,
    // Events from database (Event)
    events: [] as Event[],
    // Users from database (User)
    users: [] as User[],
    // Current user information
    currentUserId: null as number | null,
    currentUsername: null as string | null,
    reconnectAttempts: 0,
    maxReconnectAttempts: Infinity,
  }),
  
  getters: {
    // Inherit base getters
    isConnected(): boolean {
      return this.connected
    },
    isConnecting(): boolean {
      return this.connecting
    },
    hasError(): boolean {
      return this.error !== null
    },
  },
  
  actions: {
    // Inherit base actions
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
    
    // Demo-specific actions
    
    incrementReconnectAttempts() {
      this.reconnectAttempts++
    },
    
    /**
     * Handle handshake_response (set current user; events/users already applied via entities)
     */
    handleSubscriptionResponse(userId: number, username: string) {
      this.currentUserId = userId
      this.currentUsername = username
    },
    
    /**
     * Add Event from database to store
     */
    addEvent(event: Event) {
      this.events.push(event)
      // Keep last 1000 events
      if (this.events.length > 1000) {
        this.events.shift()
      }
    },

    /**
     * Add or update events by id. Events in the list overwrite existing by id;
     * events already in the store but not in the list are left unchanged.
     */
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
    
    /**
     * Add User from database to store
     */
    addUser(user: User) {
      // Update existing user or add new one
      const existingIndex = this.users.findIndex(u => u.id === user.id)
      if (existingIndex >= 0) {
        this.users[existingIndex] = user
      } else {
        this.users.push(user)
      }
    },

    /**
     * Add or update multiple users
     */
    upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null }>) {
      for (const user of users) {
        this.addUser(User.fromObject({
          id: user.id,
          name: user.name,
          lastActivity: user.lastActivity ?? null,
        }))
      }
    },

    /**
     * Remove users by id
     */
    removeUsers(userIds: number[]) {
      if (userIds.length === 0) {
        return
      }
      const ids = new Set(userIds)
      this.users = this.users.filter(user => user.id === null || !ids.has(user.id))
    },
    
    /**
     * Clear events from store
     */
    clearEvents() {
      this.events = []
    },

    /**
     * Remove events by id (e.g. from entities.deleted.events)
     */
    removeEventsById(eventIds: number[]) {
      if (eventIds.length === 0) {
        return
      }
      const ids = new Set(eventIds)
      this.events = this.events.filter(ev => ev.id === null || !ids.has(ev.id))
    },
  }
})
