import { describe, expect, it } from 'vitest'

import {
  createHilosProfileDeviceActions,
  profilePushChannel,
  resolveHilosProfileDevices,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'

describe('profile devices', () => {
  it('marks the current and expired push destinations', () => {
    const devices = resolveHilosProfileDevices(
      [
        {
          id: 1,
          deviceName: 'Current browser',
          endpointHash: 'own-hash',
          createdAt: '2026-09-25 10:00:00',
          goneAt: null,
        },
        {
          id: 2,
          deviceName: null,
          endpointHash: 'other-hash',
          createdAt: '2026-09-20 10:00:00',
          goneAt: '2026-09-24 10:00:00',
        },
      ],
      'own-hash',
    )

    expect(devices).toEqual([
      {
        id: 1,
        deviceName: 'Current browser',
        createdAt: '2026-09-25 10:00:00',
        expired: false,
        current: true,
      },
      {
        id: 2,
        deviceName: null,
        createdAt: '2026-09-20 10:00:00',
        expired: true,
        current: false,
      },
    ])
  })

  it('finds the push channel by its public VAPID config', () => {
    expect(
      profilePushChannel([
        {
          channel: 'email',
          label: 'Email',
          allowed: true,
          hasAddress: true,
        },
        {
          channel: 'push',
          label: 'Push',
          allowed: true,
          hasAddress: true,
          config: { vapid_public: 'public-key' },
        },
      ]),
    ).toEqual({
      channel: 'push',
      label: 'Push',
      vapidPublicKey: 'public-key',
    })
  })

  it('dispatches remove with the subscription id', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = {} as ActionHandle
    const actions = {
      dispatch(action: string, payload: unknown): ActionHandle {
        sent.push({ action, payload })

        return handle
      },
    } as unknown as ActionLifecycle
    const profileActions = createHilosProfileDeviceActions({ actions })

    expect(profileActions.removeDevice(9)).toBe(handle)
    expect(sent).toEqual([
      { action: 'push_remove', payload: { subscriptionId: 9 } },
    ])
  })
})
