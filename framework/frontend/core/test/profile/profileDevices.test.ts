import { describe, expect, it } from 'vitest'

import {
  createHilosProfileDeviceActions,
  hilosProfileDeviceKind,
  profilePushChannel,
  resolveHilosProfileDevices,
  type ActionHandle,
  type ActionLifecycle,
  type HilosProfileDevice,
  type HilosProfileDeviceKind,
} from '../../src/base/index.js'

describe('profile devices', () => {
  it('marks the current and expired push destinations', () => {
    const devices = resolveHilosProfileDevices(
      [
        {
          id: 1,
          deviceName: 'Current browser',
          endpointHash: 'own-hash',
          createdAt: 1790330400000,
          goneAt: null,
        },
        {
          id: 2,
          deviceName: null,
          endpointHash: 'other-hash',
          createdAt: 1789898400000,
          goneAt: 1790244000000,
        },
      ],
      'own-hash',
    )

    expect(devices).toEqual([
      {
        id: 1,
        deviceName: 'Current browser',
        createdAt: 1790330400000,
        expired: false,
        current: true,
      },
      {
        id: 2,
        deviceName: null,
        createdAt: 1789898400000,
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

  it.each<[string | null, HilosProfileDeviceKind]>([
    // Seven canonical platforms
    ['Safari on iPhone', 'phone'],
    ['Chrome on Android', 'phone'],
    ['Safari on iPad', 'tablet'],
    ['Chrome on macOS', 'laptop'],
    ['Chrome on ChromeOS', 'laptop'],
    ['Firefox on Windows', 'display'],
    ['Firefox on Linux', 'display'],

    // Both/different browsers on the same platform
    ['Firefox on Android', 'phone'],
    ['Samsung Internet on Android', 'phone'],
    ['Opera on iPhone', 'phone'],
    ['Chrome on iPhone', 'phone'],
    ['Chrome on iPad', 'tablet'],
    ['Firefox on iPad', 'tablet'],
    ['Safari on macOS', 'laptop'],
    ['Firefox on macOS', 'laptop'],
    ['Edge on macOS', 'laptop'],
    ['Opera on ChromeOS', 'laptop'],
    ['Edge on Windows', 'display'],
    ['Chrome on Windows', 'display'],
    ['Chrome on Linux', 'display'],
    ['Opera on Linux', 'display'],

    // Trim and case variation
    ['  Safari on iPhone  ', 'phone'],
    ['chrome ON MACOS', 'laptop'],
    ['FIREFOX ON WINDOWS', 'display'],
    ['opera ON iPad', 'tablet'],
    ['  edge on chromeos  ', 'laptop'],
    ['\tFirefox on Linux\n', 'display'],
    ['Browser on ANDROID', 'phone'],
    ['  Browser on iPhone  ', 'phone'],
    ['  Browser on iPad  ', 'tablet'],
    ['  Browser on macOS  ', 'laptop'],
    ['  Browser on ChromeOS  ', 'laptop'],
    ['  Browser on Windows  ', 'display'],
    ['  Browser on Linux  ', 'display'],

    // Null, empty, whitespace and unknown names
    [null, 'display'],
    ['', 'display'],
    ['   ', 'display'],
    ['\t\n', 'display'],
    ['Unknown device', 'display'],
    ['Safari on FreeBSD', 'display'],
    ['Some browser', 'display'],

    // False substrings
    ['My iPhone backup', 'display'],
    ['MacBook', 'display'],
    ['Phone on Desk', 'display'],
    ['iPhone', 'display'],
    ['iPad', 'display'],
    ['macOS', 'display'],
    ['Android', 'display'],
    ['Windows', 'display'],
    ['Linux', 'display'],
    ['ChromeOS', 'display'],
    ['Safari on iPhone (work)', 'display'],
    ['Chrome on Windows extra', 'display'],
    ['Chrome on macOS 14', 'display'],
    ['iPad on table', 'display'],
    ['Android phone', 'display'],
  ])('determines device kind for %j as %s', (deviceName, expectedKind) => {
    expect(hilosProfileDeviceKind(deviceName)).toBe(expectedKind)
  })

  it('does not mutate input strings or source device models', () => {
    const originalLabel = 'Safari on iPhone'
    const frozenDevice: HilosProfileDevice = Object.freeze({
      id: 1,
      deviceName: originalLabel,
      createdAt: 1790330400000,
      expired: false,
      current: true,
    })

    expect(hilosProfileDeviceKind(frozenDevice.deviceName)).toBe('phone')
    expect(frozenDevice.deviceName).toBe(originalLabel)
  })
})
