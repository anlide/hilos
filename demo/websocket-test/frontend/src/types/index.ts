/**
 * Chat message
 */
export interface ChatMessage {
  id: string
  type: 'message' | 'notification'
  timestamp: number
  username?: string
  content: string
  notificationType?: 'user_joined' | 'user_left' | 'connection_lost' | 'user_renamed'
}

/**
 * WebSocket message from server
 */
export interface WebSocketMessage {
  type: string
  data: unknown
}
