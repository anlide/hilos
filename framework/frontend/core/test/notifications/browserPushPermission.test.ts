import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createBrowserPushEnvironment,
  type HilosPushPermission,
} from '../../src/notifications/pushSubscription.js'

const disposers: Array<() => void> = []

afterEach(() => {
  for (const stop of disposers.splice(0)) stop()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

/** Browser globals with a real event target and no prompt or push service. */
function stubBrowser() {
  const status = Object.assign(new EventTarget(), {
    name: 'notifications' as PermissionName,
    state: 'prompt' as PermissionState,
    onchange: vi.fn(),
  }) satisfies PermissionStatus
  const query = vi
    .fn<(descriptor: PermissionDescriptor) => Promise<PermissionStatus>>()
    .mockResolvedValue(status)
  const notification = {
    permission: 'default' as NotificationPermission,
    requestPermission: vi.fn(),
  }
  const pushManager = {
    getSubscription: vi.fn(async () => null),
    subscribe: vi.fn(),
  }
  const serviceWorker = {
    register: vi.fn(async () => ({ pushManager })),
  }
  const navigator = { serviceWorker, permissions: { query } }
  vi.stubGlobal('navigator', navigator)
  vi.stubGlobal('Notification', notification)
  vi.stubGlobal('window', { Notification: notification, PushManager: class {} })
  const environment = createBrowserPushEnvironment()

  return {
    environment,
    navigator,
    notification,
    status,
    query,
    serviceWorker,
    pushManager,
    watch(listener: () => void) {
      const stop = environment.watchPermission!(listener)
      disposers.push(stop)

      return stop
    },
  }
}

/** Resolve the Permissions query explicitly, without a timer or browser task. */
function pendingStatus() {
  let resolve!: (status: PermissionStatus) => void
  const promise = new Promise<PermissionStatus>((fulfill) => {
    resolve = fulfill
  })

  return { promise, resolve }
}

describe('browser push permission observation', () => {
  it('constructs without reading browser globals', () => {
    const read = vi.fn(() => {
      throw new Error('browser globals are unavailable')
    })
    for (const name of ['window', 'navigator', 'Notification']) {
      vi.stubGlobal(name, undefined)
      Object.defineProperty(globalThis, name, {
        configurable: true,
        get: read,
      })
    }

    const environment = createBrowserPushEnvironment()

    expect(environment.watchPermission).toBeTypeOf('function')
    expect(read).not.toHaveBeenCalled()
  })

  it('queries notifications lazily and reads Notification permission on change', async () => {
    const browser = stubBrowser()
    const seen: HilosPushPermission[] = []
    const onchange = browser.status.onchange
    expect(browser.query).not.toHaveBeenCalled()

    browser.watch(() => seen.push(browser.environment.permission()))
    expect(browser.query).toHaveBeenCalledExactlyOnceWith({
      name: 'notifications',
    })
    await Promise.resolve()
    expect(seen).toEqual([])

    browser.status.dispatchEvent(new Event('change'))
    expect(seen).toEqual(['default'])
    browser.notification.permission = 'denied'
    browser.status.dispatchEvent(new Event('change'))

    expect(seen).toEqual(['default', 'denied'])
    expect(browser.status.onchange).toBe(onchange)
    expect(browser.notification.requestPermission).not.toHaveBeenCalled()
    expect(browser.serviceWorker.register).not.toHaveBeenCalled()
    expect(browser.pushManager.getSubscription).not.toHaveBeenCalled()
    expect(browser.pushManager.subscribe).not.toHaveBeenCalled()
  })

  it('reconciles permission changed while the query was pending', async () => {
    const browser = stubBrowser()
    const pending = pendingStatus()
    browser.query.mockReturnValueOnce(pending.promise)
    const seen: HilosPushPermission[] = []
    browser.watch(() => seen.push(browser.environment.permission()))

    browser.notification.permission = 'granted'
    pending.resolve(browser.status)
    await pending.promise

    expect(seen).toEqual(['granted'])
    browser.notification.permission = 'denied'
    browser.status.dispatchEvent(new Event('change'))
    expect(seen).toEqual(['granted', 'denied'])
  })

  it.each([undefined, {}])(
    'keeps observation optional when the Permissions API is %j',
    async (permissions) => {
      const browser = stubBrowser()
      vi.stubGlobal('navigator', { ...browser.navigator, permissions })
      const listener = vi.fn()

      const stop = browser.watch(listener)
      await Promise.resolve()
      stop()
      stop()

      expect(listener).not.toHaveBeenCalled()
      expect(browser.query).not.toHaveBeenCalled()
      expect(browser.environment.permission()).toBe('default')
      await expect(browser.environment.getSubscription()).resolves.toBeNull()
    },
  )

  it('does not query permissions in an unsupported browser', async () => {
    const browser = stubBrowser()
    vi.stubGlobal('window', {})
    const listener = vi.fn()

    const stop = browser.watch(listener)
    await Promise.resolve()
    stop()
    stop()

    expect(browser.environment.permission()).toBe('unsupported')
    expect(browser.query).not.toHaveBeenCalled()
    expect(listener).not.toHaveBeenCalled()
  })

  it.each(['throw', 'reject'])(
    'isolates a query %s and allows a later observation',
    async (failure) => {
      const browser = stubBrowser()
      browser.query.mockImplementationOnce(() => {
        const error = new Error('notifications observation is unavailable')
        if (failure === 'throw') throw error

        return Promise.reject(error)
      })
      const listener = vi.fn()

      const stop = browser.watch(listener)
      await Promise.resolve()
      expect(listener).not.toHaveBeenCalled()
      expect(browser.query).toHaveBeenCalledTimes(1)
      expect(browser.environment.permission()).toBe('default')
      await expect(browser.environment.getSubscription()).resolves.toBeNull()
      stop()
      stop()

      browser.watch(listener)
      await Promise.resolve()
      browser.status.dispatchEvent(new Event('change'))
      expect(browser.query).toHaveBeenCalledTimes(2)
      expect(listener).toHaveBeenCalledTimes(1)
    },
  )

  it('does not attach or notify when stopped before the query resolves', async () => {
    const browser = stubBrowser()
    const pending = pendingStatus()
    browser.query.mockReturnValueOnce(pending.promise)
    const attach = vi.spyOn(browser.status, 'addEventListener')
    const listener = vi.fn()

    const stop = browser.watch(listener)
    stop()
    stop()
    browser.notification.permission = 'denied'
    pending.resolve(browser.status)
    await pending.promise
    browser.status.dispatchEvent(new Event('change'))

    expect(attach).not.toHaveBeenCalled()
    expect(listener).not.toHaveBeenCalled()
  })

  it('removes only its own listener and can observe again after cleanup', async () => {
    const browser = stubBrowser()
    const other = vi.fn()
    const first = vi.fn()
    const second = vi.fn()
    browser.status.addEventListener('change', other)
    const stopFirst = browser.watch(first)
    const stopSecond = browser.watch(second)
    await Promise.resolve()

    stopFirst()
    stopFirst()
    browser.status.dispatchEvent(new Event('change'))
    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
    expect(other).toHaveBeenCalledTimes(1)

    stopSecond()
    stopSecond()
    browser.status.dispatchEvent(new Event('change'))
    expect(second).toHaveBeenCalledTimes(1)
    expect(other).toHaveBeenCalledTimes(2)

    const next = vi.fn()
    browser.watch(next)
    await Promise.resolve()
    browser.status.dispatchEvent(new Event('change'))
    expect(browser.query).toHaveBeenCalledTimes(3)
    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledTimes(1)
    expect(other).toHaveBeenCalledTimes(3)
  })
})
