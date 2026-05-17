import { defineStore } from 'pinia'
import { ChatBot, Event } from '@/types'

/**
 * Chat-specific store — domain entities and user session state.
 * Connection, table, pageCatalog, and guardian state live in framework stores.
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    events: [] as Event[],
    bots: [] as ChatBot[],
    currentUserId: null as number | null,
    messageError: null as string | null,
  }),

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
