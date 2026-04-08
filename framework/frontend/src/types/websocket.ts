/**
 * WebSocket connection options
 */
export interface WebSocketOptions {
  /** WebSocket server URL (protocol://host:port) */
  url: string
  /** Query parameters (GET parameters) to add to WebSocket URL */
  queryParams?: Record<string, string>
  /** Auto-connect on plugin installation */
  autoConnect?: boolean
  /** Reconnection delay in milliseconds */
  reconnectDelay?: number
  /** Ping interval in milliseconds */
  pingInterval?: number
  /** Custom ping message */
  pingMessage?: string
  /** Callback when connection is established */
  onOpen?: () => void
  /** Callback when connection is closed */
  onClose?: () => void
  /** Callback when error occurs */
  onError?: (error: Event) => void
  /** Callback when message is received */
  onMessage?: (data: string | object | Blob | ArrayBuffer) => void
}
