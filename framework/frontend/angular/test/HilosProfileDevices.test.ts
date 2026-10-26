import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosPushSubscriptionStore,
  createSignal,
  type ActionHandle,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosProfileDevices } from '../src/profile/HilosProfileDevices.js'

afterEach(() => {
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function createStubConnection(): HilosConnection {
  return {
    on: () => () => {},
    sendAction: vi.fn(() => true),
  } as unknown as HilosConnection
}

describe('HilosProfileDevices (Angular)', () => {
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

    const fixture = TestBed.createComponent(HilosProfileDevices)
    fixture.componentRef.setInput('devices', devices)
    fixture.componentRef.setInput('actions', actions)
    fixture.componentRef.setInput('connection', connection)
    fixture.componentRef.setInput('pushChannel', pushChannel)
    fixture.componentRef.setInput('store', pushStore)
    fixture.detectChanges()
    await settle(fixture)

    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelector('[data-id="profile-devices"]')).not.toBeNull()

    const rows = root.querySelectorAll<HTMLElement>(
      '[data-id="profile-device-row"]',
    )
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

    const thisBadge = h3List[0].querySelector('[data-id="profile-device-this"]')
    expect(thisBadge).not.toBeNull()
    expect(thisBadge!.textContent?.trim()).toBe('this one')
    expect(thisBadge!.classList.contains('badge')).toBe(true)
    expect(thisBadge!.classList.contains('text-bg-success')).toBe(true)
    expect(thisBadge!.classList.contains('ms-1')).toBe(true)
    expect(thisBadge!.classList.contains('text-bg-primary')).toBe(false)

    // Expired status without badge
    const expired = rows[2].querySelector('[data-id="profile-device-expired"]')
    expect(expired).not.toBeNull()
    expect(expired!.textContent?.trim()).toBe('Subscription expired')
    expect(expired!.classList.contains('small')).toBe(true)
    expect(expired!.classList.contains('text-body-secondary')).toBe(true)
    expect(expired!.classList.contains('badge')).toBe(false)
    expect(expired!.classList.contains('text-bg-secondary')).toBe(false)

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
    expect(rows[0].textContent).toContain('Subscribed · added')
    expect(rows[1].textContent).toContain('Subscribed · added')
    expect(rows[2].textContent).not.toContain('added')
    expect(rows[3].textContent).toContain('Subscribed · added')
    expect(rows[4].textContent).toContain('Subscribed · added')

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
      const removeBtn = rows[i].querySelector<HTMLButtonElement>(
        '[data-id="profile-device-remove"]',
      )
      expect(removeBtn).not.toBeNull()
      expect(removeBtn!.classList.contains('btn-outline-secondary')).toBe(true)
      expect(removeBtn!.classList.contains('btn-sm')).toBe(true)
      expect(removeBtn!.classList.contains('btn-outline-danger')).toBe(false)
    }

    // With current device present, unsubscribed placeholder is not shown
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()
  })

  it('handles the Remove action lifecycle and disables button before refresh', async () => {
    const connection = createStubConnection()
    const supported = createSignal(true)
    const permission = createSignal<HilosPushPermission>('granted')
    const subscribed = createSignal(false)
    const endpointHash = createSignal<string | null>(null)
    const refreshed = createSignal(false)
    const busy = createSignal(false)

    let resolveRefresh!: () => void
    const refreshPromise = new Promise<void>((resolve) => {
      resolveRefresh = resolve
    })

    const pushStore: HilosPushSubscriptionStore = {
      supported,
      permission,
      subscribed,
      endpointHash,
      refreshed,
      busy,
      refresh: vi.fn(() => refreshPromise),
      enable: vi.fn(async () => true),
      disable: vi.fn(async () => true),
      clear: vi.fn(),
    }

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
    const removeActionHandle: ActionHandle = {
      requestId: 'remove-device-10',
      done,
      loading: createSignal(false),
    }
    const removeDevice = vi.fn(() => removeActionHandle)
    const actions: HilosProfileDeviceActions = { removeDevice }

    const fixture = TestBed.createComponent(HilosProfileDevices)
    fixture.componentRef.setInput('devices', devices)
    fixture.componentRef.setInput('actions', actions)
    fixture.componentRef.setInput('connection', connection)
    fixture.componentRef.setInput('pushChannel', null)
    fixture.componentRef.setInput('store', pushStore)
    fixture.detectChanges()
    await settle(fixture)

    const root = fixture.nativeElement as HTMLElement
    const removeBtn = root.querySelector<HTMLButtonElement>(
      '[data-id="profile-device-remove"]',
    )
    expect(removeBtn).not.toBeNull()

    // Before refreshed: button is disabled
    expect(removeBtn!.hasAttribute('disabled')).toBe(true)

    // Trigger refresh completion
    refreshed.set(true)
    resolveRefresh()
    await settle(fixture)

    // After refreshed: button is enabled
    expect(removeBtn!.hasAttribute('disabled')).toBe(false)

    // Click Remove: dispatches action with exact device id
    removeBtn!.click()
    expect(removeDevice).toHaveBeenCalledExactlyOnceWith(10)

    // While action is in-flight: button is busy/loading and repeated click is blocked
    await settle(fixture)
    expect(removeBtn!.getAttribute('aria-busy')).toBe('true')
    removeBtn!.click()
    expect(removeDevice).toHaveBeenCalledTimes(1)

    // Row is not removed locally before props update
    expect(
      root.querySelectorAll('[data-id="profile-device-row"]'),
    ).toHaveLength(1)

    // Action finishes
    resolveAction()
    await settle(fixture)
    expect(removeBtn!.getAttribute('aria-busy')).toBeNull()
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
    const fixture = TestBed.createComponent(HilosProfileDevices)
    fixture.componentRef.setInput('devices', devices)
    fixture.componentRef.setInput('actions', { removeDevice })
    fixture.componentRef.setInput('connection', connection)
    fixture.componentRef.setInput('pushChannel', null)
    fixture.componentRef.setInput('store', pushStore)
    fixture.detectChanges()
    await settle(fixture)

    const root = fixture.nativeElement as HTMLElement
    const row = root.querySelector<HTMLElement>(
      '[data-id="profile-device-row"]',
    )
    expect(row).not.toBeNull()
    expect(
      row!.querySelector(
        '[data-id="hilos-notification-preference-toggle-push"]',
      ),
    ).toBeNull()

    const removeButton = row!.querySelector<HTMLButtonElement>(
      '[data-id="profile-device-remove"]',
    )
    expect(removeButton).not.toBeNull()
    expect(removeButton!.classList.contains('btn-outline-secondary')).toBe(true)
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

    // Condition: !hasCurrent && refreshed && pushChannel !== null && supported && permission !== 'denied'
    // 1. All true -> placeholder is rendered with card class
    const fixture = TestBed.createComponent(HilosProfileDevices)
    fixture.componentRef.setInput('devices', [
      {
        id: 2,
        deviceName: 'Other phone',
        createdAt: 1767227400000,
        current: false,
        expired: false,
      },
    ])
    fixture.componentRef.setInput('actions', { removeDevice: vi.fn() })
    fixture.componentRef.setInput('connection', connection)
    fixture.componentRef.setInput('pushChannel', pushChannel)
    fixture.componentRef.setInput('store', pushStore)
    fixture.detectChanges()
    await settle(fixture)

    const root = fixture.nativeElement as HTMLElement
    const placeholder = root.querySelector(
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
    fixture.componentRef.setInput('devices', [
      {
        id: 2,
        deviceName: 'Other phone',
        createdAt: 1767227400000,
        current: true,
        expired: false,
      },
    ])
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    // Reset devices to not current
    fixture.componentRef.setInput('devices', [])
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 3. pushChannel is null -> not rendered
    fixture.componentRef.setInput('pushChannel', null)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    fixture.componentRef.setInput('pushChannel', pushChannel)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 4. refreshed is false -> not rendered
    refreshed.set(false)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    refreshed.set(true)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 5. supported is false -> not rendered
    supported.set(false)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()

    supported.set(true)
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).not.toBeNull()

    // 6. permission is denied -> not rendered
    permission.set('denied')
    await settle(fixture)
    expect(
      root.querySelector('[data-id="profile-device-none-this"]'),
    ).toBeNull()
  })
})
