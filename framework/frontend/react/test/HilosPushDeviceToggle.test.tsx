import {
  createHilosPushSubscriptionStore,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
} from '@hilos/core'
import { cleanup, fireEvent, render } from '@testing-library/react'
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
  store: Awaited<ReturnType<typeof storeReflecting>>,
  connection: HilosConnection,
) {
  render(
    <HilosPushDeviceToggle
      connection={connection}
      channel="push"
      label="Push on this device"
      vapidPublicKey="vapid-public-key"
      store={store}
    />,
  )
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
})
