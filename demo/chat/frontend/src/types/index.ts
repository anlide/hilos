/**
 * Re-export domain types
 */
export * from './domain'
export * from './PageCatalog'

// Explicitly export classes for better TypeScript support
export { ChatBot, Event, User } from './domain'

/**
 * Re-export WebSocket transport types from framework.
 *
 * Historical note: earlier iterations re-exported a dozen flat-protocol
 * message types that never matched this project's actual `{type, data, time?,
 * outcome?}` wire shape. The modern set is intentionally small — envelope
 * types plus action-ack payload shapes — and the whole module is re-exported
 * below so downstream imports don't need to know internal filenames.
 */
export type {
  WebSocketIncoming,
  WebSocketIncomingEmpty,
  WebSocketTimeSync,
  WebSocketActionSuccess,
  WebSocketActionFail,
  WebSocketActionMessage,
  WebSocketPageSubscribeMessage,
  WebSocketOutcome,
  ActionFailData,
} from '@hilos/sdk/types/websocket-messages'
