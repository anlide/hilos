import type { Expect, Page } from '@playwright/test'

import {
  deliveryObject as object,
  type DeliveryFrameLog,
  type DeliveryPacket,
} from './deliveryFrames.js'
import { setCustomSetting } from './settings.js'
import { dismissToasts } from './toasts.js'

/** One relevant inner delta, retaining its physical delivery and original data. */
export interface DeliverySettingUpdate {
  packet: DeliveryPacket
  value: string
  data: Record<string, unknown>
}

/** A UI save with a real outgoing request and its correlated server acknowledgement. */
export interface DeliverySettingMutation {
  key: string
  value: string
  sent: number
  accepted: number
  requestId: string
  ack: unknown
}

/** Read Settings deltas through the canonical delivery observer without parsing raw again. */
export function deliverySettingUpdates(
  log: DeliveryFrameLog,
  key: string,
): DeliverySettingUpdate[] {
  return log.packets.flatMap((packet) =>
    packet.frames.flatMap(({ wire }) => {
      const envelope = object(wire)
      const data = object(envelope.data)
      const slot = object(object(object(data.row).slots).settings)
      return envelope.type === 'table_viewport_delta' &&
        data.page === 'hilos_settings' &&
        data.tableKey === 'settings' &&
        data.rowKey === key
        ? [{ packet, value: String(slot.value), data }]
        : []
    }),
  )
}

/** Save through the existing modal and prove settle by the actual correlated success frame. */
export async function saveDeliverySetting(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  key: string,
  value: string,
): Promise<DeliverySettingMutation> {
  await dismissToasts(page)
  const before = log.sent.length
  await setCustomSetting(page, key, value)
  const requests = log.sent.slice(before).filter(({ wire }) => {
    const request = object(wire)
    // Catalog keys use the existing idempotent add-by-key action for every save.
    return request.type === 'action' && request.action === 'setting_add'
  })
  expect(requests).toHaveLength(1)
  const request = requests[0]!
  expect(object(request.wire).data).toEqual({ key, value })
  const requestId = object(request.wire).requestId
  expect(typeof requestId).toBe('string')
  const packet = log.packets.find((packet) =>
    packet.frames.some(({ wire }) => object(wire).requestId === requestId),
  )
  expect(
    packet,
    'closing the modal followed the actual server response',
  ).toBeDefined()
  const ack = packet!.frames
    .map(({ wire }) => object(wire))
    .find((wire) => wire.requestId === requestId)!
  expect(ack.type).toBe('action_success')
  expect(ack.outcome).toBe('success')
  return {
    key,
    value,
    sent: request.at,
    accepted: packet!.at,
    requestId: String(requestId),
    ack,
  }
}
