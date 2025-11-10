import type { WebSocketOptions } from '../types/websocket'

/**
 * Universal WebSocket service for Vue 3
 * Manages WebSocket connection lifecycle with auto-reconnect and ping support
 */
export class WebSocketService {
  private ws: WebSocket | null = null
  private reconnectTimer: number | null = null
  private pingTimer: number | null = null
  private isReconnecting = false
  private options: Required<Omit<WebSocketOptions, 'url' | 'onOpen' | 'onClose' | 'onError' | 'onMessage'>> & Pick<WebSocketOptions, 'url' | 'onOpen' | 'onClose' | 'onError' | 'onMessage'>
  
  private readonly defaultReconnectDelay = 3000 // 3 seconds
  private readonly defaultPingInterval = 40000 // 40 seconds
  private readonly defaultPingMessage = 'ping'

  constructor(options: WebSocketOptions) {
    this.options = {
      url: options.url,
      autoConnect: options.autoConnect ?? true,
      reconnectDelay: options.reconnectDelay ?? this.defaultReconnectDelay,
      pingInterval: options.pingInterval ?? this.defaultPingInterval,
      pingMessage: options.pingMessage ?? this.defaultPingMessage,
      onOpen: options.onOpen,
      onClose: options.onClose,
      onError: options.onError,
      onMessage: options.onMessage,
    }
  }

  /**
   * Connect to WebSocket server
   */
  connect(): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      return // Already connected
    }

    // Prevent multiple simultaneous connection attempts
    if (this.isReconnecting || (this.ws && this.ws.readyState === WebSocket.CONNECTING)) {
      return
    }

    this.isReconnecting = false

    try {
      this.ws = new WebSocket(this.options.url)
      
      this.ws.onopen = () => {
        this.isReconnecting = false
        
        // Start ping interval
        this.startPingInterval()
        
        // Call custom onOpen callback if provided
        if (this.options.onOpen) {
          this.options.onOpen()
        }
      }
      
      this.ws.onmessage = (event) => {
        try {
          const data = event.data
          if (typeof data === 'string') {
            // Try to parse as JSON
            try {
              const parsed = JSON.parse(data)
              if (this.options.onMessage) {
                this.options.onMessage(parsed)
              }
            } catch (parseError) {
              // Not JSON, pass as string
              if (this.options.onMessage) {
                this.options.onMessage(data)
              }
            }
          } else {
            // Binary or other data
            if (this.options.onMessage) {
              this.options.onMessage(data)
            }
          }
        } catch (error) {
          console.error('Error processing message:', error)
        }
      }
      
      this.ws.onerror = (error) => {
        console.error('WebSocket error:', error)
        if (this.options.onError) {
          this.options.onError(error)
        }
      }
      
      this.ws.onclose = () => {
        // Stop ping interval
        this.stopPingInterval()
        
        // Clean up current connection
        this.ws = null
        
        // Call custom onClose callback if provided
        if (this.options.onClose) {
          this.options.onClose()
        }
        
        // Only reconnect if not already reconnecting
        if (!this.isReconnecting) {
          // Attempt to reconnect
          this.scheduleReconnect()
        }
      }
    } catch (error) {
      console.error('Failed to connect:', error)
      if (this.options.onError) {
        this.options.onError(error as Event)
      }
      this.scheduleReconnect()
    }
  }

  /**
   * Schedule reconnection attempt
   */
  private scheduleReconnect(): void {
    // Prevent multiple simultaneous reconnection attempts
    if (this.isReconnecting) {
      return
    }

    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }

    this.isReconnecting = true
    
    this.reconnectTimer = window.setTimeout(() => {
      this.isReconnecting = false
      this.reconnectTimer = null
      this.connect()
    }, this.options.reconnectDelay)
  }

  /**
   * Send message to server
   */
  send(data: string | object): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      const message = typeof data === 'string' ? data : JSON.stringify(data)
      this.ws.send(message)
    } else {
      console.warn('WebSocket is not connected')
    }
  }

  /**
   * Send WebSocket ping message
   */
  private sendPing(): void {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      return
    }

    try {
      this.ws.send(this.options.pingMessage)
    } catch (error) {
      console.error('Error sending ping:', error)
    }
  }

  /**
   * Start ping interval
   */
  private startPingInterval(): void {
    this.stopPingInterval() // Clear any existing interval
    
    if (this.options.pingInterval > 0) {
      this.pingTimer = window.setInterval(() => {
        this.sendPing()
      }, this.options.pingInterval)
    }
  }

  /**
   * Stop ping interval
   */
  private stopPingInterval(): void {
    if (this.pingTimer !== null) {
      clearInterval(this.pingTimer)
      this.pingTimer = null
    }
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
    
    this.stopPingInterval()
    
    if (this.ws) {
      this.ws.close()
      this.ws = null
    }
  }

  /**
   * Get connection state
   */
  get isConnected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN
  }

  /**
   * Get connection state
   */
  get readyState(): number | null {
    return this.ws?.readyState ?? null
  }
}

