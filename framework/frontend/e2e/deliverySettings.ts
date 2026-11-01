import {
  deliveryObject as object,
  type DeliveryFrameLog,
  type DeliveryPacket,
} from './deliveryFrames.js'
import { writeSettings } from '../scripts/settingsWrites.mjs'

/** One relevant inner delta, retaining its physical delivery and original data. */
export interface DeliverySettingUpdate {
  packet: DeliveryPacket
  value: string
  data: Record<string, unknown>
}

/** A real settings write, timed in the observing runner's monotonic clock. */
export interface DeliverySettingWrite {
  key: string
  value: string
  /** When the runner sent the write. */
  sent: number
  /** When the server accepted the write. */
  accepted: number
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

/**
 * Write example settings keys by the test command, one real write per pair and
 * in order, so a source runs at the pace the delivery window is measured at.
 *
 * Every pair of one call shares its send moment; the library's acceptance
 * moment is brought onto the runner's clock, the one delivery packets carry.
 *
 * @param writes Pairs to write, 1 to 32 of them.
 * @param options Where to send the command.
 * @param options.host Explicit cluster host instead of the stand's own.
 * @returns Every written pair in order, with its send and acceptance moments.
 */
export async function writeDeliverySettings(
  writes: ReadonlyArray<{ key: string; value: string }>,
  { host }: { host?: string } = {},
): Promise<DeliverySettingWrite[]> {
  const sent = performance.now()
  const accepted = await writeSettings([...writes], { host })
  return accepted.map(({ key, value, acceptedAt }) => ({
    key,
    value,
    sent,
    accepted: acceptedAt - performance.timeOrigin,
  }))
}
