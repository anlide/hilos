/**
 * Application configuration
 * Values are read from environment variables with VITE_ prefix
 */
export const config = {
  websocketHost: import.meta.env.VITE_WEBSOCKET_HOST || 'localhost',
  websocketPort: parseInt(import.meta.env.VITE_WEBSOCKET_PORT || '8092'),
  websocketProtocol: (import.meta.env.VITE_WEBSOCKET_PROTOCOL || 'ws') as 'ws' | 'wss',
  /** Browser-facing daemon HTTP base (status routes + /chat/attachment downloads); not WebSocket */
  httpStatusHost: import.meta.env.VITE_HTTP_STATUS_HOST || import.meta.env.VITE_WEBSOCKET_HOST || 'localhost',
  httpStatusPort: parseInt(import.meta.env.VITE_HTTP_STATUS_PORT || '8090', 10),
  httpStatusProtocol: (import.meta.env.VITE_HTTP_STATUS_PROTOCOL || 'http') as 'http' | 'https',
} as const
