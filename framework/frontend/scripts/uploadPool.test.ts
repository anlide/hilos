import { describe, expect, it } from 'vitest'
import { createUploadPoolDriver } from './uploadPool.mjs'

const EMPTY = {
  limitBytes: 131_072,
  usedBytes: 0,
  waitingUploads: 0,
  pendingPeerReservations: 0,
}

describe('the real upload pool command reply boundary', () => {
  it('uses the existing test route and preserves cumulative byte snapshots', async () => {
    const commands: unknown[] = []
    const events: unknown[] = []
    const driver = createUploadPoolDriver(
      async (command, payload) => {
        commands.push({ command, payload })
        return {
          ...EMPTY,
          limitBytes: payload.memoryPoolBytes ?? EMPTY.limitBytes,
        }
      },
      (event) => events.push(event),
    )
    expect(await driver.inspect()).toEqual(EMPTY)
    expect(await driver.limit(0)).toEqual({ ...EMPTY, limitBytes: 0 })
    expect(commands).toEqual([
      {
        command: 'test:cluster:upload-pool',
        payload: { operation: 'inspect' },
      },
      {
        command: 'test:cluster:upload-pool',
        payload: { operation: 'limit', memoryPoolBytes: 0 },
      },
    ])
    expect(events).toHaveLength(2)
  })

  it.each([
    { ...EMPTY, usedBytes: EMPTY.limitBytes + 1 },
    { ...EMPTY, usedBytes: -1 },
    { ...EMPTY, waitingUploads: 0.5 },
    { ...EMPTY, pendingPeerReservations: '0' },
    { ...EMPTY, limitBytes: Number.NaN },
    { ...EMPTY, usedBytes: undefined },
    { ...EMPTY, unexpected: 0 },
  ])(
    'refuses an invalid snapshot without turning it into an observation',
    async (reply) => {
      const driver = createUploadPoolDriver(
        async () => reply,
        () => {},
      )
      await expect(driver.inspect()).rejects.toThrow('Invalid or over-budget')
    },
  )
})
