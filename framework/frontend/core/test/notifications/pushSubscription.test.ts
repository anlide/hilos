import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosPushSubscriptionStore,
  PUSH_ACTION_SUBSCRIBE,
  PUSH_ACTION_UNSUBSCRIBE,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
} from '../../src/notifications/pushSubscription.js'
import { subscribeSignal } from '../../src/state/signal.js'

/** A subscription snapshot the fake browser hands out. */
const SNAPSHOT: HilosPushSubscriptionSnapshot = {
  endpoint: 'https://push.example/abc',
  p256dh: 'p256dh-key',
  auth: 'auth-secret',
}

const SNAPSHOT_ENDPOINT_HASH =
  'f7a263f8786e9f9540c48a757245e31290708979548c369f207cbcff9e23bb86'

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
  const state = {
    permission: options.permission ?? 'default',
    current: options.existing ?? null,
  }
  const listeners = new Set<() => void>()
  const changePermission = (next: HilosPushPermission) => {
    state.permission = next
    for (const listener of listeners) listener()
  }
  const stopWatching = vi.fn((listener: () => void) => {
    listeners.delete(listener)
  })
  const watchPermission = vi.fn((listener: () => void) => {
    listeners.add(listener)

    return () => stopWatching(listener)
  })
  const calls = {
    requestPermission: 0,
    subscribe: [] as string[],
    unsubscribe: 0,
  }
  const read = vi.fn(async () => {
    if (options.rejectGetSubscription === true) {
      throw new Error('push service unreachable')
    }

    return state.current
  })

  const environment: HilosPushEnvironment = {
    isSupported: () => supported,
    permission: () => state.permission,
    watchPermission,
    userAgent: () => 'FakeAgent/1.0',
    requestPermission: async () => {
      calls.requestPermission += 1
      changePermission(options.grant ?? 'granted')

      return state.permission
    },
    getSubscription: read,
    subscribe: async (key) => {
      calls.subscribe.push(key)
      if (options.rejectSubscribe === true) {
        throw new Error('push service refused the subscription')
      }
      state.current = SNAPSHOT

      return SNAPSHOT
    },
    unsubscribe: async () => {
      calls.unsubscribe += 1
      if (options.rejectUnsubscribe === true) {
        throw new Error('push service unreachable')
      }
      state.current = null
    },
  }

  return {
    environment,
    calls,
    state,
    changePermission,
    read,
    watchPermission,
    stopWatching,
  }
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

/** A browser operation whose completion the scenario controls without a clock. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })

  return { promise, resolve, reject }
}

/** Let the fake read and digest promises publish, without waiting for real time. */
async function flushMicrotasks(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  await Promise.resolve()
}

describe('push subscription store', () => {
  it('refreshes to unsupported when the browser lacks push', async () => {
    const { environment } = fakeEnvironment({ supported: false })
    const store = createHilosPushSubscriptionStore(environment)

    await store.refresh()

    expect(store.supported.get()).toBe(false)
    expect(store.permission.get()).toBe('unsupported')
    expect(store.subscribed.get()).toBe(false)
    expect(store.refreshed.get()).toBe(true)
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
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(store.refreshed.get()).toBe(true)
  })

  it('subscribes and registers the device server-side on enable', async () => {
    const { environment, calls } = fakeEnvironment({ grant: 'granted' })
    const { sender, sent } = fakeSender(true)
    const store = createHilosPushSubscriptionStore(environment)

    const ok = await store.enable(sender, 'vapid-public')

    expect(ok).toBe(true)
    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
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
    expect(store.endpointHash.get()).toBeNull()
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
    expect(store.endpointHash.get()).toBeNull()
    expect(store.refreshed.get()).toBe(false)
    expect(store.busy.get()).toBe(false)
  })
})

describe('push permission observation', () => {
  const cleanups: Array<() => void> = []

  function storeFor(environment: HilosPushEnvironment) {
    const store = createHilosPushSubscriptionStore(environment)
    cleanups.push(() => store.clear())

    return store
  }

  beforeEach(() => {
    // Keep digest completion in the promise queue; the store tests above use
    // real SHA-256 to verify the endpoint fingerprint itself.
    vi.spyOn(crypto.subtle, 'digest').mockResolvedValue(
      Uint8Array.from(SNAPSHOT_ENDPOINT_HASH.match(/../g)!, (hex) =>
        Number.parseInt(hex, 16),
      ).buffer,
    )
  })

  afterEach(() => {
    for (const cleanup of cleanups.splice(0)) cleanup()
    vi.restoreAllMocks()
  })

  it('constructs the store without consulting the environment', () => {
    const browser = fakeEnvironment()
    const support = vi.spyOn(browser.environment, 'isSupported')
    const permission = vi.spyOn(browser.environment, 'permission')
    const store = storeFor(browser.environment)

    expect(store.observePermission).toBeTypeOf('function')
    expect(store.refreshed.get()).toBe(false)
    expect(support).not.toHaveBeenCalled()
    expect(permission).not.toHaveBeenCalled()
    expect(browser.watchPermission).not.toHaveBeenCalled()
    expect(browser.read).not.toHaveBeenCalled()
  })

  it.each([
    { permission: 'default', existing: null, subscribed: false },
    { permission: 'default', existing: SNAPSHOT, subscribed: true },
    { permission: 'granted', existing: null, subscribed: false },
    { permission: 'granted', existing: SNAPSHOT, subscribed: true },
    { permission: 'denied', existing: null, subscribed: false },
    { permission: 'denied', existing: SNAPSHOT, subscribed: false },
  ] as const)(
    'projects $permission and subscription $existing without mutations',
    async ({ permission, existing, subscribed }) => {
      const browser = fakeEnvironment({ permission, existing })
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()

      expect(store.supported.get()).toBe(true)
      expect(store.permission.get()).toBe(permission)
      expect(store.subscribed.get()).toBe(subscribed)
      expect(store.endpointHash.get()).toBe(
        subscribed ? SNAPSHOT_ENDPOINT_HASH : null,
      )
      expect(store.refreshed.get()).toBe(true)
      expect(store.busy.get()).toBe(false)
      expect(browser.calls).toEqual({
        requestPermission: 0,
        subscribe: [],
        unsubscribe: 0,
      })
    },
  )

  it('reconciles permission events without prompting or changing subscriptions', async () => {
    const browser = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const store = storeFor(browser.environment)
    store.observePermission!()
    await flushMicrotasks()

    browser.changePermission('denied')
    expect(store.permission.get()).toBe('denied')
    expect(store.subscribed.get()).toBe(false)
    expect(store.endpointHash.get()).toBeNull()
    expect(store.busy.get()).toBe(false)

    browser.state.current = null
    browser.changePermission('default')
    await flushMicrotasks()
    expect(store.permission.get()).toBe('default')
    expect(store.subscribed.get()).toBe(false)

    browser.state.current = SNAPSHOT
    browser.changePermission('granted')
    await flushMicrotasks()
    expect(store.permission.get()).toBe('granted')
    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(browser.calls).toEqual({
      requestPermission: 0,
      subscribe: [],
      unsubscribe: 0,
    })
  })

  it('shares one watcher and initial read until the last idempotent release', async () => {
    const browser = fakeEnvironment()
    const store = storeFor(browser.environment)
    const first = store.observePermission!()
    const second = store.observePermission!()
    await flushMicrotasks()

    expect(browser.watchPermission).toHaveBeenCalledTimes(1)
    expect(browser.read).toHaveBeenCalledTimes(1)
    first()
    first()
    expect(browser.stopWatching).not.toHaveBeenCalled()

    browser.changePermission('denied')
    expect(store.permission.get()).toBe('denied')
    second()
    second()
    expect(browser.stopWatching).toHaveBeenCalledTimes(1)
    browser.changePermission('granted')
    expect(store.permission.get()).toBe('denied')

    store.observePermission!()
    await flushMicrotasks()
    expect(browser.watchPermission).toHaveBeenCalledTimes(2)
    expect(browser.read).toHaveBeenCalledTimes(2)
    expect(store.permission.get()).toBe('granted')
  })

  it('force-stops on clear and keeps old releases out of a new group', async () => {
    const browser = fakeEnvironment({ permission: 'granted' })
    const store = storeFor(browser.environment)
    const first = store.observePermission!()
    const second = store.observePermission!()
    await flushMicrotasks()

    store.clear()
    expect(browser.stopWatching).toHaveBeenCalledTimes(1)
    expect(store.supported.get()).toBe(false)
    expect(store.permission.get()).toBe('default')
    expect(store.refreshed.get()).toBe(false)

    const current = store.observePermission!()
    first()
    second()
    expect(browser.stopWatching).toHaveBeenCalledTimes(1)
    browser.changePermission('denied')
    expect(store.permission.get()).toBe('denied')
    current()
    expect(browser.stopWatching).toHaveBeenCalledTimes(2)
  })

  it.each(['read', 'hash'] as const)(
    'discards a late %s after a permission event',
    async (phase) => {
      const browser = fakeEnvironment({ permission: 'granted' })
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      const hash = deferred<ArrayBuffer>()
      if (phase === 'read') browser.read.mockReturnValueOnce(read.promise)
      else {
        browser.state.current = SNAPSHOT
        vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      }
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()

      browser.changePermission('denied')
      expect(store.permission.get()).toBe('denied')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      browser.state.current = null
      browser.changePermission('granted')
      await flushMicrotasks()

      read.resolve(SNAPSHOT)
      hash.resolve(new Uint8Array([1]).buffer)
      await flushMicrotasks()
      expect(store.permission.get()).toBe('granted')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
    },
  )

  it.each(['read', 'hash'] as const)(
    'checks live permission before publishing a late %s without an event',
    async (phase) => {
      const browser = fakeEnvironment({
        permission: 'granted',
        existing: SNAPSHOT,
      })
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      const hash = deferred<ArrayBuffer>()
      if (phase === 'read') browser.read.mockReturnValueOnce(read.promise)
      else vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      const store = storeFor(browser.environment)
      const refreshing = store.refresh()
      await flushMicrotasks()

      browser.state.permission = 'denied'
      read.resolve(SNAPSHOT)
      hash.resolve(new Uint8Array([1]).buffer)
      await refreshing

      expect(store.permission.get()).toBe('denied')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
    },
  )

  it.each(['read', 'hash', 'rejection'] as const)(
    'lets a newer refresh supersede a pending %s',
    async (phase) => {
      const browser = fakeEnvironment({
        permission: 'granted',
        existing: SNAPSHOT,
      })
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      const hash = deferred<ArrayBuffer>()
      if (phase === 'hash') {
        vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      } else browser.read.mockReturnValueOnce(read.promise)
      const store = storeFor(browser.environment)
      const old = store.refresh()
      await flushMicrotasks()

      browser.state.current = phase === 'hash' ? null : SNAPSHOT
      await store.refresh()
      if (phase === 'rejection') read.reject(new Error('stale read failed'))
      else read.resolve(null)
      hash.resolve(new Uint8Array([1]).buffer)
      await old

      expect(store.subscribed.get()).toBe(phase !== 'hash')
      expect(store.endpointHash.get()).toBe(
        phase === 'hash' ? null : SNAPSHOT_ENDPOINT_HASH,
      )
      expect(store.refreshed.get()).toBe(true)
    },
  )

  it.each([
    ['release', 'read'],
    ['release', 'hash'],
    ['clear', 'read'],
    ['clear', 'hash'],
  ] as const)('invalidates %s group results during %s', async (end, phase) => {
    const browser = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const read = deferred<HilosPushSubscriptionSnapshot | null>()
    const hash = deferred<ArrayBuffer>()
    if (phase === 'read') browser.read.mockReturnValueOnce(read.promise)
    else vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
    const store = storeFor(browser.environment)
    const release = store.observePermission!()
    await flushMicrotasks()

    if (end === 'clear') store.clear()
    else release()
    read.resolve(SNAPSHOT)
    hash.resolve(new Uint8Array([1]).buffer)
    await flushMicrotasks()

    expect(store.subscribed.get()).toBe(end === 'release' && phase === 'hash')
    expect(store.endpointHash.get()).toBeNull()
    expect(store.refreshed.get()).toBe(false)
    expect(browser.stopWatching).toHaveBeenCalledTimes(1)
    browser.state.current = null
    store.observePermission!()
    await flushMicrotasks()
    expect(store.refreshed.get()).toBe(true)
    expect(store.subscribed.get()).toBe(false)
  })

  it('keeps a new observation intact when the previous group finishes reading', async () => {
    const browser = fakeEnvironment({ permission: 'granted' })
    const read = deferred<HilosPushSubscriptionSnapshot | null>()
    browser.read.mockReturnValueOnce(read.promise)
    const store = storeFor(browser.environment)
    const oldRelease = store.observePermission!()

    oldRelease()
    browser.state.current = SNAPSHOT
    const release = store.observePermission!()
    await flushMicrotasks()
    read.resolve(null)
    await flushMicrotasks()

    oldRelease()
    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(store.refreshed.get()).toBe(true)
    expect(browser.stopWatching).toHaveBeenCalledTimes(1)
    release()
    expect(browser.stopWatching).toHaveBeenCalledTimes(2)
  })

  it.each([
    ['enable', 'read'],
    ['enable', 'hash'],
    ['disable', 'read'],
    ['disable', 'hash'],
  ] as const)(
    'invalidates an old %s refresh during %s',
    async (action, phase) => {
      const browser = fakeEnvironment({
        permission: 'granted',
        existing: SNAPSHOT,
      })
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      const hash = deferred<ArrayBuffer>()
      if (phase === 'read') browser.read.mockReturnValueOnce(read.promise)
      else vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      const store = storeFor(browser.environment)
      const old = store.refresh()
      await flushMicrotasks()
      const { sender } = fakeSender()

      const ok =
        action === 'enable'
          ? await store.enable(sender, 'vapid-public')
          : await store.disable(sender)
      expect(ok).toBe(true)
      read.resolve(action === 'enable' ? null : SNAPSHOT)
      hash.resolve(new Uint8Array([1]).buffer)
      await old

      expect(store.subscribed.get()).toBe(action === 'enable')
      expect(store.endpointHash.get()).toBe(
        action === 'enable' ? SNAPSHOT_ENDPOINT_HASH : null,
      )
    },
  )

  it.each(['enable', 'disable'] as const)(
    'reflects permission immediately and coalesces busy events during %s',
    async (action) => {
      const browser = fakeEnvironment({ permission: 'granted' })
      const gate = deferred<void>()
      if (action === 'enable') {
        vi.spyOn(browser.environment, 'subscribe').mockImplementationOnce(
          async () => {
            await gate.promise
            browser.state.current = SNAPSHOT

            return SNAPSHOT
          },
        )
      } else {
        vi.spyOn(browser.environment, 'unsubscribe').mockImplementationOnce(
          async () => {
            await gate.promise
            browser.state.current = null
          },
        )
      }
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()
      const { sender, sent } = fakeSender()
      const operation =
        action === 'enable'
          ? store.enable(sender, 'vapid-public')
          : store.disable(sender)
      await flushMicrotasks()
      const readsBeforeEvents = browser.read.mock.calls.length

      browser.changePermission('denied')
      expect(store.permission.get()).toBe('denied')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.busy.get()).toBe(true)
      browser.changePermission('default')
      browser.changePermission('granted')
      expect(store.permission.get()).toBe('granted')
      expect(store.refreshed.get()).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(readsBeforeEvents)

      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      browser.read.mockReturnValueOnce(read.promise)
      gate.resolve()
      expect(await operation).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(readsBeforeEvents + 1)
      expect(store.busy.get()).toBe(false)
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      read.resolve(browser.state.current)
      await flushMicrotasks()
      expect(store.subscribed.get()).toBe(action === 'enable')
      expect(store.endpointHash.get()).toBe(
        action === 'enable' ? SNAPSHOT_ENDPOINT_HASH : null,
      )
      expect(sent).toHaveLength(action === 'enable' ? 1 : 0)
    },
  )

  it('keeps an enable valid through its own granted permission event', async () => {
    const browser = fakeEnvironment()
    const store = storeFor(browser.environment)
    store.observePermission!()
    await flushMicrotasks()
    const { sender, sent } = fakeSender()

    expect(await store.enable(sender, 'vapid-public')).toBe(true)
    await flushMicrotasks()

    expect(store.permission.get()).toBe('granted')
    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(browser.calls.requestPermission).toBe(1)
    expect(browser.calls.subscribe).toEqual(['vapid-public'])
    expect(sent).toHaveLength(1)
  })

  it.each(['default', 'granted'] as const)(
    'keeps a revoked enable snapshot absent after permission returns to %s',
    async (permission) => {
      const browser = fakeEnvironment({ permission: 'granted' })
      const hash = deferred<ArrayBuffer>()
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
      const { sender, sent } = fakeSender()
      const enabling = store.enable(sender, 'vapid-public')
      await flushMicrotasks()
      expect(sent).toHaveLength(1)
      expect(store.busy.get()).toBe(true)

      browser.state.current = null
      browser.changePermission('denied')
      expect(store.permission.get()).toBe('denied')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      browser.changePermission(permission)
      browser.read.mockReturnValueOnce(read.promise)
      expect(browser.read).toHaveBeenCalledTimes(1)

      hash.resolve(new Uint8Array([1]).buffer)
      expect(await enabling).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(2)
      expect(store.busy.get()).toBe(false)
      expect(store.permission.get()).toBe(permission)
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)

      read.resolve(null)
      await flushMicrotasks()
      expect(store.permission.get()).toBe(permission)
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(2)
      expect(browser.calls).toEqual({
        requestPermission: 1,
        subscribe: ['vapid-public'],
        unsubscribe: 0,
      })
      expect(sent).toEqual([
        {
          action: PUSH_ACTION_SUBSCRIBE,
          data: { ...SNAPSHOT, userAgent: 'FakeAgent/1.0' },
        },
      ])
    },
  )

  it.each(['default', 'granted'] as const)(
    'keeps a revoked snapshot absent when subscribe resolves after permission returns to %s',
    async (permission) => {
      const browser = fakeEnvironment({ permission: 'granted' })
      const gate = deferred<void>()
      const read = deferred<HilosPushSubscriptionSnapshot | null>()
      const subscribe = vi
        .spyOn(browser.environment, 'subscribe')
        .mockImplementationOnce(async () => {
          browser.state.current = SNAPSHOT
          await gate.promise

          return SNAPSHOT
        })
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()
      expect(store.permission.get()).toBe('granted')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
      const { sender, sent } = fakeSender()
      const enabling = store.enable(sender, 'vapid-public')
      await flushMicrotasks()
      expect(browser.state.current).toBe(SNAPSHOT)
      expect(store.busy.get()).toBe(true)
      expect(sent).toEqual([])

      browser.state.current = null
      browser.changePermission('denied')
      expect(store.permission.get()).toBe('denied')
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      browser.changePermission(permission)
      expect(store.permission.get()).toBe(permission)
      expect(browser.read).toHaveBeenCalledTimes(1)
      browser.read.mockReturnValueOnce(read.promise)

      gate.resolve()
      expect(await enabling).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(2)
      expect(browser.state.current).toBeNull()
      expect(store.busy.get()).toBe(false)
      expect(store.permission.get()).toBe(permission)
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)

      read.resolve(null)
      await flushMicrotasks()
      expect(store.permission.get()).toBe(permission)
      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.refreshed.get()).toBe(true)
      expect(browser.read).toHaveBeenCalledTimes(2)
      expect(browser.calls.requestPermission).toBe(1)
      expect(subscribe).toHaveBeenCalledTimes(1)
      expect(subscribe).toHaveBeenCalledWith('vapid-public')
      expect(browser.calls.unsubscribe).toBe(0)
      expect(sent).toEqual([
        {
          action: PUSH_ACTION_SUBSCRIBE,
          data: { ...SNAPSHOT, userAgent: 'FakeAgent/1.0' },
        },
      ])
    },
  )

  it.each(['denied', 'clear'] as const)(
    'does not publish an enable hash completed after %s',
    async (end) => {
      const browser = fakeEnvironment({ permission: 'granted' })
      const hash = deferred<ArrayBuffer>()
      vi.mocked(crypto.subtle.digest).mockReturnValueOnce(hash.promise)
      const store = storeFor(browser.environment)
      store.observePermission!()
      await flushMicrotasks()
      const { sender, sent } = fakeSender()
      const enabling = store.enable(sender, 'vapid-public')
      await flushMicrotasks()
      expect(sent).toHaveLength(1)

      if (end === 'clear') store.clear()
      else browser.changePermission('denied')
      hash.resolve(new Uint8Array([1]).buffer)
      expect(await enabling).toBe(true)
      await flushMicrotasks()

      expect(store.subscribed.get()).toBe(false)
      expect(store.endpointHash.get()).toBeNull()
      expect(store.permission.get()).toBe(
        end === 'clear' ? 'default' : 'denied',
      )
      expect(store.refreshed.get()).toBe(end !== 'clear')
      expect(store.busy.get()).toBe(false)
      expect(browser.calls.unsubscribe).toBe(0)
    },
  )

  it('keeps a late mutation completion from releasing a new busy operation after clear', async () => {
    const browser = fakeEnvironment({ permission: 'granted' })
    const oldSubscribe = deferred<HilosPushSubscriptionSnapshot>()
    const newUnsubscribe = deferred<void>()
    vi.spyOn(browser.environment, 'subscribe').mockReturnValueOnce(
      oldSubscribe.promise,
    )
    vi.spyOn(browser.environment, 'unsubscribe').mockReturnValueOnce(
      newUnsubscribe.promise,
    )
    const store = storeFor(browser.environment)
    const { sender } = fakeSender()
    const enabling = store.enable(sender, 'vapid-public')
    await flushMicrotasks()
    store.clear()
    const disabling = store.disable(sender)
    await flushMicrotasks()

    oldSubscribe.resolve(SNAPSHOT)
    expect(await enabling).toBe(true)
    expect(store.busy.get()).toBe(true)
    expect(store.permission.get()).toBe('default')
    expect(store.subscribed.get()).toBe(false)
    expect(store.endpointHash.get()).toBeNull()
    newUnsubscribe.resolve()
    expect(await disabling).toBe(true)
    expect(store.busy.get()).toBe(false)
  })

  it('keeps a new observation intact when a disable finishes after clear', async () => {
    const browser = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const unsubscribe = deferred<void>()
    vi.spyOn(browser.environment, 'unsubscribe').mockReturnValueOnce(
      unsubscribe.promise,
    )
    const store = storeFor(browser.environment)
    const { sender, sent } = fakeSender()
    const disabling = store.disable(sender)
    await flushMicrotasks()

    store.clear()
    store.observePermission!()
    await flushMicrotasks()
    unsubscribe.resolve()
    expect(await disabling).toBe(true)

    expect(store.permission.get()).toBe('granted')
    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(store.refreshed.get()).toBe(true)
    expect(sent).toEqual([
      {
        action: PUSH_ACTION_UNSUBSCRIBE,
        data: { endpoint: SNAPSHOT.endpoint },
      },
    ])
  })

  it('keeps refreshed true through later events and a rejected read until clear', async () => {
    const browser = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const store = storeFor(browser.environment)
    store.observePermission!()
    await flushMicrotasks()
    const seen: boolean[] = []
    cleanups.push(subscribeSignal(store.refreshed, (value) => seen.push(value)))
    const read = deferred<HilosPushSubscriptionSnapshot | null>()
    browser.read.mockReturnValueOnce(read.promise)

    browser.changePermission('default')
    expect(store.refreshed.get()).toBe(true)
    read.reject(new Error('push service unreachable'))
    await flushMicrotasks()
    expect(store.subscribed.get()).toBe(false)
    expect(store.endpointHash.get()).toBeNull()
    expect(store.refreshed.get()).toBe(true)
    expect(store.busy.get()).toBe(false)
    browser.changePermission('denied')
    expect(seen).toEqual([])
    expect(browser.calls).toEqual({
      requestPermission: 0,
      subscribe: [],
      unsubscribe: 0,
    })

    store.clear()
    expect(seen).toEqual([false])
  })

  it('supports an initial observation read with a legacy environment', async () => {
    const browser = fakeEnvironment({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    delete browser.environment.watchPermission
    const store = storeFor(browser.environment)
    const release = store.observePermission!()
    await flushMicrotasks()

    expect(store.subscribed.get()).toBe(true)
    expect(store.endpointHash.get()).toBe(SNAPSHOT_ENDPOINT_HASH)
    expect(store.refreshed.get()).toBe(true)
    expect(browser.read).toHaveBeenCalledTimes(1)
    expect(browser.watchPermission).not.toHaveBeenCalled()
    release()
    release()
  })
})
