import type { Expect } from '@playwright/test'
import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'

/** Read one reply only when its request id, action and physical socket all agree. */
export function trackedActionReplies(
  log: DeliveryFrameLog,
  action: string,
  since: number,
) {
  return log.sent.slice(since).flatMap((request) => {
    const envelope = deliveryObject(request.wire)
    if (envelope.type !== 'action' || envelope.action !== action) return []
    return log.packets.flatMap((packet) =>
      packet.frames.flatMap(({ wire, socket }) => {
        const frame = deliveryObject(wire)
        const data = deliveryObject(frame.data)
        return socket === request.socket &&
          typeof envelope.requestId === 'string' &&
          (frame.requestId ?? data.requestId) === envelope.requestId &&
          data.action === action &&
          ['action_success', 'action_error'].includes(String(frame.type))
          ? [{ request, frame, data }]
          : []
      }),
    )
  })
}

/** Wait for the single tracked reply, including its exact public domain payload. */
export async function expectTrackedActionReply(
  expect: Expect,
  log: DeliveryFrameLog,
  action: string,
  since: number,
  reply: Record<string, unknown>,
): Promise<void> {
  await expect
    .poll(() => trackedActionReplies(log, action, since).length)
    .toBe(1)
  const result = trackedActionReplies(log, action, since)[0]
  expect(result.frame.type).toBe('action_success')
  expect(result.data.reply).toEqual(reply)
}
