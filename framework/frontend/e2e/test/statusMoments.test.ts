import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  staleStatusHeartbeat,
  beatStatusHeartbeat,
  removeStatusSilencePeriod,
} from '../../scripts/statusMoments.mjs'

const { send, create } = vi.hoisted(() => ({ send: vi.fn(), create: vi.fn() }))
vi.mock('../../scripts/standCommand.mjs', () => ({
  createStandCommandChannel: create,
}))

beforeEach(() => {
  send.mockReset()
  create.mockReset()
  create.mockReturnValue(send)
})

describe('owned Status silence commands', () => {
  it('retains the stale identity for immediate cleanup registration and keeps the five-second budget', async () => {
    send.mockResolvedValue({ aliveAt: 1000, silencePeriodId: 'a'.repeat(32) })
    await expect(staleStatusHeartbeat({ host: 'owner' })).resolves.toEqual({
      aliveAt: 1000,
      silencePeriodId: 'a'.repeat(32),
    })
    expect(create).toHaveBeenCalledWith({ timeoutMs: 5000, host: 'owner' })
    expect(send).toHaveBeenCalledWith('test:status:heartbeat:stale', {})
  })
  it('refuses a stale reply without a usable owned identity', async () => {
    send.mockResolvedValue({ aliveAt: 1000 })
    await expect(staleStatusHeartbeat()).rejects.toThrow(
      'Invalid test:status:heartbeat:stale reply',
    )
  })
  it('keeps the now request and reply compatible', async () => {
    send.mockResolvedValue({ aliveAt: 2000 })
    await expect(beatStatusHeartbeat()).resolves.toEqual({ aliveAt: 2000 })
    expect(send).toHaveBeenCalledWith('test:status:heartbeat:now', {})
  })
  it('removes only the requested identity and refuses a mismatched cleanup receipt', async () => {
    const publicId = 'b'.repeat(32)
    send
      .mockResolvedValueOnce({ publicId })
      .mockResolvedValueOnce({ publicId: 'c'.repeat(32) })
    await expect(removeStatusSilencePeriod(publicId)).resolves.toEqual({
      publicId,
    })
    expect(send).toHaveBeenCalledWith('test:status:silence:remove', {
      publicId,
    })
    await expect(removeStatusSilencePeriod(publicId)).rejects.toThrow(
      'Invalid test:status:silence:remove reply',
    )
  })
})
