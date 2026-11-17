import { afterEach, expect, it, vi } from 'vitest'

const { send } = vi.hoisted(() => ({ send: vi.fn() }))
vi.mock('./standCommand.mjs', () => ({
  createStandCommandChannel: () => send,
}))

import {
  cleanupNotification,
  emitNotification,
  inspectNotification,
} from './notifications.mjs'

const emitted = { notificationId: 7, queuedChannels: ['email'] }
const inspection = {
  ...emitted,
  deliveries: [{ deliveryId: 8, channel: 'email', status: 'sent' }],
}
const absent = { notificationId: null, queuedChannels: [], deliveries: [] }

afterEach(() => send.mockReset())

it('keeps the emit API and channel narrowing while requiring a complete reply', async () => {
  send.mockResolvedValueOnce(emitted)
  const draft = { type: 'test.owned', title: 'Owned', channels: [] }
  await expect(emitNotification(3, draft)).resolves.toEqual(emitted)
  expect(send).toHaveBeenCalledWith('test:notification:emit', {
    userId: 3,
    ...draft,
  })
  for (const reply of [
    {},
    { ...emitted, notificationId: '7' },
    { ...emitted, notificationId: true },
    { ...emitted, notificationId: 7.5 },
    { ...emitted, notificationId: 0 },
    { ...emitted, queuedChannels: undefined },
    { ...emitted, queuedChannels: ['email', 'email'] },
    { ...emitted, queuedChannels: [7] },
    { ...emitted, extra: true },
  ]) {
    send.mockResolvedValueOnce(reply)
    await expect(emitNotification(3, draft)).rejects.toThrow()
  }
})

it('inspects the exact recipient and title, including an absent subject', async () => {
  for (const reply of [inspection, absent]) {
    send.mockResolvedValueOnce(reply)
    await expect(inspectNotification(3, 'Exact title')).resolves.toEqual(reply)
    expect(send).toHaveBeenLastCalledWith('test:notification:inspect', {
      userId: 3,
      title: 'Exact title',
    })
  }
})

it('refuses malformed inspection ids, channels, journal rows and inconsistent absence', async () => {
  for (const reply of [
    {},
    { ...inspection, notificationId: '7' },
    { ...inspection, notificationId: false },
    { ...inspection, notificationId: 7.5 },
    { ...inspection, notificationId: null },
    { ...inspection, queuedChannels: ['sms'] },
    { ...inspection, deliveries: {} },
    { ...inspection, deliveries: [{ deliveryId: 8, channel: 'email' }] },
    {
      ...inspection,
      deliveries: [{ deliveryId: '8', channel: 'email', status: 'sent' }],
    },
    {
      ...inspection,
      deliveries: [{ deliveryId: true, channel: 'email', status: 'sent' }],
    },
    {
      ...inspection,
      deliveries: [{ deliveryId: 8.5, channel: 'email', status: 'sent' }],
    },
    {
      ...inspection,
      deliveries: [{ deliveryId: 8, channel: 7, status: 'sent' }],
    },
    {
      ...inspection,
      deliveries: [{ deliveryId: 8, channel: 'email', status: 'queued' }],
    },
    {
      ...inspection,
      deliveries: [...inspection.deliveries, ...inspection.deliveries],
    },
    { ...inspection, extra: true },
  ]) {
    send.mockResolvedValueOnce(reply)
    await expect(inspectNotification(3, 'Owned')).rejects.toThrow()
  }
})

it('accepts pending and failed as observable states without retrying any delivery', async () => {
  for (const status of ['pending', 'failed']) {
    const reply = {
      ...inspection,
      deliveries: [{ deliveryId: 8, channel: 'email', status }],
    }
    send.mockResolvedValueOnce(reply)
    await expect(inspectNotification(3, 'Owned')).resolves.toEqual(reply)
  }
  expect(send.mock.calls.map(([command]) => command)).toEqual([
    'test:notification:inspect',
    'test:notification:inspect',
  ])
})

it('requires a boolean cleanup verdict and preserves pending, duplicate and transport refusals', async () => {
  for (const deleted of [true, false]) {
    send.mockResolvedValueOnce({ deleted })
    await expect(cleanupNotification(3, 'Owned')).resolves.toEqual({ deleted })
    expect(send).toHaveBeenLastCalledWith('test:notification:cleanup', {
      userId: 3,
      title: 'Owned',
    })
  }
  for (const reply of [
    {},
    { deleted: 1 },
    { deleted: 'true' },
    { deleted: true, extra: true },
  ]) {
    send.mockResolvedValueOnce(reply)
    await expect(cleanupNotification(3, 'Owned')).rejects.toThrow()
  }
  for (const message of ['pending', 'duplicate', 'reply lost']) {
    const failure = new Error(message)
    send.mockRejectedValueOnce(failure)
    await expect(cleanupNotification(3, 'Owned')).rejects.toBe(failure)
  }
})

it('rejects invalid subjects before the shared transport and never retries a failed emit', async () => {
  for (const userId of [0, -1, 1.5, true, '3']) {
    await expect(
      inspectNotification(userId as number, 'Owned'),
    ).rejects.toThrow()
    await expect(
      cleanupNotification(userId as number, 'Owned'),
    ).rejects.toThrow()
  }
  expect(send).not.toHaveBeenCalled()
  const failure = new Error('emit response lost')
  send.mockRejectedValueOnce(failure)
  await expect(
    emitNotification(3, { type: 'test.owned', title: 'Owned' }),
  ).rejects.toBe(failure)
  expect(send).toHaveBeenCalledTimes(1)
})
