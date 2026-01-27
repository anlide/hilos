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
     * Handle subscription_response
     */
    handleSubscriptionResponse(
      events: Array<{
        id: number
        userId: number | null
        type: string
        timestamp: number
        data: Record<string, unknown> | string | null
      }>,
      userId: number,
      username: string,
    ) {
      this.currentUserId = userId
      this.currentUsername = username
      
      this.clearEvents()
      
      for (const event of events) {
        const eventData = typeof event.data === 'string'
          ? JSON.parse(event.data)
          : (event.data ?? {})

        const timestampSeconds = typeof event.timestamp === 'string'
          ? Math.floor(Date.parse(event.timestamp) / 1000)
          : event.timestamp

        if (!Number.isFinite(timestampSeconds)) {
          throw new Error(`Invalid event timestamp: ${String(event.timestamp)}`)
        }

        const timestampString = new Date(timestampSeconds * 1000)
          .toISOString()
          .slice(0, 19)
          .replace('T', ' ')
        
        const eventObj = Event.fromObject({
          id: event.id,
          userId: event.userId,
          type: event.type,
          timestamp: timestampString,
          data: eventData,
        })
        
        this.addEvent(eventObj)
      }
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
     * Clear events from database
     */
    clearEvents() {
      this.events = []
    },
  }
})
