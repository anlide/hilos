import { defineStore } from 'pinia'

export const useConnectionStore = defineStore('connection', {
  state: () => ({
    connected: false,
    connecting: false,
    error: null as string | null,
    reconnectAttempts: 0,
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
  },
})
