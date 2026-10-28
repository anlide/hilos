import { afterEach, expect, it, vi } from 'vitest'
import { resetAuthThrottle } from './authThrottle.mjs'

const command = vi.hoisted(() => vi.fn())
vi.mock('./commandChannel.mjs', () => ({ createCommandChannel: () => command }))

afterEach(() => {
  vi.unstubAllEnvs()
  command.mockReset()
})

it('uses the current stand endpoint and the existing owner reset command', async () => {
  vi.stubEnv('COMMAND_HOST', 'daemon.test')
  vi.stubEnv('COMMAND_PORT', '8094')
  command.mockResolvedValue({ counters: 2, blocks: 1 })
  await resetAuthThrottle()
  expect(command).toHaveBeenCalledExactlyOnceWith('test:throttle:reset', {})
})

it('preserves a refusal from the counter owner', async () => {
  vi.stubEnv('COMMAND_HOST', 'daemon.test')
  vi.stubEnv('COMMAND_PORT', '8094')
  command.mockRejectedValue(new Error('owner unavailable'))
  await expect(resetAuthThrottle()).rejects.toThrow('owner unavailable')
})
