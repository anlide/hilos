import { parseUserPayloads } from '@/entities/parsers'
import { ChatSignalDefinition } from '@/services/signals'
import type { PageCatalogState } from '@hilos/sdk/types/pageCatalog'

/**
 * Handshake payload: server's view of the current user + page catalog.
 * Exactly one user record in entities.full.users (server contract).
 */
export interface HandshakePayload {
  self: { id: number; name: string }
  pageCatalog?: Record<string, unknown> | null
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export const handshakeResponse = ChatSignalDefinition.fromEntitiesEnvelope<
  'handshake_response',
  HandshakePayload
>('handshake_response', (envelope, raw) => {
  const users = parseUserPayloads(envelope.full?.users)
  if (users === null || users.length !== 1) {
    return null
  }
  const self = users[0]!
  const pageCatalog = isRecord(raw.pageCatalog) ? raw.pageCatalog : null
  return {
    self: { id: self.id, name: self.name },
    pageCatalog,
  }
})

// Re-export upstream type for handler callers that set store.pageCatalog.
export type { PageCatalogState }
