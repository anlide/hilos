type WebSocketSender = {
  send: (data: string | object) => void
}

/**
 * Send a WebSocket action message.
 * Protocol: { type: 'action', action: '<name>', data: {...} }
 */
export const sendAction = (
  websocket: WebSocketSender,
  action: string,
  data: Record<string, unknown> = {},
): void => {
  websocket.send({
    type: 'action',
    action,
    data,
  })
}
