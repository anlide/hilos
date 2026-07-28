import {
  createHilosPushSubscriptionStore,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
} from '@hilos/core'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import HilosPushDeviceToggle from './HilosPushDeviceToggle.vue'

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

function mountToggle(
  store: Awaited<ReturnType<typeof storeReflecting>>,
  connection: HilosConnection,
) {
  return mount(HilosPushDeviceToggle, {
    props: {
      connection,
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-public-key',
      store,
    },
  })
}

describe('HilosPushDeviceToggle', () => {
  it('reflects an existing device subscription as a checked, enabled switch', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
    expect(toggle.attributes('disabled')).toBeUndefined()
    expect(
      wrapper
        .find('[data-id="hilos-notification-preference-hint-push"]')
        .exists(),
    ).toBe(false)
  })

  it('disables the switch with a hint when the browser does not support push', async () => {
    const store = await storeReflecting({ supported: false })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect(toggle.attributes('disabled')).toBeDefined()
    const hint = wrapper.find(
      '[data-id="hilos-notification-preference-hint-push"]',
    )
    expect(hint.text()).toContain('not supported')
    // The disabled switch points at its hint for assistive tech.
    expect(toggle.attributes('aria-describedby')).toBe(hint.attributes('id'))
  })

  it('disables the switch with a blocked hint when permission is denied', async () => {
    const store = await storeReflecting({ permission: 'denied' })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect(toggle.attributes('disabled')).toBeDefined()
    const hint = wrapper.find(
      '[data-id="hilos-notification-preference-hint-push"]',
    )
    expect(hint.text()).toContain('blocked')
    expect(toggle.attributes('aria-describedby')).toBe(hint.attributes('id'))
  })

  it('subscribes this device when the switch is turned on', async () => {
    const store = await storeReflecting({ existing: null })
    const enable = vi.spyOn(store, 'enable').mockResolvedValue(true)
    const connection = fakeConnection()
    const wrapper = mountToggle(store, connection)
    await flushPromises()

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-push"]')
      .setValue(true)

    expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
  })

  it('unsubscribes this device when the switch is turned off', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const disable = vi.spyOn(store, 'disable').mockResolvedValue(true)
    const connection = fakeConnection()
    const wrapper = mountToggle(store, connection)
    await flushPromises()

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-push"]')
      .setValue(false)

    expect(disable).toHaveBeenCalledWith(connection)
  })
})
