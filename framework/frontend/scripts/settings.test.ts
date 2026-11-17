import { afterEach, expect, it, vi } from 'vitest'

const { send } = vi.hoisted(() => ({ send: vi.fn() }))
vi.mock('./standCommand.mjs', () => ({
  createStandCommandChannel: () => send,
}))

import { captureSetting, restoreSetting } from './settings.mjs'

const original = {
  key: 'example_integer',
  exists: true,
  type: 'integer',
  rawValue: '0007',
  valueSource: 'override' as const,
}

afterEach(() => send.mockReset())

it('captures a complete snapshot through the shared channel and rejects malformed replies', async () => {
  send.mockResolvedValueOnce({ snapshot: original })
  await expect(captureSetting(original.key)).resolves.toEqual(original)
  expect(send).toHaveBeenCalledWith('test:settings:snapshot', {
    key: original.key,
  })
  for (const snapshot of [
    { ...original, exists: 1 },
    { ...original, rawValue: 7 },
    { ...original, exists: false },
    { ...original, valueSource: 'unknown' },
    { ...original, key: 'other' },
    { ...original, extra: true },
    { key: original.key },
  ]) {
    send.mockResolvedValueOnce({ snapshot })
    await expect(captureSetting(original.key)).rejects.toThrow()
  }
  send.mockResolvedValueOnce({ snapshot: original, extra: true })
  await expect(captureSetting(original.key)).rejects.toThrow()
})

it('a lost snapshot response performs no mutation', async () => {
  const failure = new Error('snapshot reply lost')
  send.mockRejectedValueOnce(failure)
  await expect(captureSetting(original.key)).rejects.toBe(failure)
  expect(send).toHaveBeenCalledTimes(1)
  expect(send).toHaveBeenCalledWith('test:settings:snapshot', {
    key: original.key,
  })
})

it('restores raw numeric text and legacy null without normalization', async () => {
  for (const snapshot of [
    original,
    {
      ...original,
      type: 'legacy',
      rawValue: null,
      valueSource: 'default' as const,
    },
    { ...original, rawValue: '', valueSource: 'override' as const },
    {
      ...original,
      exists: false,
      rawValue: null,
      valueSource: 'reference' as const,
    },
  ]) {
    send.mockResolvedValueOnce({ restored: true })
    await restoreSetting(snapshot)
    expect(send).toHaveBeenLastCalledWith('test:settings:restore', { snapshot })
  }
  await expect(restoreSetting({ ...original, exists: false })).rejects.toThrow()
  expect(send).toHaveBeenCalledTimes(4)
})

it('reconciles a lost restore reply by reading all original fields without a second write', async () => {
  send.mockRejectedValueOnce(new Error('restore reply lost'))
  send.mockResolvedValueOnce({ snapshot: original })
  await expect(restoreSetting(original)).resolves.toBeUndefined()
  expect(send.mock.calls).toEqual([
    ['test:settings:restore', { snapshot: original }],
    ['test:settings:snapshot', { key: original.key }],
  ])
})

it('does not hide failed restoration, unreadable reconciliation or malformed acknowledgements', async () => {
  const failure = new Error('restore failed')
  send.mockRejectedValueOnce(failure)
  send.mockResolvedValueOnce({ snapshot: { ...original, rawValue: '7' } })
  await expect(restoreSetting(original)).rejects.toBe(failure)
  send.mockRejectedValueOnce(failure)
  send.mockRejectedValueOnce(new Error('read failed'))
  await expect(restoreSetting(original)).rejects.toMatchObject({
    errors: [failure, expect.objectContaining({ message: 'read failed' })],
  })
  for (const reply of [{}, { restored: 1 }, { restored: true, extra: true }]) {
    send.mockResolvedValueOnce(reply)
    await expect(restoreSetting(original)).rejects.toThrow(
      'Invalid settings restoration reply.',
    )
  }
})
