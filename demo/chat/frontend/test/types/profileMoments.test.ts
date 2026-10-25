import { describe, expect, it } from 'vitest'
import {
  resolveHilosProfileDevices,
  resolveHilosProfileSessions,
} from '@hilos/core/base'
import { sessionFromFields } from '../../src/types/Session.js'
import { pushSubscriptionFromFields } from '../../src/types/PushSubscription.js'

describe('typed profile moment readers', () => {
  it('preserves numeric session moments and the raw browser zone for the shared resolver', () => {
    const fields = {
      id: 1,
      userId: 7,
      deviceName: 'Laptop',
      browserTimeZone: 'US/Eastern',
      createdAt: 1767225600000,
      lastSeenAt: 1767227400000,
      expiresAt: 1767312000000,
      impersonatorUserId: null,
    }
    const session = sessionFromFields(fields)
    expect(session).toEqual(fields)
    expect(
      resolveHilosProfileSessions([
        { session: { ...session }, connections: [] },
      ])[0],
    ).toEqual({
      id: 1,
      deviceName: 'Laptop',
      browserTimeZone: 'US/Eastern',
      createdAt: 1767225600000,
      lastSeenAt: 1767227400000,
      expiresAt: 1767312000000,
      current: false,
      impersonated: false,
      tabs: [],
    })
    expect(
      sessionFromFields({ ...fields, browserTimeZone: null, expiresAt: null }),
    ).toEqual({
      ...fields,
      browserTimeZone: null,
      expiresAt: null,
    })
  })

  it('preserves numeric push dates and null expiry for the shared resolver', () => {
    const fields = {
      id: 2,
      deviceName: null,
      endpointHash: 'own-hash',
      createdAt: 1767225600000,
      goneAt: null,
    }
    const device = pushSubscriptionFromFields(fields)
    expect(device).toEqual(fields)
    expect(resolveHilosProfileDevices([{ ...device }], 'own-hash')).toEqual([
      {
        id: 2,
        deviceName: null,
        createdAt: 1767225600000,
        expired: false,
        current: true,
      },
    ])
    const gone = pushSubscriptionFromFields({
      ...fields,
      goneAt: 1767227400000,
    })
    expect(gone.goneAt).toBe(1767227400000)
    expect(resolveHilosProfileDevices([{ ...gone }], null)[0]?.expired).toBe(
      true,
    )
  })
})
