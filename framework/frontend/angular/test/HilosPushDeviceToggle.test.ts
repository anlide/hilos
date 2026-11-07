// Covers the Angular per-device push switch (HIL-199) in both shapes: the
// compact switch the Devices section mounts and the delivery-channel row
// (HIL-1658) — what the person reads and can do, which store it reads and calls,
// and that its subscriptions end with the component or with a replaced store.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosPushSubscriptionStore,
  createSignal,
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosPushDeviceToggle } from '../src/HilosPushDeviceToggle.js'
import {
  registerPushPermissionToggleCases,
  type PushPermissionToggleMount,
} from '../../test/pushPermissionCases.js'

afterEach(() => {
  TestBed.resetTestingModule()
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

// A subscription snapshot the fake browser reports as this device's current one.
const SNAPSHOT: HilosPushSubscriptionSnapshot = {
  endpoint: 'https://push.example/abc',
  p256dh: 'p256dh-key',
  auth: 'auth-secret',
}

interface FakeEnvironmentOptions {
  supported?: boolean
  permission?: HilosPushPermission
  existing?: HilosPushSubscriptionSnapshot | null
}

// A fake browser push surface so the store reflects a known device state; the
// component test only cares about what the store exposes, not the browser dance.
function fakeEnvironment(
  options: FakeEnvironmentOptions = {},
): HilosPushEnvironment {
  return {
    isSupported: () => options.supported ?? true,
    permission: () => options.permission ?? 'default',
    userAgent: () => 'FakeAgent/1.0',
    requestPermission: async () => 'granted',
    getSubscription: async () => options.existing ?? null,
    subscribe: async () => SNAPSHOT,
    unsubscribe: async () => {},
  }
}

// A minimal connection stub: the toggle only ever hands it to the store.
function fakeConnection(): HilosConnection {
  return {
    sendAction: vi.fn().mockReturnValue(true),
  } as unknown as HilosConnection
}

// A store already reflecting the given device state, so the first render is
// deterministic; the component's initial observation re-reads the same values.
async function storeReflecting(
  options: FakeEnvironmentOptions = {},
): Promise<HilosPushSubscriptionStore> {
  const store = createHilosPushSubscriptionStore(fakeEnvironment(options))
  await store.refresh()

  return store
}

// A typed store whose signals the test writes by hand and whose calls it counts.
function handStore(subscribed: boolean) {
  const signals = {
    supported: createSignal(true),
    permission: createSignal<HilosPushPermission>('granted'),
    subscribed: createSignal(subscribed),
    busy: createSignal(false),
  }
  const store: HilosPushSubscriptionStore = {
    ...signals,
    endpointHash: createSignal<string | null>(null),
    refreshed: createSignal(true),
    refresh: vi.fn(async () => {}),
    enable: vi.fn(async () => true),
    disable: vi.fn(async () => true),
    clear: vi.fn(),
  }

  return { store, signals }
}

interface MountOptions {
  channel?: string
  label?: string
  channelRow?: boolean
  icon?: string
}

// Every input goes in through setInput before the first change detection, as a
// parent template binds it.
async function mountToggle(
  store: HilosPushSubscriptionStore,
  connection: HilosConnection,
  options: MountOptions = {},
): Promise<ComponentFixture<HilosPushDeviceToggle>> {
  const fixture = TestBed.createComponent(HilosPushDeviceToggle)
  fixture.componentRef.setInput('connection', connection)
  fixture.componentRef.setInput('channel', options.channel ?? 'push')
  fixture.componentRef.setInput('label', options.label ?? 'Push on this device')
  fixture.componentRef.setInput('vapidPublicKey', 'vapid-public-key')
  fixture.componentRef.setInput('store', store)
  if (options.channelRow !== undefined) {
    fixture.componentRef.setInput('channelRow', options.channelRow)
  }
  if (options.icon !== undefined) {
    fixture.componentRef.setInput('icon', options.icon)
  }
  fixture.detectChanges()
  await settle(fixture)

  return fixture
}

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function byId(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

// The one switch inside the given root.
function switchIn(root: ParentNode): HTMLInputElement {
  const switches = root.querySelectorAll<HTMLInputElement>(
    'input[role="switch"]',
  )
  expect(switches).toHaveLength(1)

  return switches[0]
}

// The switch's accessible name: the text of the label pointing at it.
function nameOf(toggle: HTMLInputElement): string | null {
  return (
    document.querySelector(`label[for="${toggle.id}"]`)?.textContent?.trim() ??
    null
  )
}

// The switch's description: the element its aria-describedby names.
function describedText(toggle: HTMLInputElement): string | null {
  const id = toggle.getAttribute('aria-describedby')
  if (id === null) {
    return null
  }

  return document.getElementById(id)?.textContent?.trim() ?? null
}

// Every id is unique, and every measuring twin is hidden, inert and carries no
// id, data-id or interactive element of its own.
function expectUniqueIdsAndInertTwins(): void {
  const ids = [...document.querySelectorAll('[id]')].map((node) => node.id)
  expect(new Set(ids).size).toBe(ids.length)
  for (const twin of document.querySelectorAll('[inert]')) {
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.hasAttribute('id')).toBe(false)
    expect(twin.hasAttribute('data-id')).toBe(false)
    expect(twin.querySelector('[id], [data-id], input, a, button')).toBeNull()
  }
}

/** Two push rows bound from one template, as a parent page mounts them. */
@Component({
  selector: 'test-push-two-rows-host',
  imports: [HilosPushDeviceToggle],
  template: `
    <hilos-push-device-toggle
      [connection]="connection"
      channel="push"
      label="Push on this device"
      vapidPublicKey="vapid-public-key"
      [store]="store()"
      [channelRow]="true"
    />
    <hilos-push-device-toggle
      [connection]="connection"
      channel="web"
      label="Project push"
      vapidPublicKey="vapid-public-key"
      [store]="store()"
      [channelRow]="true"
      icon="bi-phone"
    />
  `,
})
class TwoRowsHost {
  readonly store = signal<HilosPushSubscriptionStore>(hilosPushSubscription)
  protected readonly connection = fakeConnection()
}

describe('HilosPushDeviceToggle (Angular)', () => {
  const mountPermissionAngular: PushPermissionToggleMount = async (props) => {
    const fixture = TestBed.createComponent(HilosPushDeviceToggle)
    fixture.componentRef.setInput('connection', props.connection)
    fixture.componentRef.setInput('channel', props.channel)
    fixture.componentRef.setInput('label', props.label)
    fixture.componentRef.setInput('vapidPublicKey', props.vapidPublicKey)
    fixture.componentRef.setInput('store', props.store)
    fixture.componentRef.setInput('channelRow', props.channelRow)
    fixture.detectChanges()
    await settle(fixture)

    return {
      container: fixture.nativeElement as HTMLElement,
      event: async (run) => {
        run()
        await settle(fixture)
      },
      flush: async () => settle(fixture),
      unmount: async () => fixture.destroy(),
    }
  }

  registerPushPermissionToggleCases({ it, expect, vi }, mountPermissionAngular)

  describe('compact switch', () => {
    it('reflects an existing device subscription as a checked, enabled switch', async () => {
      const store = await storeReflecting({
        permission: 'granted',
        existing: SNAPSHOT,
      })
      const fixture = await mountToggle(store, fakeConnection())
      const root = fixture.nativeElement as HTMLElement

      const toggle = switchIn(root)
      expect(toggle).toBe(
        byId(root, 'hilos-notification-preference-toggle-push'),
      )
      expect(toggle.checked).toBe(true)
      expect(toggle.disabled).toBe(false)
      expect(nameOf(toggle)).toBe('Push on this device')
      expect(toggle.getAttribute('aria-describedby')).toBeNull()
      expect(byId(root, 'hilos-notification-preference-hint-push')).toBeNull()
      expect(byId(root, 'hilos-notification-preference-icon-push')).toBeNull()
      expect(
        byId(root, 'hilos-notification-preference-subtitle-push'),
      ).toBeNull()
    })

    it.each([
      {
        name: 'unsupported',
        options: { supported: false },
        text: 'Push notifications are not supported on this device.',
      },
      {
        name: 'denied',
        options: { permission: 'denied' },
        text: 'Push notifications are blocked in your browser settings.',
      },
    ] satisfies {
      name: string
      options: FakeEnvironmentOptions
      text: string
    }[])(
      'disables the switch with its hint when the browser is $name',
      async ({ options, text }) => {
        const store = await storeReflecting(options)
        const fixture = await mountToggle(store, fakeConnection())
        const root = fixture.nativeElement as HTMLElement

        const toggle = switchIn(root)
        expect(toggle.disabled).toBe(true)
        const hint = byId(root, 'hilos-notification-preference-hint-push')
        expect(hint?.textContent?.trim()).toBe(text)
        // The disabled switch points at its hint for assistive tech.
        expect(toggle.getAttribute('aria-describedby')).toBe(hint?.id)
      },
    )

    it('subscribes this device when the switch is turned on', async () => {
      const store = await storeReflecting({ existing: null })
      const enable = vi.spyOn(store, 'enable').mockResolvedValue(true)
      const connection = fakeConnection()
      const fixture = await mountToggle(store, connection)

      switchIn(fixture.nativeElement as HTMLElement).click()

      expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
    })

    it('unsubscribes this device when the switch is turned off', async () => {
      const store = await storeReflecting({
        permission: 'granted',
        existing: SNAPSHOT,
      })
      const disable = vi.spyOn(store, 'disable').mockResolvedValue(true)
      const connection = fakeConnection()
      const fixture = await mountToggle(store, connection)

      switchIn(fixture.nativeElement as HTMLElement).click()

      expect(disable).toHaveBeenCalledWith(connection)
    })

    it('shows the loader as a status and blocks a second call while busy', async () => {
      const { store, signals } = handStore(false)
      const fixture = await mountToggle(store, fakeConnection())
      const root = fixture.nativeElement as HTMLElement
      const toggle = switchIn(root)

      toggle.click()
      signals.busy.set(true)
      fixture.detectChanges()

      expect(toggle.disabled).toBe(true)
      expect(toggle.getAttribute('aria-busy')).toBe('true')
      expect(root.querySelector('[role="status"]')?.textContent?.trim()).toBe(
        'Saving…',
      )
      expect(
        byId(root, 'hilos-notification-preference-pending-push'),
      ).not.toBeNull()
      toggle.click()
      expect(store.enable).toHaveBeenCalledTimes(1)
    })
  })

  describe('channel row', () => {
    it.each([
      {
        name: 'subscribed',
        options: { permission: 'granted', existing: SNAPSHOT },
        checked: true,
        disabled: false,
        text: 'This browser is subscribed',
      },
      {
        name: 'unsubscribed',
        options: { existing: null },
        checked: false,
        disabled: false,
        text: 'This browser is not subscribed',
      },
      {
        name: 'unsupported',
        options: { supported: false },
        checked: false,
        disabled: true,
        text: 'Push notifications are not supported on this device.',
      },
      {
        name: 'denied',
        options: { permission: 'denied' },
        checked: false,
        disabled: true,
        text: 'Push notifications are blocked in your browser settings.',
      },
    ] satisfies {
      name: string
      options: FakeEnvironmentOptions
      checked: boolean
      disabled: boolean
      text: string
    }[])(
      'renders the $name device as a row with one named, described switch',
      async ({ options, checked, disabled, text }) => {
        const store = await storeReflecting(options)
        const fixture = await mountToggle(store, fakeConnection(), {
          channelRow: true,
        })
        const root = fixture.nativeElement as HTMLElement

        expect(
          root.querySelectorAll(
            '[data-id="hilos-notification-preference-push"]',
          ),
        ).toHaveLength(1)
        expect(root.querySelectorAll('input')).toHaveLength(1)
        const toggle = switchIn(root)
        expect(toggle).toBe(
          byId(root, 'hilos-notification-preference-toggle-push'),
        )
        expect(nameOf(toggle)).toBe('Push on this device')
        expect(toggle.checked).toBe(checked)
        expect(toggle.disabled).toBe(disabled)
        expect(toggle.getAttribute('aria-busy')).toBe('false')
        expect(describedText(toggle)).toBe(text)
        const subtitle = byId(
          root,
          'hilos-notification-preference-subtitle-push',
        )
        expect(
          subtitle?.contains(
            document.getElementById(
              toggle.getAttribute('aria-describedby') as string,
            ),
          ),
        ).toBe(true)
        const hint = byId(root, 'hilos-notification-preference-hint-push')
        expect(hint !== null).toBe(disabled)
        if (hint !== null) {
          expect(toggle.getAttribute('aria-describedby')).toBe(hint.id)
        }
        expect(
          byId(root, 'hilos-notification-preference-icon-push')?.getAttribute(
            'aria-hidden',
          ),
        ).toBe('true')
        expect(
          byId(root, 'hilos-notification-preference-pending-push'),
        ).toBeNull()
        expectUniqueIdsAndInertTwins()
      },
    )

    it('names an unsupported browser before a denied permission', async () => {
      const store = await storeReflecting({
        supported: false,
        permission: 'denied',
      })
      const fixture = await mountToggle(store, fakeConnection(), {
        channelRow: true,
      })

      const toggle = switchIn(fixture.nativeElement as HTMLElement)
      expect(toggle.disabled).toBe(true)
      expect(describedText(toggle)).toBe(
        'Push notifications are not supported on this device.',
      )
    })

    it('asks for permission only after the person turns the row switch on', async () => {
      const environment = fakeEnvironment({ existing: null })
      const requestPermission = vi.fn(
        async (): Promise<HilosPushPermission> => 'granted',
      )
      environment.requestPermission = requestPermission
      const store = createHilosPushSubscriptionStore(environment)
      await store.refresh()
      const enable = vi.spyOn(store, 'enable')
      const connection = fakeConnection()
      const fixture = await mountToggle(store, connection, { channelRow: true })
      const toggle = switchIn(fixture.nativeElement as HTMLElement)

      expect(requestPermission).not.toHaveBeenCalled()
      toggle.click()

      expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
      await enable.mock.results[0].value
      await settle(fixture)
      expect(requestPermission).toHaveBeenCalledTimes(1)
      expect(toggle.checked).toBe(true)
      expect(describedText(toggle)).toBe('This browser is subscribed')
    })

    it.each([
      { name: 'unsubscribed', existing: false },
      { name: 'subscribed', existing: true },
    ])(
      'keeps the status, the confirmed position and one call while the $name row is busy',
      async ({ existing }) => {
        let finish!: () => void
        const wait = new Promise<void>((resolve) => {
          finish = resolve
        })
        const environment = fakeEnvironment({
          permission: 'granted',
          existing: existing ? SNAPSHOT : null,
        })
        environment.subscribe = async () => {
          await wait

          return SNAPSHOT
        }
        environment.unsubscribe = async () => {
          await wait
        }
        const store = createHilosPushSubscriptionStore(environment)
        await store.refresh()
        const mutation = vi.spyOn(store, existing ? 'disable' : 'enable')
        const connection = fakeConnection()
        const fixture = await mountToggle(store, connection, {
          channelRow: true,
        })
        const root = fixture.nativeElement as HTMLElement
        const toggle = switchIn(root)
        const description = toggle.getAttribute('aria-describedby')
        const status = existing
          ? 'This browser is subscribed'
          : 'This browser is not subscribed'

        toggle.click()
        // The row goes back to the store's position at once, before any result.
        expect(toggle.checked).toBe(existing)
        fixture.detectChanges()

        expect(store.busy.get()).toBe(true)
        expect(toggle.getAttribute('aria-busy')).toBe('true')
        expect(toggle.disabled).toBe(true)
        expect(toggle.checked).toBe(existing)
        expect(toggle.getAttribute('aria-describedby')).toBe(description)
        expect(describedText(toggle)).toBe(status)
        expect(root.querySelector('[role="status"]')?.textContent?.trim()).toBe(
          'Saving…',
        )
        expect(
          byId(root, 'hilos-notification-preference-pending-push')?.textContent,
        ).toBe('Saving…')
        expect(root.querySelectorAll('input')).toHaveLength(1)
        expectUniqueIdsAndInertTwins()
        // The busy switch is disabled, so a second click starts no second call.
        toggle.click()
        expect(mutation).toHaveBeenCalledTimes(1)
        if (existing) {
          expect(mutation).toHaveBeenCalledWith(connection)
        } else {
          expect(mutation).toHaveBeenCalledWith(connection, 'vapid-public-key')
        }

        finish()
        await mutation.mock.results[0].value
        await settle(fixture)
        expect(toggle.getAttribute('aria-busy')).toBe('false')
        expect(toggle.checked).toBe(!existing)
        expect(toggle.disabled).toBe(false)
        expect(describedText(toggle)).toBe(
          existing
            ? 'This browser is not subscribed'
            : 'This browser is subscribed',
        )
        expect(
          byId(root, 'hilos-notification-preference-pending-push'),
        ).toBeNull()
      },
    )

    it('keeps ids unique and one switch per row across several instances', async () => {
      const store = await storeReflecting({ existing: null })
      const fixture = TestBed.createComponent(TwoRowsHost)
      fixture.componentInstance.store.set(store)
      fixture.detectChanges()
      await settle(fixture)
      const root = fixture.nativeElement as HTMLElement

      const switches = root.querySelectorAll<HTMLInputElement>(
        'input[role="switch"]',
      )
      expect(switches).toHaveLength(2)
      expect([...switches].map(nameOf)).toEqual([
        'Push on this device',
        'Project push',
      ])
      for (const toggle of switches) {
        expect(describedText(toggle)).toBe('This browser is not subscribed')
      }
      for (const channel of ['push', 'web']) {
        expect(
          byId(
            root,
            `hilos-notification-preference-icon-${channel}`,
          )?.getAttribute('aria-hidden'),
        ).toBe('true')
      }
      expectUniqueIdsAndInertTwins()
    })
  })

  describe('the passed store', () => {
    it('reads, refreshes and calls the store set before the first render', async () => {
      const { store } = handStore(true)
      const connection = fakeConnection()
      const fixture = await mountToggle(store, connection, { channelRow: true })
      const toggle = switchIn(fixture.nativeElement as HTMLElement)

      expect(store.refresh).toHaveBeenCalledTimes(1)
      expect(toggle.checked).toBe(true)
      expect(describedText(toggle)).toBe('This browser is subscribed')
      toggle.click()
      expect(store.disable).toHaveBeenCalledWith(connection)
    })

    it('follows its own store only, so one instance does not move another', async () => {
      const a = handStore(false)
      const b = handStore(false)
      const first = await mountToggle(a.store, fakeConnection(), {
        channelRow: true,
      })
      const second = await mountToggle(b.store, fakeConnection(), {
        channel: 'web',
        channelRow: true,
      })

      a.signals.subscribed.set(true)
      first.detectChanges()
      second.detectChanges()

      expect(switchIn(first.nativeElement as HTMLElement).checked).toBe(true)
      expect(switchIn(second.nativeElement as HTMLElement).checked).toBe(false)
      expect(describedText(switchIn(second.nativeElement as HTMLElement))).toBe(
        'This browser is not subscribed',
      )
    })

    it('leaves a replaced store and lets go of the store when destroyed', async () => {
      const a = handStore(false)
      const b = handStore(true)
      const connection = fakeConnection()
      const fixture = await mountToggle(a.store, connection, {
        channelRow: true,
      })
      const toggle = switchIn(fixture.nativeElement as HTMLElement)

      fixture.componentRef.setInput('store', b.store)
      await settle(fixture)
      expect(toggle.checked).toBe(true)
      const readA = vi.spyOn(a.signals.subscribed, 'get')
      a.signals.subscribed.set(true)
      b.signals.subscribed.set(false)
      fixture.detectChanges()
      expect(readA).not.toHaveBeenCalled()
      expect(toggle.checked).toBe(false)
      toggle.click()
      expect(b.store.enable).toHaveBeenCalledWith(
        connection,
        'vapid-public-key',
      )
      expect(a.store.enable).not.toHaveBeenCalled()

      fixture.destroy()
      const readB = vi.spyOn(b.signals.subscribed, 'get')
      b.signals.subscribed.set(true)
      expect(readB).not.toHaveBeenCalled()
    })
  })
})
