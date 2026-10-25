import {
  bindTimeZoneState,
  createHilosPushSubscriptionStore,
  createSignal,
  type HilosConnection,
  type HilosProfileDevice,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosProfileDevices from './HilosProfileDevices.vue'

let releaseTimeZone: (() => void) | undefined

afterEach(() => {
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
})
enableAutoUnmount(afterEach)

describe('HilosProfileDevices (Vue)', () => {
  it('reformats the same numeric device moments without resetting push or removal state', async () => {
    let browserZone = 'Asia/Tokyo'
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(
      Intl.DateTimeFormat.prototype,
      'resolvedOptions',
    ).mockImplementation(() => ({ ...resolved, timeZone: browserZone }))
    const connection = {
      on: () => () => {},
      sendAction: vi.fn(() => true),
    } as unknown as HilosConnection
    releaseTimeZone = bindTimeZoneState(connection)
    const subscription = {
      endpoint: 'https://push.example/current',
      p256dh: 'public-key',
      auth: 'auth',
    }
    const pushStore = createHilosPushSubscriptionStore({
      isSupported: () => true,
      permission: () => 'granted',
      userAgent: () => 'Test browser',
      requestPermission: async () => 'granted',
      getSubscription: async () => subscription,
      subscribe: async () => subscription,
      unsubscribe: async () => {},
    })
    const devices: readonly HilosProfileDevice[] = [
      {
        id: 1,
        deviceName: 'This browser',
        createdAt: 1767227400000,
        current: true,
        expired: false,
      },
      {
        id: 2,
        deviceName: null,
        createdAt: 1767227460000,
        current: false,
        expired: false,
      },
      {
        id: 3,
        deviceName: 'Old browser',
        createdAt: 1767227520000,
        current: false,
        expired: true,
      },
    ]
    let resolveAction!: () => void
    const done = new Promise<{ reply: null }>((resolve) => {
      resolveAction = () => resolve({ reply: null })
    })
    const removeDevice = vi.fn(() => ({
      requestId: 'remove-device',
      done,
      loading: createSignal(false),
    }))
    const wrapper = mount(HilosProfileDevices, {
      props: {
        devices,
        actions: { removeDevice },
        connection,
        pushChannel: {
          channel: 'push',
          label: 'Push on this device',
          vapidPublicKey: 'vapid-key',
        },
        pushStore,
      },
    })
    await flushPromises()
    const originalDevices = wrapper.props('devices')
    const rows = wrapper.findAll('[data-id="profile-device-row"]')
    expect(rows[0]!.text()).toContain('Subscribed · added 1/1/2026, 9:30:00 AM')
    expect(rows[1]!.text()).toContain('Unknown device')
    expect(rows[1]!.text()).toContain('Subscribed · added 1/1/2026, 9:31:00 AM')
    const expired = rows[2]!.get('[data-id="profile-device-expired"]')
    expect(expired.text()).toBe('Subscription expired')
    expect(rows[2]!.text()).not.toContain('added')
    const toggle = rows[0]!.get<HTMLInputElement>(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect(toggle.element.checked).toBe(true)
    const remove = rows[1]!.get('[data-id="profile-device-remove"]')
    // The real store finishes hashing its endpoint before enabling removal.
    await vi.waitFor(() =>
      expect(remove.attributes('disabled')).toBeUndefined(),
    )
    await remove.trigger('click')
    expect(removeDevice).toHaveBeenCalledExactlyOnceWith(2)
    expect(remove.attributes('aria-busy')).toBe('true')

    browserZone = 'America/New_York'
    window.dispatchEvent(new Event('focus'))
    await nextTick()

    expect(wrapper.props('devices')).toBe(originalDevices)
    expect(rows[0]!.text()).toContain(
      'Subscribed · added 12/31/2025, 7:30:00 PM',
    )
    expect(rows[1]!.text()).toContain(
      'Subscribed · added 12/31/2025, 7:31:00 PM',
    )
    expect(
      rows[0]!.get('[data-id="hilos-notification-preference-toggle-push"]')
        .element,
    ).toBe(toggle.element)
    expect(toggle.element.checked).toBe(true)
    expect(rows[2]!.get('[data-id="profile-device-expired"]').element).toBe(
      expired.element,
    )
    expect(rows[2]!.text()).not.toContain('added')
    expect(remove.attributes('aria-busy')).toBe('true')
    expect(removeDevice).toHaveBeenCalledTimes(1)
    expect(connection.sendAction).not.toHaveBeenCalled()
    resolveAction()
    await flushPromises()
    expect(remove.attributes('aria-busy')).toBeUndefined()
  })
})
