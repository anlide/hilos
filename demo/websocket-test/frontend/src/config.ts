/**
 * Application configuration
 * Values are read from environment variables with VITE_ prefix
 */
export const config = {
  websocketHost: import.meta.env.VITE_WEBSOCKET_HOST || 'localhost',
  websocketPort: parseInt(import.meta.env.VITE_WEBSOCKET_PORT || '8092'),
  websocketProtocol: (import.meta.env.VITE_WEBSOCKET_PROTOCOL || 'ws') as 'ws' | 'wss',
} as const

