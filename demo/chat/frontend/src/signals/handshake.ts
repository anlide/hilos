import { SignalDefinition } from '@hilos/sdk/services/signals'
import type { PageCatalogState } from '@hilos/sdk/types/pageCatalog'

/**
 * Handshake payload: server's view of the current user + page catalog.
 * User display state is delivered by browser page subscriptions.
 */
export interface HandshakePayload {
  self: { id: number }
  pageCatalog?: Record<string, unknown> | null
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export const handshakeResponse = new SignalDefinition<
  'handshake_response',
  HandshakePayload
>('handshake_response', (raw) => {
  if (!isRecord(raw) || !isRecord(raw.self)) {
    return null
  }

  const { id } = raw.self
  if (typeof id !== 'number' || !Number.isInteger(id) || id <= 0) {
    return null
  }

  const pageCatalog = isRecord(raw.pageCatalog) ? raw.pageCatalog : null
  return {
    self: { id },
    pageCatalog,
  }
})

// Re-export upstream type for handler callers that set store.pageCatalog.
export type { PageCatalogState }
