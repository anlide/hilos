import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStandCommandChannel } from '../../scripts/standCommand.mjs'
import { bringLivenessMomentToNow } from '../rawWebSocket.js'

vi.mock('../../scripts/standCommand.mjs', () => ({
  createStandCommandChannel: vi.fn(),
}))

const TOKEN = 'fixture-session-token'

const send =
  vi.fn<
    (
      command: string,
      payload: Record<string, unknown>,
    ) => Promise<Record<string, unknown>>
  >()

beforeEach(() => {
  vi.mocked(createStandCommandChannel).mockReturnValue(send)
})

afterEach(() => {
  vi.resetAllMocks()
})

describe('bringLivenessMomentToNow', () => {
  it.each(['probably-offline', 'close', 'check'] as const)(
    'sends %s of the token to the stand command endpoint',
    async (moment) => {
      send.mockResolvedValue({ sockets: 1 })

      await expect(bringLivenessMomentToNow(TOKEN, moment)).resolves.toBe(
        undefined,
      )

      expect(send).toHaveBeenCalledExactlyOnceWith('test:connection:liveness', {
        sessionToken: TOKEN,
        moment,
      })
      expect(createStandCommandChannel).toHaveBeenCalledExactlyOnceWith({
        timeoutMs: expect.any(Number),
      })
    },
  )

  it.each([0, 2, 3])(
    'fails at once naming the %i sockets the master reported',
    async (sockets) => {
      send.mockResolvedValue({ sockets })

      await expect(bringLivenessMomentToNow(TOKEN, 'check')).rejects.toThrow(
        `the master reported ${sockets}`,
      )
    },
  )

  it.each([
    {},
    { sockets: '1' },
    { sockets: null },
    { sockets: 1.5 },
    { sockets: Number.NaN },
  ])('refuses a reply without an integer socket count: %j', async (reply) => {
    send.mockResolvedValue(reply)

    await expect(bringLivenessMomentToNow(TOKEN, 'close')).rejects.toThrow(
      'Invalid test:connection:liveness reply',
    )
  })

  it('lets the channel error through as it came', async () => {
    const refusal = new Error(
      'test:connection:liveness failed: moment must be probably-offline, close or check',
    )
    send.mockRejectedValue(refusal)

    await expect(bringLivenessMomentToNow(TOKEN, 'close')).rejects.toBe(refusal)
  })
})
