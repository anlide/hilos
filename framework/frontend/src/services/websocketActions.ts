import type { HilosActionMap } from '../types/actionMap'

type WebSocketSender = {
  send: (data: string | object) => void
}

/**
 * Send a WebSocket action message.
 *
 * Protocol: `{ type: 'action', action: '<name>', data: {...} }`.
 *
 * Both `action` and `data` are type-checked against {@link HilosActionMap}.
 * Downstream projects augment the map via TypeScript declaration merging
 * (see demo/chat/frontend/src/types/actionMap.ts).
 */
export const sendAction = <K extends keyof HilosActionMap>(
  websocket: WebSocketSender,
  action: K,
  data: HilosActionMap[K],
): void => {
  websocket.send({
    type: 'action',
    action,
    data,
  })
}
