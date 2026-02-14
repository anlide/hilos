// Note: Pinia types are resolved by demo projects via tsconfig.app.json
// IDE may show errors here, but TypeScript compiler in demo projects will resolve them correctly
// @ts-expect-error - Pinia types are provided by demo project's node_modules
import { defineStore } from 'pinia'

/**
 * Base store interface for connection state
 * Can be extended in demo projects
 */
export interface BaseConnectionState {
  connected: boolean
  connecting: boolean
  error: string | null
}

/**
 * Base connection store
 * Provides common connection management functionality
 * Can be extended in demo projects
 */
export function createBaseConnectionStore(storeId: string) {
  return defineStore(storeId, {
    state: (): BaseConnectionState => ({
      connected: false,
      connecting: false,
      error: null,
    }),

    getters: {
      isConnected: (state: BaseConnectionState) => state.connected,
      isConnecting: (state: BaseConnectionState) => state.connecting,
      hasError: (state: BaseConnectionState) => state.error !== null,
    },

    actions: {
      setConnected(value: boolean): void {
        // @ts-expect-error - Pinia provides this context, but TypeScript can't infer it here
        this.connected = value
        if (value) {
          // @ts-expect-error - Pinia provides this context, but TypeScript can't infer it here
          this.connecting = false
          // @ts-expect-error - Pinia provides this context, but TypeScript can't infer it here
          this.error = null
        }
      },

      setConnecting(value: boolean): void {
        // @ts-expect-error - Pinia provides this context, but TypeScript can't infer it here
        this.connecting = value
      },

      setError(error: string | null): void {
        // @ts-expect-error - Pinia provides this context, but TypeScript can't infer it here
        this.error = error
      },
    }
  })
}
