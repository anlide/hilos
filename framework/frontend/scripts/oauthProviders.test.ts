import { afterEach, expect, it, vi } from 'vitest'

const { send } = vi.hoisted(() => ({ send: vi.fn() }))
vi.mock('./standCommand.mjs', () => ({
  createStandCommandChannel: () => send,
}))

import {
  captureOAuthProvider,
  restoreOAuthProvider,
} from './oauthProviders.mjs'

const original = {
  providerKey: 'oauth:github',
  row: {
    id: 7,
    clientId: '',
    clientSecret: 'synthetic-test-secret',
    scope: null,
    createdAt: -123,
    updatedAt: 456,
  },
}

afterEach(() => send.mockReset())

it('captures strict nullable raw fields and rejects extra, absent or coerced fields', async () => {
  for (const row of [
    original.row,
    null,
    { ...original.row, clientSecret: '' },
  ]) {
    send.mockResolvedValueOnce({ snapshot: { ...original, row } })
    const snapshot = await captureOAuthProvider(original.providerKey)
    expect(snapshot.row?.clientSecret === row?.clientSecret).toBe(true)
    expect(snapshot.row?.clientId === row?.clientId).toBe(true)
  }
  for (const row of [
    { ...original.row, id: '7' },
    { ...original.row, id: 0 },
    { ...original.row, id: true },
    { ...original.row, id: 1.5 },
    { ...original.row, clientSecret: 3 },
    { ...original.row, createdAt: '123' },
    { ...original.row, updatedAt: null },
    { ...original.row, extra: true },
    { id: 7 },
  ]) {
    send.mockResolvedValueOnce({ snapshot: { ...original, row } })
    await expect(captureOAuthProvider(original.providerKey)).rejects.toThrow()
  }
  for (const snapshot of [
    { ...original, providerKey: 'other' },
    { ...original, extra: true },
    { providerKey: original.providerKey },
  ]) {
    send.mockResolvedValueOnce({ snapshot })
    await expect(captureOAuthProvider(original.providerKey)).rejects.toThrow()
  }
})

it('does not write after a lost snapshot response', async () => {
  send.mockRejectedValueOnce(new Error('lost snapshot'))
  await expect(captureOAuthProvider(original.providerKey)).rejects.toThrow()
  expect(send).toHaveBeenCalledTimes(1)
  expect(send.mock.calls[0][0]).toBe('test:oauth-provider:snapshot')
})

it('checks successful and lost restore replies by one read without a second write', async () => {
  for (const lost of [false, true]) {
    send.mockReset()
    if (lost) send.mockRejectedValueOnce(new Error('lost restore'))
    else send.mockResolvedValueOnce({ restored: true })
    send.mockResolvedValueOnce({ snapshot: original })
    await restoreOAuthProvider(original, original.row.id)
    expect(send.mock.calls.map(([command]) => command)).toEqual([
      'test:oauth-provider:restore',
      'test:oauth-provider:snapshot',
    ])
    expect(send.mock.calls[0][1].currentId).toBe(original.row.id)
  }
  const absent = { providerKey: original.providerKey, row: null }
  send.mockResolvedValueOnce({ restored: true })
  send.mockResolvedValueOnce({ snapshot: absent })
  await restoreOAuthProvider(absent, 9)
})

it('refuses incomplete cleanup, raw differences and replacement without retrying a write', async () => {
  for (const row of [
    null,
    { ...original.row, id: 9 },
    { ...original.row, clientSecret: '' },
    { ...original.row, clientSecret: null },
    { ...original.row, clientId: null },
    { ...original.row, scope: '' },
    { ...original.row, createdAt: 124 },
    { ...original.row, updatedAt: 457 },
  ]) {
    send.mockReset().mockRejectedValueOnce(new Error('lost restore'))
    send.mockResolvedValueOnce({ snapshot: { ...original, row } })
    await expect(restoreOAuthProvider(original, 7)).rejects.toThrow(
      'OAuth provider cleanup did not restore the original state.',
    )
    expect(send.mock.calls.map(([command]) => command)).toEqual([
      'test:oauth-provider:restore',
      'test:oauth-provider:snapshot',
    ])
  }
})

it('keeps raw responses and transport errors out of cleanup errors', async () => {
  for (const reply of [
    { snapshot: original, extra: original.row.clientSecret },
    { restored: true, extra: original.row.clientSecret },
  ]) {
    send.mockReset().mockResolvedValueOnce(reply)
    const operation = Object.hasOwn(reply, 'snapshot')
      ? captureOAuthProvider(original.providerKey)
      : restoreOAuthProvider(original, 7)
    const failure = await operation.catch((error: unknown) => error)
    expect(failure).toBeInstanceOf(Error)
    expect(String(failure).includes(original.row.clientSecret)).toBe(false)
    expect((failure as Error).cause).toBeUndefined()
  }
  send.mockReset().mockRejectedValue(new Error(original.row.clientSecret))
  const failure = await restoreOAuthProvider(original, 7).catch(
    (error: unknown) => error,
  )
  expect(String(failure).includes(original.row.clientSecret)).toBe(false)
  expect((failure as Error).cause).toBeUndefined()
})
