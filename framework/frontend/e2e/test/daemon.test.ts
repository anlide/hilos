import { describe, expect, it } from 'vitest'

import { readDaemonCronMoment } from '../daemon.js'
import { createSocketFrameReader } from '../socket.js'

const rowKey = 'hilos_sessions_library/hilos_sessions_expire'
const ruleRow = (nextRunAt: number) => ({
  rowKey,
  slots: {
    rule: {
      rowKey,
      lastRunAt: null,
      nextRunAt,
      idleReason: null,
    },
  },
})
const initial = {
  type: 'page_response',
  data: {
    page: 'hilos_daemon_cron',
    payload: {
      windows: { hilosDaemonCron: { rows: [ruleRow(1_800_000_000_000)] } },
    },
  },
}

describe('real Cron moment evidence', () => {
  it('keeps data-only page updates, then replaces the entire window including an absent row', () => {
    const read = createSocketFrameReader(1)
    const frames = read(JSON.stringify(initial))
    frames.push(
      ...read(
        JSON.stringify({
          type: 'page_response',
          data: {
            page: 'hilos_daemon_cron',
            payload: { data: { cronReported: true } },
          },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)?.frame).toBe(frames[0])
    const replacement = {
      type: 'table_window',
      data: {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
        totalCount: 1,
        totalExact: true,
        limit: 25,
        firstAnchor: null,
        lastAnchor: null,
        rows: [ruleRow(1_800_000_060_000)],
      },
    }
    frames.push(...read(JSON.stringify(replacement)))
    expect(frames.map((frame) => frame.failure)).toEqual([
      undefined,
      undefined,
      undefined,
    ])
    expect(readDaemonCronMoment(frames, rowKey)?.rule.nextRunAt).toBe(
      1_800_000_060_000,
    )
    frames.push(
      ...read(
        JSON.stringify({
          ...replacement,
          data: { ...replacement.data, rows: [] },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)).toBeUndefined()
  })

  it('reads batched live edits in order, ignores other addresses and retains decoder failures', () => {
    const read = createSocketFrameReader(2)
    const update = {
      type: 'table_viewport_delta',
      data: {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
        rowKey,
        kind: 'row_updated',
        row: ruleRow(1_800_000_120_000),
      },
    }
    const raw = JSON.stringify({
      type: 'signal_batch',
      data: {
        frames: [
          initial,
          update,
          {
            ...update,
            data: { ...update.data, page: 'elsewhere', row: ruleRow(42) },
          },
        ],
      },
    })
    const frames = read(raw)
    const current = readDaemonCronMoment(frames, rowKey)!
    expect(frames.every((frame) => frame.failure === undefined)).toBe(true)
    expect(current.rule.nextRunAt).toBe(1_800_000_120_000)
    expect(current.frame).toBe(frames[1])
    expect(current.frame.raw).toBe(raw)
    expect(current.frame.message).toBe(1)
    expect(current.frame.socket).toBe(2)
    frames.push(...read('{'))
    expect(frames.at(-1)?.failure).toBeDefined()
    expect(readDaemonCronMoment(frames, rowKey)).toEqual(current)
    frames.push(
      ...read(
        JSON.stringify({
          ...update,
          data: { ...update.data, kind: 'row_removed' },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)).toBeUndefined()
  })
})
