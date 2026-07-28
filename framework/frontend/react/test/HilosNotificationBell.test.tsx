import {
  createHilosNotificationStore,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
} from '@hilos/core'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationBell } from '../src/HilosNotificationBell.js'

// The React bell renders its dropdown only while open (the Vue bell keeps it in the
// DOM via v-show), so tests open the menu before asserting on its contents.
afterEach(() => cleanup())

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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosNotificationBell', () => {
  it('links the toggle to the menu and shows the unread badge with a hidden count label', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationBell connection={connection} store={storeWith(1)} />,
    )

    const toggle = byId('hilos-notification-toggle') as HTMLButtonElement
    expect(toggle.getAttribute('aria-haspopup')).toBe('true')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(toggle.getAttribute('aria-label')).toBe('Notifications')
    const controls = toggle.getAttribute('aria-controls')
    expect(controls).toBeTruthy()

    const badge = byId('hilos-notification-badge') as HTMLElement
    expect(badge.textContent).toContain('1')
    expect(badge.querySelector('.visually-hidden')?.textContent).toContain(
      'unread',
    )

    // Opening the menu reveals the element the toggle points at.
    fireEvent.click(toggle)
    expect(toggle.getAttribute('aria-expanded')).toBe('true')
    expect(byId('hilos-notification-menu')?.getAttribute('id')).toBe(controls)
  })

  it('renders the recent rows and drops the badge when nothing is unread', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationBell connection={connection} store={storeWith(0)} />,
    )

    expect(byId('hilos-notification-badge')).toBeNull()

    fireEvent.click(byId('hilos-notification-toggle') as Element)
    expect(byId('hilos-notification-menu')?.textContent).toContain('First')
  })

  it('shows the empty state when there are no notifications', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationBell
        connection={connection}
        store={createHilosNotificationStore()}
      />,
    )

    fireEvent.click(byId('hilos-notification-toggle') as Element)
    expect(byId('hilos-notification-empty')).not.toBeNull()
  })

  it('marks one read by sending the mark-read action (no optimistic update)', () => {
    const { connection, sendAction } = fakeConnection()
    const store = storeWith(1)
    render(<HilosNotificationBell connection={connection} store={store} />)

    fireEvent.click(byId('hilos-notification-toggle') as Element)
    fireEvent.click(byId('hilos-notification-mark-read-1') as Element)

    expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_MARK_READ, {
      id: 1,
    })
    // The store is untouched until the READ signal fans back.
    expect(store.unreadCount.get()).toBe(1)
  })

  it('marks all read by sending the mark-all action', () => {
    const { connection, sendAction } = fakeConnection()
    render(
      <HilosNotificationBell connection={connection} store={storeWith(1)} />,
    )

    fireEvent.click(byId('hilos-notification-toggle') as Element)
    fireEvent.click(byId('hilos-notification-mark-all') as Element)

    expect(sendAction).toHaveBeenCalledWith(
      NOTIFICATION_ACTION_MARK_ALL_READ,
      {},
    )
  })
})
