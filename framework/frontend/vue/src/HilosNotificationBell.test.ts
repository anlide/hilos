import {
  createHilosNotificationStore,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import HilosNotificationBell from './HilosNotificationBell.vue'

// A minimal connection stub: the bell only ever calls sendAction on it.
function fakeConnection(): {
  connection: HilosConnection
  sendAction: ReturnType<typeof vi.fn>
} {
  const sendAction = vi.fn().mockReturnValue(true)
  return {
    connection: { sendAction } as unknown as HilosConnection,
    sendAction,
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
        createdAt: '2026-07-28T10:00:00.000Z',
      },
    ],
    unreadCount: unread,
  })
  return store
}

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
})
