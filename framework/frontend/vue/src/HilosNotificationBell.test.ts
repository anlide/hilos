import {
  applyServerTime,
  bindTimeZoneState,
  createHilosNotificationStore,
  hilosTimeZone,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
} from '@hilos/core/base'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosNotificationBell from './HilosNotificationBell.vue'

// A minimal connection stub: the bell calls sendAction on it, and timeZoneState listens on it.
function fakeConnection(): {
  connection: HilosConnection
  sendAction: ReturnType<typeof vi.fn>
  setOwnZone: (timeZone: string | null) => void
} {
  const sendAction = vi.fn().mockReturnValue(true)
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  const connection = {
    projectTimeZone: 'UTC',
    sendAction,
    on(event: string, listener: (signal: unknown) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)
      return () => group.delete(listener)
    },
  } as unknown as HilosConnection

  const setOwnZone = (timeZone: string | null): void => {
    for (const listener of listeners.get('projectSignal') ?? []) {
      listener({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 42, timeZone } } },
      })
    }
  }

  return {
    connection,
    sendAction,
    setOwnZone,
  }
}

function storeWith(unread: number) {
  const store = createHilosNotificationStore()
  store.ingestSnapshot({
    recent: [
      {
        id: 1,
        userId: 42,
        type: 'system',
        severity: 'info',
        title: 'First',
        body: 'Hello',
        readAt: null,
        createdAt: 1785232800000,
      },
    ],
    unreadCount: unread,
  })
  return store
}

afterEach(() => {
  applyServerTime(Date.now())
  vi.restoreAllMocks()
})

describe('HilosNotificationBell', () => {
  it('links the toggle to the menu and shows the unread badge with a hidden count label', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store: storeWith(1) },
    })

    const toggle = wrapper.find('[data-id="hilos-notification-toggle"]')
    const menu = wrapper.find('[data-id="hilos-notification-menu"]')
    const menuId = menu.attributes('id')

    expect(menuId).toBeTruthy()
    expect(toggle.attributes('aria-controls')).toBe(menuId)
    expect(toggle.attributes('aria-haspopup')).toBe('true')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(toggle.attributes('aria-label')).toBe('Notifications')

    const badge = wrapper.find('[data-id="hilos-notification-badge"]')
    expect(badge.text()).toContain('1')
    expect(badge.find('.visually-hidden').text()).toContain('unread')
  })

  it('renders the recent rows and drops the badge when nothing is unread', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store: storeWith(0) },
    })

    expect(wrapper.text()).toContain('First')
    expect(wrapper.find('[data-id="hilos-notification-badge"]').exists()).toBe(
      false,
    )
  })

  it('shows the empty state when there are no notifications', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store: createHilosNotificationStore() },
    })

    expect(wrapper.find('[data-id="hilos-notification-empty"]').exists()).toBe(
      true,
    )
  })

  it('marks one read by sending the mark-read action (no optimistic update)', async () => {
    const { connection, sendAction } = fakeConnection()
    const store = storeWith(1)
    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store },
    })

    await wrapper
      .find('[data-id="hilos-notification-mark-read-1"]')
      .trigger('click')

    expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_MARK_READ, {
      id: 1,
    })
    // The store is untouched until the READ signal fans back.
    expect(store.unreadCount.get()).toBe(1)
  })

  it('marks all read by sending the mark-all action', async () => {
    const { connection, sendAction } = fakeConnection()
    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store: storeWith(1) },
    })

    await wrapper
      .find('[data-id="hilos-notification-mark-all"]')
      .trigger('click')

    expect(sendAction).toHaveBeenCalledWith(
      NOTIFICATION_ACTION_MARK_ALL_READ,
      {},
    )
  })

  it('renders timestamps in UTC and Pacific/Honolulu across the day boundary, updates without server frames, handles null/0, ignores clock offset, and cleans up on unmount', async () => {
    const { connection, setOwnZone } = fakeConnection()
    const releaseTime = bindTimeZoneState(connection)

    // Known moment: 2026-10-24 03:30:00 UTC.
    // In UTC, this is Oct 24, 2026.
    // In Pacific/Honolulu (UTC-10), this is Oct 23, 2026 17:30:00 (crossing the day boundary).
    const at = Date.parse('2026-10-24T03:30:00Z')

    const store = createHilosNotificationStore()
    store.ingestSnapshot({
      recent: [
        {
          id: 1,
          userId: 42,
          type: 'system',
          severity: 'info',
          title: 'Boundary Alert',
          body: 'Check day boundary',
          readAt: null,
          createdAt: at,
        },
        {
          id: 2,
          userId: 42,
          type: 'system',
          severity: 'info',
          title: 'Epoch Alert',
          body: 'Epoch 0',
          readAt: null,
          createdAt: 0,
        },
        {
          id: 3,
          userId: 42,
          type: 'system',
          severity: 'info',
          title: 'Null Alert',
          body: 'Null createdAt',
          readAt: null,
          createdAt: null,
        },
      ],
      unreadCount: 3,
    })

    const wrapper = mount(HilosNotificationBell, {
      props: { connection, store },
    })

    // Open disclosure menu so notification items are rendered and visible in DOM
    await wrapper.find('[data-id="hilos-notification-toggle"]').trigger('click')

    // Independent formatter that does not call formatMoment
    const formatIndependent = (moment: number, timeZone: string): string =>
      new Intl.DateTimeFormat(undefined, {
        timeZone,
        dateStyle: 'medium',
        timeStyle: 'medium',
      }).format(moment)

    // 1. Initial render in UTC
    setOwnZone('UTC')
    await nextTick()
    expect(hilosTimeZone.get()).toBe('UTC')

    const item1Time = wrapper.find(
      '[data-id="hilos-notification-item-1"] .text-body-tertiary',
    )
    const item2Time = wrapper.find(
      '[data-id="hilos-notification-item-2"] .text-body-tertiary',
    )
    const item3Time = wrapper.find(
      '[data-id="hilos-notification-item-3"] .text-body-tertiary',
    )

    const expectedUtc1 = formatIndependent(at, 'UTC')
    const expectedUtc2 = formatIndependent(0, 'UTC')

    expect(item1Time.text()).toBe(expectedUtc1)
    expect(item2Time.text()).toBe(expectedUtc2)
    expect(item3Time.text()).toBe('')

    // 2. Change reader zone on the already mounted component to Pacific/Honolulu
    setOwnZone('Pacific/Honolulu')
    await nextTick()
    expect(hilosTimeZone.get()).toBe('Pacific/Honolulu')

    const expectedHonolulu1 = formatIndependent(at, 'Pacific/Honolulu')
    const expectedHonolulu2 = formatIndependent(0, 'Pacific/Honolulu')

    expect(item1Time.text()).toBe(expectedHonolulu1)
    expect(item2Time.text()).toBe(expectedHonolulu2)
    expect(item3Time.text()).toBe('')

    // Verify the boundary shift: UTC time and Honolulu time are distinct calendar days
    expect(expectedHonolulu1).not.toBe(expectedUtc1)

    // 3. Device clock offset has no effect on moment presentation (server moment is authoritative)
    applyServerTime(Date.now() + 5 * 3_600_000)
    await nextTick()

    expect(item1Time.text()).toBe(expectedHonolulu1)
    expect(item2Time.text()).toBe(expectedHonolulu2)

    // 4. Cleanup on unmount
    wrapper.unmount()
    releaseTime()
  })
})
