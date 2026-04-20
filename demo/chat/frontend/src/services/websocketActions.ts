import { MESSAGE_ACTION_FIELD, MESSAGE_ACTION_TYPE, MESSAGE_DATA_FIELD } from '@/constants'
import type { HilosActionMap } from '@hilos/sdk/types/actionMap'
// Side-effect import: declaration-merges chat actions into HilosActionMap.
import '@/types/actionMap'

export type WebSocketSender = {
  send: (data: string | object) => void
  sendBinary?: (data: ArrayBuffer | Blob) => void
}

/**
 * Send a typed WebSocket action message.
 *
 * Both `action` and `data` are checked against the augmented
 * {@link HilosActionMap} (framework entries + demo/chat augmentation from
 * `@/types/actionMap`). Unknown action names or mismatched payload shapes
 * are caught at compile time.
 */
export const sendAction = <K extends keyof HilosActionMap>(
  websocket: WebSocketSender,
  action: K,
  data: HilosActionMap[K],
): void => {
  websocket.send({
    type: MESSAGE_ACTION_TYPE,
    [MESSAGE_ACTION_FIELD]: action,
    [MESSAGE_DATA_FIELD]: data,
  })
}
