import { describe, expect, it } from 'vitest'

import {
  createHilosPushSubscriptionStore,
  PUSH_ACTION_SUBSCRIBE,
  PUSH_ACTION_UNSUBSCRIBE,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
} from '../../src/notifications/pushSubscription.js'

/** A subscription snapshot the fake browser hands out. */
const SNAPSHOT: HilosPushSubscriptionSnapshot = {
  endpoint: 'https://push.example/abc',
  p256dh: 'p256dh-key',
  auth: 'auth-secret',
}

interface FakeEnvironmentOptions {
  supported?: boolean
  permission?: HilosPushPermission
  grant?: HilosPushPermission
  existing?: HilosPushSubscriptionSnapshot | null
  rejectSubscribe?: boolean
  rejectUnsubscribe?: boolean
  rejectGetSubscription?: boolean
}

/** A fake browser push surface recording the calls the store makes. */
function fakeEnvironment(options: FakeEnvironmentOptions = {}) {
  const supported = options.supported ?? true
  let current = options.existing ?? null
  const calls = {
    requestPermission: 0,
    subscribe: [] as string[],
    unsubscribe: 0,
  }

  const environment: HilosPushEnvironment = {
    isSupported: () => supported,
    permission: () => options.permission ?? 'default',
    userAgent: () => 'FakeAgent/1.0',
    requestPermission: async () => {
      calls.requestPermission += 1

      return options.grant ?? 'granted'
    },
    getSubscription: async () => {
      if (options.rejectGetSubscription === true) {
        throw new Error('push service unreachable')
      }

      return current
    },
    subscribe: async (key) => {
      calls.subscribe.push(key)
      if (options.rejectSubscribe === true) {
        throw new Error('push service refused the subscription')
      }
      current = SNAPSHOT

      return SNAPSHOT
    },
    unsubscribe: async () => {
      calls.unsubscribe += 1
      if (options.rejectUnsubscribe === true) {
        throw new Error('push service unreachable')
      }
      current = null
    },
  }

  return { environment, calls }
}

/** A connection stub whose sendAction result is fixed and whose calls are recorded. */
function fakeSender(result = true) {
  const sent: Array<{ action: string; data: unknown }> = []

  return {
    sender: {
      sendAction(action: string, data: unknown): boolean {
        sent.push({ action, data })

        return result
      },
    },
    sent,
  }
}

describe('push subscription store', () => {
  it('refreshes to unsupported when the browser lacks push', async () => {
    const { environment } = fakeEnvironment({ supported: false })
    const store = createHilosPushSubscriptionStore(environment)

    await store.refresh()

    expect(store.supported.get()).toBe(false)
    expect(store.permission.get()).toBe('unsupported')
    expect(store.subscribed.get()).toBe(false)
  })

  it('reflects an existing subscription and permission on refresh', async () => {
    const { environment } = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const store = createHilosPushSubscriptionStore(environment)

    await store.refresh()

    expect(store.supported.get()).toBe(true)
    expect(store.permission.get()).toBe('granted')
    expect(store.subscribed.get()).toBe(true)
  })

  it('subscribes and registers the device server-side on enable', async () => {
    const { environment, calls } = fakeEnvironment({ grant: 'granted' })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, 'vapid-public')

    expect(ok).toBe(true)
    expect(store.subscribed.get()).toBe(true)
    expect(store.busy.get()).toBe(false)
    expect(calls.subscribe).toEqual(['vapid-public'])
    expect(sent).toEqual([
      {
        action: PUSH_ACTION_SUBSCRIBE,
        data: {
          endpoint: SNAPSHOT.endpoint,
          p256dh: SNAPSHOT.p256dh,
          auth: SNAPSHOT.auth,
          userAgent: 'FakeAgent/1.0',
        },
      },
    ])
  })

  it('does not subscribe when permission is denied', async () => {
    const { environment, calls } = fakeEnvironment({ grant: 'denied' })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, 'vapid-public')

    expect(ok).toBe(false)
    expect(store.permission.get()).toBe('denied')
    expect(store.subscribed.get()).toBe(false)
    expect(calls.subscribe).toEqual([])
    expect(sent).toEqual([])
  })

  it('rolls back the browser subscription when the register action does not leave', async () => {
    const { environment, calls } = fakeEnvironment({ grant: 'granted' })
    const { sender } = fakeSender(false)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, 'vapid-public')

    expect(ok).toBe(false)
    expect(store.subscribed.get()).toBe(false)
    expect(calls.subscribe).toEqual(['vapid-public'])
    expect(calls.unsubscribe).toBe(1)
  })

  it('reports failure without throwing when the browser subscribe rejects', async () => {
    const { environment } = fakeEnvironment({
      grant: 'granted',
      rejectSubscribe: true,
    })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, 'vapid-public')

    expect(ok).toBe(false)
    expect(store.subscribed.get()).toBe(false)
    expect(store.busy.get()).toBe(false)
    expect(sent).toEqual([])
  })

  it('reports failure without throwing when the browser unsubscribe rejects on disable', async () => {
    const { environment } = fakeEnvironment({
      existing: SNAPSHOT,
      rejectUnsubscribe: true,
    })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.disable(sender)

    expect(ok).toBe(false)
    expect(store.busy.get()).toBe(false)
    expect(sent).toEqual([])
  })

  it('refreshes to not-subscribed without throwing when getSubscription rejects', async () => {
    const { environment } = fakeEnvironment({
      permission: 'granted',
      rejectGetSubscription: true,
    })
    const store = createHilosPushSubscriptionStore(environment)

    await store.refresh()

    expect(store.supported.get()).toBe(true)
    expect(store.permission.get()).toBe('granted')
    expect(store.subscribed.get()).toBe(false)
  })

  it('refuses to enable without a VAPID key', async () => {
    const { environment, calls } = fakeEnvironment()
    const { sender } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, '')

    expect(ok).toBe(false)
    expect(calls.requestPermission).toBe(0)
    expect(calls.subscribe).toEqual([])
  })

  it('unsubscribes the browser and tells the server on disable', async () => {
    const { environment, calls } = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.disable(sender)

    expect(ok).toBe(true)
    expect(store.subscribed.get()).toBe(false)
    expect(calls.unsubscribe).toBe(1)
    expect(sent).toEqual([
      {
        action: PUSH_ACTION_UNSUBSCRIBE,
        data: { endpoint: SNAPSHOT.endpoint },
      },
    ])
  })

  it('disables cleanly with no existing subscription and sends nothing', async () => {
    const { environment, calls } = fakeEnvironment({ existing: null })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.disable(sender)

    expect(ok).toBe(true)
    expect(store.subscribed.get()).toBe(false)
    expect(calls.unsubscribe).toBe(1)
    expect(sent).toEqual([])
  })

  it('clears back to defaults', async () => {
    const { environment } = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const store = createHilosPushSubscriptionStore(environment)
    await store.refresh()

    store.clear()

    expect(store.supported.get()).toBe(false)
    expect(store.permission.get()).toBe('default')
    expect(store.subscribed.get()).toBe(false)
    expect(store.busy.get()).toBe(false)
  })
})
