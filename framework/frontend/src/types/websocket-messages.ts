/**
 * WebSocket message types
 * Types for WebSocket communication
 */

/**
 * Base type for WebSocket receive messages
 */
export interface WebSocketReceiveMessage {
  signal: string
  [key: string]: unknown
}

/**
 * Type for WebSocket messages with timestamp
 */
export interface WebSocketReceiveMessageTime extends WebSocketReceiveMessage {
  time?: number
}

/**
 * Type for empty WebSocket messages (only signal)
 */
export interface WebSocketReceiveMessageEmpty<T extends string = string> {
  signal: T
}

/**
 * Type for WebSocket messages with error
 */
export interface WebSocketReceiveMessageError<T extends string = string> {
  signal: T
  reason: string
}

/**
 * Type for WebSocket messages with data
 */
export interface WebSocketReceiveMessageData<T extends string = string, D = unknown> {
  signal: T
  data: D
}

// Utility types for creating message types
export type WebSocketMessageEmpty<T extends string> = WebSocketReceiveMessageEmpty<T>

export type WebSocketMessageError<T extends string> = WebSocketReceiveMessageError<T>

export type WebSocketMessageData<T extends string, D = unknown> = WebSocketReceiveMessageData<T, D>

/**
 * Utility type for creating message type with additional fields
 */
export type WebSocketMessageWithFields<T extends string, F = Record<string, unknown>> = {
  signal: T
} & F

/**
 * Type for WebSocket messages with timestamp
 */
export type WebSocketMessageTime<T extends string = string> = WebSocketReceiveMessageTime & {
  signal: T
}

/**
 * Type for WebSocket messages with data and timestamp
 */
export type WebSocketMessageWithDataAndTime<T extends string = string, D = Record<string, unknown>> = WebSocketReceiveMessageTime & {
  signal: T
} & D

/**
 * Utility functions for quickly creating message types
 */
export const createWebSocketTypes = <T extends string>(signal: T) => ({
  empty: () => ({ signal } as WebSocketMessageEmpty<T>),
  error: () => ({ signal, reason: '' } as WebSocketMessageError<T>),
  withFields: <F extends Record<string, unknown>>(fields: F) => ({ signal, ...fields } as WebSocketMessageWithFields<T, F>),
  withData: <D = unknown>(data: D) => ({ signal, data } as WebSocketMessageData<T, D>),
  withTime: () => ({ signal, time: 0 } as WebSocketMessageTime<T>)
})

// Ready-made types for commonly used patterns
export type WebSocketMessageSuccess<T extends string> = WebSocketMessageEmpty<T>

export type WebSocketMessageFail<T extends string> = WebSocketMessageError<T>

export type WebSocketMessageProgress<T extends string, P = Record<string, unknown>> = WebSocketMessageWithFields<T, P>

/**
 * Types for WebSocket send messages
 */
export interface WebSocketSendMessage {
  action: string
  data: unknown
}

/**
 * WebSocket configuration
 */
export interface WebSocketConfig {
  wsPort: number
  host: string
}

/**
 * Types for event handlers
 */
export type WebSocketEventHandler = (event: Event) => void

export type WebSocketCloseHandler = (event: CloseEvent) => void

export type WebSocketMessageHandler = (event: MessageEvent) => void

