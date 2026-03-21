/**
 * Re-export domain types
 */
export * from './domain'
export * from './PageCatalog'

// Explicitly export classes for better TypeScript support
export { ChatBot, Event, User } from './domain'

/**
 * Re-export WebSocket types from framework
 */
export type {
  WebSocketReceiveMessage,
  WebSocketReceiveMessageTime,
  WebSocketReceiveMessageEmpty,
  WebSocketReceiveMessageError,
  WebSocketReceiveMessageData,
  WebSocketMessageEmpty,
  WebSocketMessageError,
  WebSocketMessageData,
  WebSocketMessageWithFields,
  WebSocketMessageTime,
  WebSocketMessageWithDataAndTime,
  WebSocketMessageSuccess,
  WebSocketMessageFail,
  WebSocketMessageProgress,
  WebSocketSendMessage,
  WebSocketConfig,
  WebSocketEventHandler,
  WebSocketCloseHandler,
  WebSocketMessageHandler
} from '@hilos/sdk/types'

// Export function (not type)
export { createWebSocketTypes } from '@hilos/sdk/types'
