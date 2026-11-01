import {
  createHilosPushSubscriptionStore,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
  type HilosPushSubscriptionStore,
} from '@hilos/core'
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosPushDeviceToggle } from '../src/HilosPushDeviceToggle.js'

afterEach(() => cleanup())

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
// deterministic; the component's on-mount refresh re-reads the same values.
async function storeReflecting(options: FakeEnvironmentOptions = {}) {
  const store = createHilosPushSubscriptionStore(fakeEnvironment(options))
  await store.refresh()

  return store
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function renderToggle(
  store: HilosPushSubscriptionStore,
  connection: HilosConnection,
  channelRow = false,
) {
  render(
    <HilosPushDeviceToggle
      connection={connection}
      channel="push"
      label="Push on this device"
      vapidPublicKey="vapid-public-key"
      store={store}
      {...(channelRow ? { channelRow } : {})}
    />,
  )
}

// The switch's description: the element its aria-describedby names.
function describedText(toggle: HTMLElement): string | null {
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

describe('HilosPushDeviceToggle', () => {
  it('reflects an existing device subscription as a checked, enabled switch', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    renderToggle(store, fakeConnection())

    const toggle = byId(
      'hilos-notification-preference-toggle-push',
    ) as HTMLInputElement
    expect(toggle.checked).toBe(true)
    expect(toggle.disabled).toBe(false)
    expect(byId('hilos-notification-preference-hint-push')).toBeNull()
    // Without the new props the compact switch stays: named, undescribed, alone.
    expect(screen.getByRole('switch', { name: 'Push on this device' })).toBe(
      toggle,
    )
    expect(toggle.getAttribute('aria-describedby')).toBeNull()
    expect(byId('hilos-notification-preference-icon-push')).toBeNull()
    expect(byId('hilos-notification-preference-subtitle-push')).toBeNull()
    expect(document.querySelectorAll('input')).toHaveLength(1)
  })

  it('disables the switch with a hint when the browser does not support push', async () => {
    const store = await storeReflecting({ supported: false })
    renderToggle(store, fakeConnection())

    const toggle = byId(
      'hilos-notification-preference-toggle-push',
    ) as HTMLInputElement
    expect(toggle.disabled).toBe(true)
    const hint = byId('hilos-notification-preference-hint-push') as HTMLElement
    expect(hint.textContent).toContain('not supported')
    // The disabled switch points at its hint for assistive tech.
    expect(toggle.getAttribute('aria-describedby')).toBe(
      hint.getAttribute('id'),
    )
  })

  it('disables the switch with a blocked hint when permission is denied', async () => {
    const store = await storeReflecting({ permission: 'denied' })
    renderToggle(store, fakeConnection())

    const toggle = byId(
      'hilos-notification-preference-toggle-push',
    ) as HTMLInputElement
    expect(toggle.disabled).toBe(true)
    const hint = byId('hilos-notification-preference-hint-push') as HTMLElement
    expect(hint.textContent).toContain('blocked')
    expect(toggle.getAttribute('aria-describedby')).toBe(
      hint.getAttribute('id'),
    )
  })

  it('subscribes this device when the switch is turned on', async () => {
    const store = await storeReflecting({ existing: null })
    const enable = vi.spyOn(store, 'enable').mockResolvedValue(true)
    const connection = fakeConnection()
    renderToggle(store, connection)

    fireEvent.click(
      byId('hilos-notification-preference-toggle-push') as Element,
    )

    expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
  })

  it('unsubscribes this device when the switch is turned off', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const disable = vi.spyOn(store, 'disable').mockResolvedValue(true)
    const connection = fakeConnection()
    renderToggle(store, connection)

    fireEvent.click(
      byId('hilos-notification-preference-toggle-push') as Element,
    )

    expect(disable).toHaveBeenCalledWith(connection)
  })

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
    'renders the $name device as a channel row with one named switch',
    async ({ options, checked, disabled, text }) => {
      const store = await storeReflecting(options)
      renderToggle(store, fakeConnection(), true)

      expect(
        document.querySelectorAll(
          '[data-id="hilos-notification-preference-push"]',
        ),
      ).toHaveLength(1)
      expect(document.querySelectorAll('input')).toHaveLength(1)
      const toggle = screen.getByRole('switch', {
        name: 'Push on this device',
      }) as HTMLInputElement
      expect(toggle).toBe(byId('hilos-notification-preference-toggle-push'))
      expect(toggle.checked).toBe(checked)
      expect(toggle.disabled).toBe(disabled)
      expect(toggle.getAttribute('aria-busy')).toBe('false')
      expect(describedText(toggle)).toBe(text)
      const subtitle = byId(
        'hilos-notification-preference-subtitle-push',
      ) as HTMLElement
      expect(
        subtitle.contains(
          document.getElementById(
            toggle.getAttribute('aria-describedby') as string,
          ),
        ),
      ).toBe(true)
      const hint = byId('hilos-notification-preference-hint-push')
      expect(hint !== null).toBe(disabled)
      if (hint !== null) {
        expect(toggle.getAttribute('aria-describedby')).toBe(hint.id)
      }
      const icon = byId(
        'hilos-notification-preference-icon-push',
      ) as HTMLElement
      expect(icon.getAttribute('aria-hidden')).toBe('true')
      expect(byId('hilos-notification-preference-pending-push')).toBeNull()
      expectUniqueIdsAndInertTwins()
    },
  )

  it('names an unsupported browser before a denied permission', async () => {
    const store = await storeReflecting({
      supported: false,
      permission: 'denied',
    })
    renderToggle(store, fakeConnection(), true)

    const toggle = screen.getByRole('switch', { name: 'Push on this device' })
    expect((toggle as HTMLInputElement).disabled).toBe(true)
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
    renderToggle(store, connection, true)
    await waitFor(() =>
      expect(screen.getByRole('switch').getAttribute('aria-busy')).toBe(
        'false',
      ),
    )

    expect(requestPermission).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('switch'))

    expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
    await waitFor(() => expect(requestPermission).toHaveBeenCalledTimes(1))
    await waitFor(() =>
      expect((screen.getByRole('switch') as HTMLInputElement).checked).toBe(
        true,
      ),
    )
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
      renderToggle(store, connection, true)
      const toggle = screen.getByRole('switch', {
        name: 'Push on this device',
      }) as HTMLInputElement
      const description = toggle.getAttribute('aria-describedby')
      const status = existing
        ? 'This browser is subscribed'
        : 'This browser is not subscribed'

      fireEvent.click(toggle)
      await waitFor(() => expect(toggle.getAttribute('aria-busy')).toBe('true'))

      expect(toggle.disabled).toBe(true)
      expect(toggle.checked).toBe(existing)
      expect(toggle.getAttribute('aria-describedby')).toBe(description)
      expect(describedText(toggle)).toBe(status)
      expect(screen.getByRole('status').textContent).toBe('Saving…')
      expect(
        byId('hilos-notification-preference-pending-push')?.textContent,
      ).toBe('Saving…')
      expect(document.querySelectorAll('input')).toHaveLength(1)
      expectUniqueIdsAndInertTwins()
      // The busy switch is disabled, so the person cannot start a second call;
      // jsdom still dispatches a click on a disabled input, so it is not replayed.
      expect(mutation).toHaveBeenCalledTimes(1)
      if (existing) {
        expect(mutation).toHaveBeenCalledWith(connection)
      } else {
        expect(mutation).toHaveBeenCalledWith(connection, 'vapid-public-key')
      }

      finish()
      await waitFor(() =>
        expect(toggle.getAttribute('aria-busy')).toBe('false'),
      )
      expect(toggle.checked).toBe(!existing)
      expect(toggle.disabled).toBe(false)
      expect(describedText(toggle)).toBe(
        existing
          ? 'This browser is not subscribed'
          : 'This browser is subscribed',
      )
      expect(byId('hilos-notification-preference-pending-push')).toBeNull()
    },
  )

  it('keeps ids unique and one switch per row across several instances', async () => {
    const store = await storeReflecting({ existing: null })
    render(
      <>
        <HilosPushDeviceToggle
          connection={fakeConnection()}
          channel="push"
          label="Push on this device"
          vapidPublicKey="vapid-public-key"
          store={store}
          channelRow
        />
        <HilosPushDeviceToggle
          connection={fakeConnection()}
          channel="web"
          label="Project push"
          vapidPublicKey="vapid-public-key"
          store={store}
          channelRow
          icon="bi-phone"
        />
      </>,
    )

    expect(screen.getAllByRole('switch')).toHaveLength(2)
    for (const name of ['Push on this device', 'Project push']) {
      expect(describedText(screen.getByRole('switch', { name }))).toBe(
        'This browser is not subscribed',
      )
    }
    for (const channel of ['push', 'web']) {
      expect(
        byId(`hilos-notification-preference-icon-${channel}`)?.getAttribute(
          'aria-hidden',
        ),
      ).toBe('true')
    }
    expectUniqueIdsAndInertTwins()
  })
})
