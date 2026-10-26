import type { Expect } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  waitForDeliveryQuiet,
  type DeliveryFrameLog,
  type DeliveryPacket,
} from '../deliveryFrames.js'

const packet = (at: number, wire: unknown): DeliveryPacket => ({
  at,
  socket: 1,
  message: at,
  raw: wire === undefined ? undefined : JSON.stringify(wire),
  frames: [],
})
const log = (packets: DeliveryPacket[]): DeliveryFrameLog => ({
  packets,
  sent: [],
  connections: [{ socket: 1, opened: 0 }],
  sockets: () => 1,
  stop() {},
})

afterEach(() => vi.restoreAllMocks())

describe('physical delivery quiet preparation', () => {
  it('uses observed history and restarts for hints and binary/failed packets on either socket', async () => {
    let now = 1_000
    vi.spyOn(performance, 'now').mockImplementation(() => now)
    const a = log([packet(100, { type: 'page_response' })])
    const b = log([packet(200, { type: 'page_response' })])
    const checks = {
      poll(read: () => number) {
        return {
          async toBeGreaterThanOrEqual(boundary: number) {
            expect(read()).toBe(800)
            a.packets.push(
              packet(9_000, { type: 'status_files_changed', data: {} }),
            )
            now = 9_500
            expect(read()).toBe(500)
            expect(read()).toBeLessThan(boundary)
            b.packets.push(packet(15_000, undefined))
            now = 18_000
            expect(read()).toBe(3_000)
            expect(read()).toBeLessThan(boundary)
            now = 24_000
            expect(read()).toBe(boundary)
          },
        }
      },
    } as unknown as Expect
    const quiet = await waitForDeliveryQuiet(
      checks,
      [a, b],
      8_000,
      1_000,
      39_000,
    )
    expect(quiet).toEqual({
      startedAt: 1_000,
      at: 24_000,
      lastActivity: [9_000, 15_000],
      packetCounts: [2, 2],
    })
  })

  it('includes accepted-request activity and the latest socket opening while ignoring transport pings', async () => {
    let now = 10_000
    vi.spyOn(performance, 'now').mockImplementation(() => now)
    const a = log([packet(100, {})])
    a.sent.push({ socket: 1, at: 9_900, raw: 'ping', wire: undefined })
    const checks = {
      poll(read: () => number) {
        return {
          async toBeGreaterThanOrEqual(boundary: number) {
            expect(read()).toBe(9_900)
            a.sent.push({ socket: 1, at: 10_050, raw: '{}', wire: {} })
            now = 10_100
            expect(read()).toBe(50)
            a.connections.push({ socket: 2, opened: 10_080 })
            expect(read()).toBe(20)
            now = 10_080 + boundary
            expect(read()).toBe(boundary)
          },
        }
      },
    } as unknown as Expect
    const quiet = await waitForDeliveryQuiet(checks, [a], 8_000, 1_000, 39_000)
    expect(quiet.lastActivity).toEqual([10_080])
  })
})
