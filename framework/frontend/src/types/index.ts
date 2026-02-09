/**
 * Base types for Hilos framework frontend
 * These types can be extended in demo projects
 */

// Re-export DomainObject abstract class
export { DomainObject } from './DomainObject'

// Re-export websocket options
export type { WebSocketOptions } from './websocket'

// Re-export WebSocket message types
export * from './websocket-messages'

// Entities transport (full/updates/deleted envelope)
export type { EntitiesEnvelope } from './entities'
export { extractEntitiesEnvelope, hasEntities } from './entities'

