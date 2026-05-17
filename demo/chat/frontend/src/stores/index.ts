import { defineStore } from 'pinia'

/**
 * Chat-specific store for session-local UI state.
 * Browser rows, connection, table, pageCatalog, and guardian state live in framework stores.
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
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
  }
})
