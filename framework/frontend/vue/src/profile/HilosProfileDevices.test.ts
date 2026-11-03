import {
  bindTimeZoneState,
  createHilosPushSubscriptionStore,
  createSignal,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosPushPermission,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosPushDeviceToggle from '../HilosPushDeviceToggle.vue'
import HilosProfileDevices from './HilosProfileDevices.vue'
import {
  registerProfileDeviceRemoveCases,
  type ProfileDeviceMountFn,
} from '../../../test/profileDeviceRemoveCases.js'

let releaseTimeZone: (() => void) | undefined

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
})
enableAutoUnmount(afterEach)

function createStubConnection(): HilosConnection {
  return {
    on: () => () => {},
    sendAction: vi.fn(() => true),
  } as unknown as HilosConnection
}

describe('HilosProfileDevices (Vue)', () => {
  it('renders flat rows with Bootstrap Icons, heading h3, and proper statuses', async () => {
    const connection = createStubConnection()
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
        deviceName: 'Chrome on macOS',
        createdAt: 1767227400000,
        current: true,
        expired: false,
      },
      {
        id: 2,
        deviceName: 'Safari on iPhone',
        createdAt: 1767227460000,
        current: false,
        expired: false,
      },
      {
        id: 3,
        deviceName: 'Firefox on Windows',
        createdAt: 1767227520000,
        current: false,
        expired: true,
      },
      {
        id: 4,
        deviceName: 'Mobile Safari on iPad',
        createdAt: 1767227580000,
        current: false,
        expired: false,
      },
      {
        id: 5,
        deviceName: null,
        createdAt: 1767227640000,
        current: false,
        expired: false,
      },
    ]

    const removeDevice = vi.fn()
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

    expect(wrapper.find('[data-id="profile-devices"]').exists()).toBe(true)

    const rows = wrapper.findAll('[data-id="profile-device-row"]')
    expect(rows).toHaveLength(5)

    // Flat list of rows: each row has border-bottom, no card or card-body
    for (const row of rows) {
      expect(row.classes()).toContain('border-bottom')
      expect(row.classes()).not.toContain('card')
      expect(row.find('.card-body').exists()).toBe(false)
    }

    // Correct Bootstrap Icons with aria-hidden="true"
    const icons = rows.map((row) => row.get('i'))
    for (const icon of icons) {
      expect(icon.attributes('aria-hidden')).toBe('true')
      expect(icon.classes()).toContain('fs-5')
      expect(icon.classes()).toContain('text-body-secondary')
      expect(icon.classes()).toContain('flex-shrink-0')
    }
    expect(icons[0]!.classes()).toContain('bi-laptop')
    expect(icons[1]!.classes()).toContain('bi-phone')
    expect(icons[2]!.classes()).toContain('bi-display')
    expect(icons[3]!.classes()).toContain('bi-tablet')
    expect(icons[4]!.classes()).toContain('bi-display')

    // Heading h3 with device name and success "this one" badge
    const h3List = rows.map((row) => row.get('h3'))
    for (const h3 of h3List) {
      expect(h3.classes()).toContain('fw-semibold')
      expect(h3.classes()).toContain('small')
      expect(h3.classes()).toContain('mb-0')
    }
    expect(h3List[0]!.text()).toContain('Chrome on macOS')
    expect(h3List[1]!.text()).toContain('Safari on iPhone')
    expect(h3List[2]!.text()).toContain('Firefox on Windows')
    expect(h3List[3]!.text()).toContain('Mobile Safari on iPad')
    expect(h3List[4]!.text()).toContain('Unknown device')

    const thisBadge = h3List[0]!.get('[data-id="profile-device-this"]')
    expect(thisBadge.text()).toBe('this one')
    expect(thisBadge.classes()).toContain('badge')
    expect(thisBadge.classes()).toContain('text-bg-success')
    expect(thisBadge.classes()).toContain('ms-1')
    expect(thisBadge.classes()).not.toContain('text-bg-primary')

    // Expired status without badge
    const expired = rows[2]!.get('[data-id="profile-device-expired"]')
    expect(expired.text()).toBe('Subscription expired')
    expect(expired.classes()).toContain('small')
    expect(expired.classes()).toContain('text-body-secondary')
    expect(expired.classes()).not.toContain('badge')
    expect(expired.classes()).not.toContain('text-bg-secondary')

    expect(rows[0]!.find('[data-id="profile-device-expired"]').exists()).toBe(
      false,
    )
    expect(rows[1]!.find('[data-id="profile-device-expired"]').exists()).toBe(
      false,
    )
    expect(rows[3]!.find('[data-id="profile-device-expired"]').exists()).toBe(
      false,
    )
    expect(rows[4]!.find('[data-id="profile-device-expired"]').exists()).toBe(
      false,
    )

    // Subscribed moment line
    expect(rows[0]!.text()).toContain('Subscribed · added')
    expect(rows[1]!.text()).toContain('Subscribed · added')
    expect(rows[2]!.text()).not.toContain('added')
    expect(rows[3]!.text()).toContain('Subscribed · added')
    expect(rows[4]!.text()).toContain('Subscribed · added')

    // Current row with pushChannel renders HilosPushDeviceToggle, not Remove button
    expect(rows[0]!.find('[data-id="profile-device-remove"]').exists()).toBe(
      false,
    )
    expect(
      rows[0]!
        .find('[data-id="hilos-notification-preference-toggle-push"]')
        .exists(),
    ).toBe(true)

    // Non-current rows render Remove button with btn-outline-secondary btn-sm
    for (let i = 1; i < rows.length; i++) {
      const removeButton = rows[i]!.get('[data-id="profile-device-remove"]')
      expect(removeButton.classes()).toContain('btn-outline-secondary')
      expect(removeButton.classes()).toContain('btn-sm')
      expect(removeButton.classes()).not.toContain('btn-outline-danger')
    }

    // With current device present, unsubscribed placeholder is not shown
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )
  })

  const mountVue: ProfileDeviceMountFn = async (overrides = {}) => {
    const connection = overrides.connection ?? createStubConnection()
    const devices = overrides.devices ?? [
      {
        id: 1,
        deviceName: 'Test Device',
        createdAt: 1767227400000,
        current: false,
        expired: false,
      },
    ]
    const actions = overrides.actions ?? {
      removeDevice: vi.fn(() => ({
        requestId: 'test-req',
        loading: createSignal(false),
        done: Promise.resolve({ reply: null }),
      })),
    }
    const pushChannel =
      overrides.pushChannel !== undefined ? overrides.pushChannel : null
    const pushStore = overrides.pushStore ?? {
      supported: createSignal(true),
      permission: createSignal<HilosPushPermission>('granted'),
      subscribed: createSignal(false),
      endpointHash: createSignal<string | null>(null),
      refreshed: createSignal(true),
      busy: createSignal(false),
      refresh: async () => {},
      enable: async () => true,
      disable: async () => true,
      clear: () => {},
    }

    const wrapper = mount(HilosProfileDevices, {
      props: {
        devices,
        actions,
        connection,
        pushChannel,
        pushStore,
      },
      attachTo: document.body,
    })
    await flushPromises()

    return {
      container: document.body,
      update: async (next) => {
        if (next.devices !== undefined) {
          await wrapper.setProps({ devices: next.devices })
        }
        if (next.actions !== undefined) {
          await wrapper.setProps({ actions: next.actions })
        }
        if (next.pushChannel !== undefined) {
          await wrapper.setProps({ pushChannel: next.pushChannel })
        }
        if (next.pushStore !== undefined) {
          await wrapper.setProps({ pushStore: next.pushStore })
        }
        await flushPromises()
      },
      flush: async () => {
        await flushPromises()
        await nextTick()
      },
      unmount: async () => {
        wrapper.unmount()
        await flushPromises()
      },
    }
  }

  registerProfileDeviceRemoveCases({ it, expect, vi }, mountVue)

  it('renders Remove button for current device when pushChannel is null', async () => {
    const connection = createStubConnection()
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
        deviceName: 'Current Laptop',
        createdAt: 1767227400000,
        current: true,
        expired: false,
      },
    ]

    const removeDevice = vi.fn()
    const wrapper = mount(HilosProfileDevices, {
      props: {
        devices,
        actions: { removeDevice },
        connection,
        pushChannel: null,
        pushStore,
      },
    })
    await flushPromises()

    const row = wrapper.get('[data-id="profile-device-row"]')
    expect(row.findComponent(HilosPushDeviceToggle).exists()).toBe(false)
    const removeButton = row.get('[data-id="profile-device-remove"]')
    expect(removeButton.classes()).toContain('btn-outline-secondary')
  })

  it('renders profile-device-none-this placeholder only when condition is met', async () => {
    const connection = createStubConnection()
    const supported = createSignal(true)
    const permission = createSignal<HilosPushPermission>('granted')
    const refreshed = createSignal(true)

    const pushStore: HilosPushSubscriptionStore = {
      supported,
      permission,
      subscribed: createSignal(false),
      endpointHash: createSignal<string | null>(null),
      refreshed,
      busy: createSignal(false),
      refresh: vi.fn(async () => {}),
      enable: vi.fn(async () => true),
      disable: vi.fn(async () => true),
      clear: vi.fn(),
    }

    const pushChannel = {
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-key',
    }

    // Condition: !hasCurrent && refreshed && pushChannel !== null && supported && permission !== 'denied'
    // 1. All true -> placeholder is rendered with card class
    const wrapper = mount(HilosProfileDevices, {
      props: {
        devices: [
          {
            id: 2,
            deviceName: 'Other phone',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: { removeDevice: vi.fn() },
        connection,
        pushChannel,
        pushStore,
      },
    })
    await flushPromises()

    const placeholder = wrapper.find('[data-id="profile-device-none-this"]')
    expect(placeholder.exists()).toBe(true)
    expect(placeholder.classes()).toContain('card')
    expect(placeholder.text()).toContain('This browser · Not subscribed')
    expect(placeholder.findComponent(HilosPushDeviceToggle).exists()).toBe(true)

    // 2. hasCurrent is true -> not rendered
    await wrapper.setProps({
      devices: [
        {
          id: 2,
          deviceName: 'Other phone',
          createdAt: 1767227400000,
          current: true,
          expired: false,
        },
      ],
    })
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )

    // Reset devices to not current
    await wrapper.setProps({
      devices: [],
    })
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      true,
    )

    // 3. pushChannel is null -> not rendered
    await wrapper.setProps({ pushChannel: null })
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )
    await wrapper.setProps({ pushChannel })
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      true,
    )

    // 4. refreshed is false -> not rendered
    refreshed.set(false)
    await nextTick()
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )
    refreshed.set(true)
    await nextTick()
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      true,
    )

    // 5. supported is false -> not rendered
    supported.set(false)
    await nextTick()
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )
    supported.set(true)
    await nextTick()
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      true,
    )

    // 6. permission is denied -> not rendered
    permission.set('denied')
    await nextTick()
    expect(wrapper.find('[data-id="profile-device-none-this"]').exists()).toBe(
      false,
    )
  })

  it('reformats the same numeric device moments without resetting push or removal state', async () => {
    let browserZone = 'Asia/Tokyo'
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(
      Intl.DateTimeFormat.prototype,
      'resolvedOptions',
    ).mockImplementation(() => ({ ...resolved, timeZone: browserZone }))
    const connection = createStubConnection()
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
      attachTo: document.body,
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
    await flushPromises()

    const confirmButton = document.querySelector<HTMLButtonElement>(
      '[data-id="profile-device-remove-confirm"]',
    )
    expect(confirmButton).not.toBeNull()
    confirmButton?.click()
    await flushPromises()

    expect(removeDevice).toHaveBeenCalledExactlyOnceWith(2)
    expect(confirmButton?.getAttribute('aria-busy')).toBe('true')

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
    expect(confirmButton?.getAttribute('aria-busy')).toBe('true')
    expect(removeDevice).toHaveBeenCalledTimes(1)
    expect(connection.sendAction).not.toHaveBeenCalled()
    resolveAction()
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    wrapper.unmount()
  })
})
