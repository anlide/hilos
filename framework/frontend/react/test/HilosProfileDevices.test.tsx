import {
  createHilosPushSubscriptionStore,
  createSignal,
  formatProfileDateTime,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosProfileDevices } from '../src/profile/HilosProfileDevices.js'

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

function createStubConnection(): HilosConnection {
  return {
    on: () => () => {},
    sendAction: vi.fn().mockReturnValue(true),
  } as unknown as HilosConnection
}

describe('HilosProfileDevices (React)', () => {
  it('renders flat rows with Bootstrap Icons, heading h3, and proper statuses', async () => {
    const connection = createStubConnection()
    const subscription: HilosPushSubscriptionSnapshot = {
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
    await pushStore.refresh()

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
    const actions: HilosProfileDeviceActions = { removeDevice }
    const pushChannel: HilosProfilePushChannel = {
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-key',
    }

    const { container } = render(
      <HilosProfileDevices
        devices={devices}
        actions={actions}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )
    await act(async () => {})

    expect(
      container.querySelector('[data-id="profile-devices"]'),
    ).not.toBeNull()

    const rows = container.querySelectorAll('[data-id="profile-device-row"]')
    expect(rows).toHaveLength(5)

    // Flat list of rows: each row has border-bottom, no card or card-body
    for (const row of rows) {
      expect(row.classList.contains('border-bottom')).toBe(true)
      expect(row.classList.contains('card')).toBe(false)
      expect(row.querySelector('.card-body')).toBeNull()
    }

    // Correct Bootstrap Icons with aria-hidden="true"
    const icons = Array.from(rows).map((row) => row.querySelector('i')!)
    for (const icon of icons) {
      expect(icon.getAttribute('aria-hidden')).toBe('true')
      expect(icon.classList.contains('fs-5')).toBe(true)
      expect(icon.classList.contains('text-body-secondary')).toBe(true)
      expect(icon.classList.contains('flex-shrink-0')).toBe(true)
    }
    expect(icons[0].classList.contains('bi-laptop')).toBe(true)
    expect(icons[1].classList.contains('bi-phone')).toBe(true)
    expect(icons[2].classList.contains('bi-display')).toBe(true)
    expect(icons[3].classList.contains('bi-tablet')).toBe(true)
    expect(icons[4].classList.contains('bi-display')).toBe(true)

    // Heading h3 with device name and success "this one" badge
    const h3List = Array.from(rows).map((row) => row.querySelector('h3')!)
    for (const h3 of h3List) {
      expect(h3.classList.contains('fw-semibold')).toBe(true)
      expect(h3.classList.contains('small')).toBe(true)
      expect(h3.classList.contains('mb-0')).toBe(true)
    }
    expect(h3List[0].textContent).toContain('Chrome on macOS')
    expect(h3List[1].textContent).toContain('Safari on iPhone')
    expect(h3List[2].textContent).toContain('Firefox on Windows')
    expect(h3List[3].textContent).toContain('Mobile Safari on iPad')
    expect(h3List[4].textContent).toContain('Unknown device')

    const thisBadge = h3List[0].querySelector(
      '[data-id="profile-device-this"]',
    )!
    expect(thisBadge.textContent).toBe('this one')
    expect(thisBadge.classList.contains('badge')).toBe(true)
    expect(thisBadge.classList.contains('text-bg-success')).toBe(true)
    expect(thisBadge.classList.contains('ms-1')).toBe(true)
    expect(thisBadge.classList.contains('text-bg-primary')).toBe(false)

    // Expired status without badge
    const expired = rows[2].querySelector('[data-id="profile-device-expired"]')!
    expect(expired.textContent).toBe('Subscription expired')
    expect(expired.classList.contains('small')).toBe(true)
    expect(expired.classList.contains('text-body-secondary')).toBe(true)
    expect(expired.classList.contains('badge')).toBe(false)
    expect(expired.classList.contains('text-bg-secondary')).toBe(false)

    expect(
      rows[0].querySelector('[data-id="profile-device-expired"]'),
    ).toBeNull()
    expect(
      rows[1].querySelector('[data-id="profile-device-expired"]'),
    ).toBeNull()
    expect(
      rows[3].querySelector('[data-id="profile-device-expired"]'),
    ).toBeNull()
    expect(
      rows[4].querySelector('[data-id="profile-device-expired"]'),
    ).toBeNull()

    // Subscribed moment line
    expect(rows[0].textContent).toContain(
      `Subscribed · added ${formatProfileDateTime(1767227400000)}`,
    )
    expect(rows[1].textContent).toContain(
      `Subscribed · added ${formatProfileDateTime(1767227460000)}`,
    )
    expect(rows[2].textContent).not.toContain('added')
    expect(rows[3].textContent).toContain(
      `Subscribed · added ${formatProfileDateTime(1767227580000)}`,
    )
    expect(rows[4].textContent).toContain(
      `Subscribed · added ${formatProfileDateTime(1767227640000)}`,
    )

    // Current row with pushChannel renders HilosPushDeviceToggle, not Remove button
    expect(
      rows[0].querySelector('[data-id="profile-device-remove"]'),
    ).toBeNull()
    expect(
      rows[0].querySelector(
        '[data-id="hilos-notification-preference-toggle-push"]',
      ),
    ).not.toBeNull()

    // Non-current rows render Remove button with btn-outline-secondary btn-sm
    for (let i = 1; i < rows.length; i++) {
      const removeBtn = rows[i].querySelector(
        '[data-id="profile-device-remove"]',
      )!
      expect(removeBtn.classList.contains('btn-outline-secondary')).toBe(true)
      expect(removeBtn.classList.contains('btn-sm')).toBe(true)
      expect(removeBtn.classList.contains('btn-outline-danger')).toBe(false)
    }

    // With current device present, unsubscribed placeholder is not shown
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()
  })

  it('handles the Remove action lifecycle and disables button before refresh', async () => {
    const connection = createStubConnection()

    let resolveSubscription!: (
      sub: HilosPushSubscriptionSnapshot | null,
    ) => void
    const getSubscriptionPromise =
      new Promise<HilosPushSubscriptionSnapshot | null>((resolve) => {
        resolveSubscription = resolve
      })

    const pushStore = createHilosPushSubscriptionStore({
      isSupported: () => true,
      permission: () => 'granted',
      userAgent: () => 'Test browser',
      requestPermission: async () => 'granted',
      getSubscription: () => getSubscriptionPromise,
      subscribe: async () => null as unknown as HilosPushSubscriptionSnapshot,
      unsubscribe: async () => {},
    })

    const devices: readonly HilosProfileDevice[] = [
      {
        id: 10,
        deviceName: 'Phone',
        createdAt: 1767227400000,
        current: false,
        expired: false,
      },
    ]

    let resolveAction!: () => void
    const done = new Promise<{ reply: null }>((resolve) => {
      resolveAction = () => resolve({ reply: null })
    })
    const removeDevice = vi.fn(() => ({
      requestId: 'remove-device-10',
      done,
      loading: createSignal(false),
    }))

    const { container } = render(
      <HilosProfileDevices
        devices={devices}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={null}
        pushStore={pushStore}
      />,
    )

    const removeBtn = container.querySelector<HTMLButtonElement>(
      '[data-id="profile-device-remove"]',
    )!
    // 1. Before refreshed: button is disabled
    expect(removeBtn.disabled).toBe(true)

    // Trigger refresh completion
    await act(async () => {
      resolveSubscription(null)
      await getSubscriptionPromise
    })

    // 2. After refreshed: button is enabled
    expect(removeBtn.disabled).toBe(false)

    // 3. Click Remove: dispatches action with exact device id
    await act(async () => {
      fireEvent.click(removeBtn)
    })
    expect(removeDevice).toHaveBeenCalledTimes(1)
    expect(removeDevice).toHaveBeenCalledWith(10)

    // 4. While action handle is in flight: button is locked/busy and repeated clicks are blocked
    expect(removeBtn.getAttribute('aria-busy')).toBe('true')
    await act(async () => {
      fireEvent.click(removeBtn)
    })
    expect(removeDevice).toHaveBeenCalledTimes(1)

    // 5. Row is not removed locally before props update
    expect(
      container.querySelectorAll('[data-id="profile-device-row"]'),
    ).toHaveLength(1)

    // Action finishes
    await act(async () => {
      resolveAction()
      await done
    })
    expect(removeBtn.getAttribute('aria-busy')).toBeNull()
  })

  it('renders Remove button for current device when pushChannel is null', async () => {
    const connection = createStubConnection()
    const subscription: HilosPushSubscriptionSnapshot = {
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
    await pushStore.refresh()

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
    const { container } = render(
      <HilosProfileDevices
        devices={devices}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={null}
        pushStore={pushStore}
      />,
    )

    const row = container.querySelector('[data-id="profile-device-row"]')!
    expect(
      row.querySelector(
        '[data-id="hilos-notification-preference-toggle-push"]',
      ),
    ).toBeNull()
    const removeBtn = row.querySelector('[data-id="profile-device-remove"]')!
    expect(removeBtn.classList.contains('btn-outline-secondary')).toBe(true)
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

    const pushChannel: HilosProfilePushChannel = {
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-key',
    }

    const removeDevice = vi.fn()

    // 1. Condition met: !hasCurrent, refreshed, pushChannel !== null, supported, permission !== 'denied'
    const { container, rerender } = render(
      <HilosProfileDevices
        devices={[
          {
            id: 2,
            deviceName: 'Other phone',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ]}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )
    await act(async () => {})

    const placeholder = container.querySelector(
      '[data-id="profile-device-none-this"]',
    )
    expect(placeholder).not.toBeNull()
    expect(placeholder!.classList.contains('card')).toBe(true)
    expect(placeholder!.textContent).toContain('This browser · Not subscribed')
    expect(
      placeholder!.querySelector(
        '[data-id="hilos-notification-preference-toggle-push"]',
      ),
    ).not.toBeNull()

    // 2. hasCurrent is true -> not rendered
    rerender(
      <HilosProfileDevices
        devices={[
          {
            id: 2,
            deviceName: 'Other phone',
            createdAt: 1767227400000,
            current: true,
            expired: false,
          },
        ]}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    // Reset devices: not current
    rerender(
      <HilosProfileDevices
        devices={[]}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 3. pushChannel is null -> not rendered
    rerender(
      <HilosProfileDevices
        devices={[]}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={null}
        pushStore={pushStore}
      />,
    )
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    // Restore pushChannel
    rerender(
      <HilosProfileDevices
        devices={[]}
        actions={{ removeDevice }}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 4. refreshed is false -> not rendered
    await act(async () => {
      refreshed.set(false)
    })
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    // Restore refreshed
    await act(async () => {
      refreshed.set(true)
    })
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 5. supported is false -> not rendered
    await act(async () => {
      supported.set(false)
    })
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    // Restore supported
    await act(async () => {
      supported.set(true)
    })
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 6. permission is denied -> not rendered
    await act(async () => {
      permission.set('denied')
    })
    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()
  })

  it('does not render placeholder before store is refreshed', () => {
    const connection = createStubConnection()
    let resolveSub!: (val: null) => void
    const subPromise = new Promise<null>((resolve) => {
      resolveSub = resolve
    })

    const pushStore = createHilosPushSubscriptionStore({
      isSupported: () => true,
      permission: () => 'granted',
      userAgent: () => 'Test browser',
      requestPermission: async () => 'granted',
      getSubscription: () => subPromise,
      subscribe: async () => null as unknown as HilosPushSubscriptionSnapshot,
      unsubscribe: async () => {},
    })

    const pushChannel: HilosProfilePushChannel = {
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-key',
    }

    const { container } = render(
      <HilosProfileDevices
        devices={[]}
        actions={{ removeDevice: vi.fn() }}
        connection={connection}
        pushChannel={pushChannel}
        pushStore={pushStore}
      />,
    )

    expect(
      container.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    resolveSub(null)
  })
})
