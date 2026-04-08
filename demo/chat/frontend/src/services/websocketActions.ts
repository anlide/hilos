import { MESSAGE_ACTION_FIELD, MESSAGE_ACTION_TYPE, MESSAGE_DATA_FIELD } from '@/constants'

export type WebSocketSender = {
  send: (data: string | object) => void
  sendBinary?: (data: ArrayBuffer | Blob) => void
}

export const sendAction = (
  websocket: WebSocketSender,
  action: string,
  data: Record<string, unknown> = {},
): void => {
  websocket.send({
    type: MESSAGE_ACTION_TYPE,
    [MESSAGE_ACTION_FIELD]: action,
    [MESSAGE_DATA_FIELD]: data,
  })
}
