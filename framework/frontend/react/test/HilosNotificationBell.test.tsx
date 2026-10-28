import {
  createHilosNotificationStore,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
} from '@hilos/core'
import { hilosBrowserTimeZone, hilosTimeZone } from '@hilos/core/base'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationBell } from '../src/HilosNotificationBell.js'
import { readerTimeZone } from './readerTimeZone.js'

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
        createdAt: 1785232800000,
      },
    ],
    unreadCount: unread,
  })
  return store
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function formatIndependent(moment: number, timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    timeZone,
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(moment)
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

  it('formats epoch moments in the live reader zone without closing or moving focus', () => {
    const reader = readerTimeZone()
    try {
      const moment = Date.parse('2026-10-24T03:30:00Z')
      const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
      const readerZone =
        browserZone === 'Pacific/Honolulu' ? 'Asia/Tokyo' : 'Pacific/Honolulu'
      const store = createHilosNotificationStore()
      store.ingestSnapshot({
        recent: [
          {
            id: 1,
            userId: 42,
            type: 'system',
            severity: 'info',
            title: 'Known moment',
            body: 'UTC epoch milliseconds',
            readAt: null,
            createdAt: moment,
          },
          {
            id: 2,
            userId: 42,
            type: 'system',
            severity: 'info',
            title: 'Epoch zero',
            readAt: null,
            createdAt: 0,
          },
          {
            id: 3,
            userId: 42,
            type: 'system',
            severity: 'info',
            title: 'No moment',
            readAt: null,
            createdAt: null,
          },
          {
            id: 4,
            userId: 42,
            type: 'system',
            severity: 'info',
            title: 'Invalid moment',
            readAt: null,
            createdAt: 8_640_000_000_000_001,
          },
        ],
        unreadCount: 4,
      })
      const { connection } = fakeConnection()
      act(() => reader.choose('UTC'))
      render(<HilosNotificationBell connection={connection} store={store} />)

      const toggle = byId('hilos-notification-toggle') as HTMLButtonElement
      fireEvent.click(toggle)
      const menu = byId('hilos-notification-menu')
      const markRead = byId(
        'hilos-notification-mark-read-1',
      ) as HTMLButtonElement
      markRead.focus()

      expect(byId('hilos-notification-time-1')?.textContent).toBe(
        formatIndependent(moment, 'UTC'),
      )
      expect(byId('hilos-notification-time-2')?.textContent).toBe(
        formatIndependent(0, 'UTC'),
      )
      expect(byId('hilos-notification-time-3')?.textContent).toBe('')
      expect(byId('hilos-notification-time-4')?.textContent).toBe('')
      expect(readerZone).not.toBe(browserZone)

      const notifications = store.notifications.get()
      act(() => reader.choose(readerZone))

      expect(hilosTimeZone.get()).toBe(readerZone)
      expect(store.notifications.get()).toBe(notifications)
      expect(byId('hilos-notification-menu')).toBe(menu)
      expect(toggle.getAttribute('aria-expanded')).toBe('true')
      expect(document.activeElement).toBe(markRead)
      expect(byId('hilos-notification-time-1')?.textContent).toBe(
        formatIndependent(moment, readerZone),
      )
      expect(byId('hilos-notification-time-2')?.textContent).toBe(
        formatIndependent(0, readerZone),
      )
      expect(byId('hilos-notification-time-2')?.textContent).not.toBe('')
      expect(byId('hilos-notification-time-3')?.textContent).toBe('')
      expect(byId('hilos-notification-time-4')?.textContent).toBe('')
    } finally {
      cleanup()
      reader.release()
    }
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
