import {
  createHilosPushSubscriptionStore,
  HILOS_NOTIFICATION_PREFERENCES_COPY as COPY,
  PROFILE_DEVICE_CREATED_AT_FIELD,
  PROFILE_DEVICE_ENDPOINT_HASH_FIELD,
  PROFILE_DEVICE_GONE_AT_FIELD,
  PROFILE_DEVICE_ID_FIELD,
  PROFILE_DEVICE_NAME_FIELD,
  resolveHilosProfileDevices,
  subscribeSignal,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import type { expect, it, vi } from 'vitest'

import type {
  ProfileDeviceMountProps,
  ProfileDeviceViewAdapter,
} from './profileDeviceRemoveCases.js'

/** Local Vitest functions supplied by each SDK's discovery entry. */
export interface PushPermissionCasesRunner {
  readonly it: typeof it
  readonly expect: typeof expect
  readonly vi: typeof vi
}

/** Props passed unchanged to the real push toggle. */
export interface PushPermissionToggleProps {
  readonly connection: HilosConnection
  readonly channel: string
  readonly label: string
  readonly vapidPublicKey: string
  readonly store: HilosPushSubscriptionStore
  readonly channelRow: boolean
}

/** Only DOM access, reactive flushing and teardown belong to the SDK adapter. */
export interface PushPermissionToggleView {
  readonly container: HTMLElement
  /** Run a browser or DOM event inside the SDK's reactive boundary. */
  event(run: () => void): Promise<void>
  flush(): Promise<void>
  unmount(): Promise<void>
}

/** Mount one real SDK toggle without supplying product behavior or expectations. */
export type PushPermissionToggleMount = (
  props: PushPermissionToggleProps,
) => Promise<PushPermissionToggleView>

/** Reuse the removal adapter, adding only the SDK's event boundary. */
export type PushPermissionDeviceMount = (
  props?: Partial<ProfileDeviceMountProps>,
) => Promise<ProfileDeviceViewAdapter & Pick<PushPermissionToggleView, 'event'>>

const CHANNEL = {
  channel: 'push',
  label: 'Push on this device',
  vapidPublicKey: 'vapid-public-key',
}
const SNAPSHOT: HilosPushSubscriptionSnapshot = {
  endpoint: 'https://push.example/first',
  p256dh: 'public-key',
  auth: 'auth-secret',
}
const NEXT_SNAPSHOT = { ...SNAPSHOT, endpoint: 'https://push.example/next' }
const FIRST_HASH = '01'.repeat(32)
const NEXT_HASH = '02'.repeat(32)
const TOGGLE = '[data-id="hilos-notification-preference-toggle-push"]'
const PLACEHOLDER = '[data-id="profile-device-none-this"]'
const ROW = '[data-id="profile-device-row"]'
const CURRENT = '[data-id="profile-device-this"]'
const REMOVE = '[data-id="profile-device-remove"]'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((finish) => {
    resolve = finish
  })
  return { promise, resolve }
}

function browserFixture(
  runner: PushPermissionCasesRunner,
  permission: HilosPushPermission = 'denied',
  snapshot: HilosPushSubscriptionSnapshot | null = null,
) {
  const state = { permission, snapshot, supported: true }
  const listeners = new Set<() => void>()
  const stop = runner.vi.fn()
  const read = runner.vi.fn(async () => state.snapshot)
  const watch = runner.vi.fn((listener: () => void) => {
    listeners.add(listener)
    return () => {
      listeners.delete(listener)
      stop()
    }
  })
  const requestPermission = runner.vi.fn(async () => state.permission)
  const subscribe = runner.vi.fn(async () => SNAPSHOT)
  const unsubscribe = runner.vi.fn(async () => {})
  const sendAction = runner.vi.fn(() => true)
  const removeDevice = runner.vi.fn()
  const connection = { sendAction } as unknown as HilosConnection
  // SHA-256 itself is covered in core. Keep these two known fingerprints in the
  // promise queue so component cases never wait on native crypto or real time.
  const digest = runner.vi
    .spyOn(crypto.subtle, 'digest')
    .mockImplementation(
      async (_algorithm, input) =>
        new Uint8Array(32).fill(
          new TextDecoder().decode(input as ArrayBuffer) === SNAPSHOT.endpoint
            ? 1
            : 2,
        ).buffer,
    )
  const environment: HilosPushEnvironment = {
    isSupported: () => state.supported,
    permission: () => state.permission,
    watchPermission: watch,
    userAgent: () => 'Same browser label',
    getSubscription: read,
    requestPermission,
    subscribe,
    unsubscribe,
  }
  const store = createHilosPushSubscriptionStore(environment)

  return {
    state,
    store,
    connection,
    read,
    watch,
    stop,
    requestPermission,
    subscribe,
    unsubscribe,
    sendAction,
    removeDevice,
    change(next: HilosPushPermission) {
      state.permission = next
      for (const listener of listeners) listener()
    },
    dispose() {
      store.clear()
      digest.mockRestore()
    },
  }
}

type BrowserFixture = ReturnType<typeof browserFixture>

function toggleProps(browser: BrowserFixture, channelRow = false) {
  return {
    ...CHANNEL,
    connection: browser.connection,
    store: browser.store,
    channelRow,
  }
}

function expectNoMutations(
  runner: PushPermissionCasesRunner,
  browser: BrowserFixture,
): void {
  for (const action of [
    browser.requestPermission,
    browser.subscribe,
    browser.unsubscribe,
    browser.sendAction,
    browser.removeDevice,
  ]) {
    runner.expect(action).not.toHaveBeenCalled()
  }
}

function expectToggle(
  runner: PushPermissionCasesRunner,
  container: HTMLElement,
  checked: boolean,
  blocked: boolean,
): void {
  const input = container.querySelector<HTMLInputElement>(TOGGLE)
  runner.expect(input).not.toBeNull()
  runner.expect(input?.checked).toBe(checked)
  runner.expect(input?.disabled).toBe(blocked)
  runner.expect(input?.getAttribute('role')).toBe('switch')
  const label = container.querySelector(`label[for="${input?.id}"]`)
  runner.expect(label?.textContent?.trim()).toBe(CHANNEL.label)
  const hint = container.querySelector(
    '[data-id="hilos-notification-preference-hint-push"]',
  )
  if (blocked) {
    runner.expect(hint?.textContent?.trim()).toBe(COPY.pushBlocked)
    runner.expect(hint?.id).toBeTruthy()
    runner.expect(input?.getAttribute('aria-describedby')).toBe(hint?.id)
  } else {
    runner.expect(hint).toBeNull()
  }
}

function deviceRow(id: number, endpointHash: string, expired = false) {
  return {
    [PROFILE_DEVICE_ID_FIELD]: id,
    [PROFILE_DEVICE_NAME_FIELD]: 'Same browser label',
    [PROFILE_DEVICE_ENDPOINT_HASH_FIELD]: endpointHash,
    [PROFILE_DEVICE_CREATED_AT_FIELD]: 1767227400000,
    [PROFILE_DEVICE_GONE_AT_FIELD]: expired ? 1767227460000 : null,
  }
}

// The scenario supplies the same reactive projection as a project's computed
// devices list. SDK adapters only apply the resulting props to real components.
async function mountResolvedDevices(
  mount: PushPermissionDeviceMount,
  browser: BrowserFixture,
  initialRows = [deviceRow(1, FIRST_HASH)],
  pushChannel: typeof CHANNEL | null = CHANNEL,
) {
  let rows = initialRows
  const project = () =>
    resolveHilosProfileDevices(rows, browser.store.endpointHash.get())
  const view = await mount({
    connection: browser.connection,
    pushStore: browser.store,
    actions: { removeDevice: browser.removeDevice },
    pushChannel,
    devices: project(),
  })
  let projectionChanged = false
  const release = subscribeSignal(browser.store.endpointHash, () => {
    projectionChanged = true
  })
  await view.update({ devices: project() })

  return {
    ...view,
    async setRows(next: typeof rows) {
      rows = next
      await view.update({ devices: project() })
    },
    async flush() {
      await view.flush()
      if (projectionChanged) {
        projectionChanged = false
        await view.update({ devices: project() })
      }
      await view.flush()
    },
    async unmount() {
      release()
      await view.unmount()
    },
  }
}

/** Register live permission, accessibility and observation lifecycle scenarios. */
export function registerPushPermissionToggleCases(
  runner: PushPermissionCasesRunner,
  mount: PushPermissionToggleMount,
): void {
  for (const channelRow of [false, true]) {
    runner.it(
      `unblocks a live ${channelRow ? 'channel row' : 'compact toggle'} without subscribing`,
      async () => {
        const browser = browserFixture(runner)
        const view = await mount(toggleProps(browser, channelRow))
        try {
          await view.flush()
          expectToggle(runner, view.container, false, true)
          // Measuring copies stay out of the accessibility tree and carry no ids.
          for (const text of [COPY.pushBlocked, COPY.pushUnsupported]) {
            const twins = Array.from(
              view.container.querySelectorAll('[inert]'),
            ).filter((node) => node.textContent?.trim() === text)
            runner.expect(twins).toHaveLength(1)
            const twin = twins[0]!
            runner.expect(twin.getAttribute('aria-hidden')).toBe('true')
            runner.expect(twin.hasAttribute('id')).toBe(false)
            runner.expect(twin.hasAttribute('data-id')).toBe(false)
            runner
              .expect(twin.querySelector('[id], [data-id], input, a, button'))
              .toBeNull()
          }
          await view.event(() => browser.change('default'))
          await view.flush()
          expectToggle(runner, view.container, false, false)
          await view.event(() => browser.change('granted'))
          await view.flush()
          expectToggle(runner, view.container, false, false)
          runner.expect(browser.watch).toHaveBeenCalledTimes(1)
          expectNoMutations(runner, browser)
        } finally {
          await view.unmount()
          browser.dispose()
        }
      },
    )
  }

  runner.it(
    'unchecks a revoked subscription and ignores its stale browser read',
    async () => {
      const browser = browserFixture(runner, 'granted', SNAPSHOT)
      const view = await mount(toggleProps(browser))
      try {
        await view.flush()
        expectToggle(runner, view.container, true, false)
        const stale = deferred<HilosPushSubscriptionSnapshot | null>()
        browser.read.mockReturnValueOnce(stale.promise)
        await view.event(() => {
          browser.change('granted')
          browser.change('denied')
        })
        await view.flush()
        expectToggle(runner, view.container, false, true)
        await view.event(() => stale.resolve(SNAPSHOT))
        await view.flush()
        expectToggle(runner, view.container, false, true)
        runner.expect(browser.store.endpointHash.get()).toBeNull()
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'shares observation across mounts and releases it only after the last unmount',
    async () => {
      const browser = browserFixture(runner, 'granted')
      const first = await mount(toggleProps(browser))
      const second = await mount(toggleProps(browser))
      let firstMounted = true
      let secondMounted = true
      try {
        await first.flush()
        await second.flush()
        runner.expect(browser.watch).toHaveBeenCalledTimes(1)
        runner.expect(browser.read).toHaveBeenCalledTimes(1)
        await first.unmount()
        firstMounted = false
        runner.expect(browser.stop).not.toHaveBeenCalled()
        await second.event(() => browser.change('denied'))
        await second.flush()
        expectToggle(runner, second.container, false, true)
        const listener = browser.watch.mock.calls[0]![0]
        await second.unmount()
        secondMounted = false
        runner.expect(browser.stop).toHaveBeenCalledTimes(1)
        browser.state.permission = 'granted'
        listener()
        runner.expect(browser.store.permission.get()).toBe('denied')
        const remounted = await mount(toggleProps(browser))
        try {
          await remounted.flush()
          expectToggle(runner, remounted.container, false, false)
          runner.expect(browser.watch).toHaveBeenCalledTimes(2)
          runner.expect(browser.read).toHaveBeenCalledTimes(2)
        } finally {
          await remounted.unmount()
        }
        runner.expect(browser.stop).toHaveBeenCalledTimes(2)
        expectNoMutations(runner, browser)
      } finally {
        if (firstMounted) await first.unmount()
        if (secondMounted) await second.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'refreshes a legacy injected store without the optional observer on mount',
    async () => {
      const browser = browserFixture(runner)
      delete browser.store.observePermission
      const refresh = runner.vi.spyOn(browser.store, 'refresh')
      const view = await mount(toggleProps(browser))
      try {
        await view.flush()
        expectToggle(runner, view.container, false, true)
        runner.expect(refresh).toHaveBeenCalledTimes(1)
        runner.expect(browser.watch).not.toHaveBeenCalled()
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )
}

/** Register device-list scenarios over the removal adapter plus an event boundary. */
export function registerProfileDevicePushPermissionCases(
  runner: PushPermissionCasesRunner,
  mount: PushPermissionDeviceMount,
): void {
  runner.it(
    'refreshes a legacy Devices store on mount without requiring observation',
    async () => {
      const browser = browserFixture(runner, 'granted')
      delete browser.store.observePermission
      const refresh = runner.vi.spyOn(browser.store, 'refresh')
      const view = await mountResolvedDevices(mount, browser, [], null)
      try {
        await view.flush()
        runner.expect(refresh).toHaveBeenCalledTimes(1)
        runner.expect(browser.read).toHaveBeenCalledTimes(1)
        runner.expect(browser.watch).not.toHaveBeenCalled()
        runner.expect(browser.store.refreshed.get()).toBe(true)
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'observes denied permission in Devices even while no toggle is mounted',
    async () => {
      const browser = browserFixture(runner)
      const view = await mountResolvedDevices(mount, browser, [], null)
      try {
        runner.expect(view.container.querySelector(TOGGLE)).toBeNull()
        runner.expect(browser.watch).toHaveBeenCalledTimes(1)
        await view.event(() => browser.change('granted'))
        await view.flush()
        runner.expect(browser.store.permission.get()).toBe('granted')
        runner.expect(browser.read).toHaveBeenCalledTimes(1)
        await view.update({ pushChannel: CHANNEL })
        await view.flush()
        runner.expect(view.container.querySelector(PLACEHOLDER)).not.toBeNull()
        expectToggle(runner, view.container, false, false)
        runner.expect(browser.watch).toHaveBeenCalledTimes(1)
        runner.expect(browser.read).toHaveBeenCalledTimes(1)
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'keeps registered and expired destinations when the current endpoint is lost, without changing an open Remove dialog',
    async () => {
      const browser = browserFixture(runner, 'granted', SNAPSHOT)
      const view = await mountResolvedDevices(mount, browser, [
        deviceRow(1, FIRST_HASH),
        deviceRow(2, NEXT_HASH, true),
      ])
      try {
        await view.flush()
        runner.expect(view.container.querySelectorAll(CURRENT)).toHaveLength(1)
        expectToggle(runner, view.container, true, false)
        await view.event(() => {
          view.container.querySelector<HTMLButtonElement>(REMOVE)!.click()
        })
        await view.flush()
        const dialog = view.container.querySelector('[role="dialog"]')
        runner.expect(dialog).not.toBeNull()

        browser.state.snapshot = null
        await view.event(() => browser.change('denied'))
        await view.flush()
        const rows = view.container.querySelectorAll(ROW)
        runner.expect(rows).toHaveLength(2)
        runner.expect(rows[0]?.textContent).toContain('Registered · added')
        runner.expect(rows[0]?.querySelector(REMOVE)).not.toBeNull()
        runner.expect(rows[1]?.textContent).toContain('Subscription expired')
        runner.expect(rows[1]?.textContent).not.toContain('Registered · added')
        runner.expect(rows[1]?.querySelector(REMOVE)).not.toBeNull()
        runner.expect(view.container.querySelector(CURRENT)).toBeNull()
        runner
          .expect(view.container.querySelector(PLACEHOLDER)?.textContent)
          .toContain('This browser · Not subscribed')
        expectToggle(runner, view.container, false, true)
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBe(dialog)
        runner
          .expect(dialog?.textContent)
          .toContain('Remove Same browser label?')
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'marks only a new endpoint registration current even when device names match',
    async () => {
      const browser = browserFixture(runner, 'granted', SNAPSHOT)
      const view = await mountResolvedDevices(mount, browser)
      try {
        await view.flush()
        runner.expect(view.container.querySelectorAll(CURRENT)).toHaveLength(1)
        browser.state.snapshot = null
        await view.event(() => browser.change('denied'))
        await view.flush()
        runner.expect(view.container.querySelector(CURRENT)).toBeNull()
        browser.state.snapshot = NEXT_SNAPSHOT
        await view.event(() => browser.change('granted'))
        await view.flush()
        runner.expect(view.container.querySelector(CURRENT)).toBeNull()
        await view.setRows([deviceRow(1, FIRST_HASH), deviceRow(2, NEXT_HASH)])
        await view.flush()
        const rows = view.container.querySelectorAll(ROW)
        runner.expect(rows).toHaveLength(2)
        runner.expect(rows[0]?.querySelector(CURRENT)).toBeNull()
        runner.expect(rows[0]?.querySelector(REMOVE)).not.toBeNull()
        runner
          .expect(rows[1]?.querySelector(CURRENT)?.textContent)
          .toBe('this one')
        runner.expect(rows[1]?.querySelector(REMOVE)).toBeNull()
        for (const row of rows) {
          runner.expect(row.textContent).toContain('Registered · added')
        }
        runner.expect(view.container.querySelector(PLACEHOLDER)).toBeNull()
        expectToggle(runner, view.container, true, false)
        expectNoMutations(runner, browser)
      } finally {
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'keeps the placeholder policy before the first read, with the channel off and without browser support',
    async () => {
      const browser = browserFixture(runner, 'granted')
      const initialRead = deferred<HilosPushSubscriptionSnapshot | null>()
      browser.read.mockReturnValueOnce(initialRead.promise)
      const view = await mountResolvedDevices(mount, browser)
      try {
        runner.expect(view.container.querySelector(PLACEHOLDER)).toBeNull()
        runner
          .expect(
            view.container.querySelector<HTMLButtonElement>(REMOVE)?.disabled,
          )
          .toBe(true)
        await view.event(() => initialRead.resolve(null))
        await view.flush()
        runner.expect(view.container.querySelector(PLACEHOLDER)).not.toBeNull()
        await view.update({ pushChannel: null })
        runner.expect(view.container.querySelector(PLACEHOLDER)).toBeNull()
        await view.update({ pushChannel: CHANNEL })
        runner.expect(view.container.querySelector(PLACEHOLDER)).not.toBeNull()
        browser.state.supported = false
        await view.event(() => browser.change('granted'))
        await view.flush()
        runner.expect(view.container.querySelector(PLACEHOLDER)).toBeNull()
        runner.expect(view.container.querySelectorAll(ROW)).toHaveLength(1)
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()
        expectNoMutations(runner, browser)
      } finally {
        initialRead.resolve(null)
        await view.unmount()
        browser.dispose()
      }
    },
  )

  runner.it(
    'shares the Devices observation with nested toggles and other mounted Devices views',
    async () => {
      const browser = browserFixture(runner, 'granted')
      const first = await mountResolvedDevices(mount, browser, [])
      const second = await mountResolvedDevices(mount, browser, [])
      let firstMounted = true
      let secondMounted = true
      try {
        await first.flush()
        await second.flush()
        runner.expect(browser.watch).toHaveBeenCalledTimes(1)
        runner.expect(browser.read).toHaveBeenCalledTimes(1)
        await first.unmount()
        firstMounted = false
        runner.expect(browser.stop).not.toHaveBeenCalled()
        await second.event(() => browser.change('denied'))
        await second.flush()
        expectToggle(runner, second.container, false, true)
        await second.unmount()
        secondMounted = false
        runner.expect(browser.stop).toHaveBeenCalledTimes(1)
        expectNoMutations(runner, browser)
      } finally {
        if (firstMounted) await first.unmount()
        if (secondMounted) await second.unmount()
        browser.dispose()
      }
    },
  )
}
