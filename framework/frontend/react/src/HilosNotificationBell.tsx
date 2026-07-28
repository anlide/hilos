// HilosNotificationBell — the tier-1 notification-center surface (HIL-195): a
// bell button carrying the unread badge and a disclosure dropdown of the recent
// notifications, each with its own mark-read control plus a mark-all and a "see
// all" placeholder. It renders the framework-owned notification store
// (hilosNotifications, fed by the connection through bindNotificationsScope once
// `notifications: true` is passed to bootHilos) and marks read by firing the
// `notification_mark_read` / `notification_mark_all_read` actions — the store
// never updates optimistically; it turns read when the server fans the READ
// signal back to every one of the recipient's tabs (core-and-connection.md, the
// shared-state signal round-trip). A connection with no signed-in user never
// joins the group, so the bell simply shows an empty, zero-badge menu there.
// Open/close, outside-click, Escape, and arrow-roving mirror the Vue bell; the
// panel is a disclosure (button + aria-expanded), not a listbox, because each row
// hosts an action rather than a single-select option. Unread is never signalled
// by color alone — an unread row is bold and carries a visually-hidden "Unread"
// marker (styling-rules.md, accessibility.md). Bootstrap classes only.
import {
  hilosNotifications,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
  type HilosNotification,
  type HilosNotificationStore,
} from '@hilos/core'
import { useEffect, useId, useRef, useState } from 'react'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosNotificationBell}. */
export interface HilosNotificationBellProps {
  /** The connection the mark-read actions are sent over. */
  connection: HilosConnection
  /** The store the bell renders; defaults to the shared framework store. */
  store?: HilosNotificationStore
}

/**
 * The notification bell: an unread badge over a bell button that opens the
 * recent-notifications dropdown.
 *
 * @param props The connection and an optional store override.
 */
export function HilosNotificationBell({
  connection,
  store = hilosNotifications,
}: HilosNotificationBellProps) {
  const notifications = useSignal(store.notifications)
  const unreadCount = useSignal(store.unreadCount)
  // The badge caps its label so a runaway count never breaks the bell's layout.
  const badgeLabel = unreadCount > 99 ? '99+' : String(unreadCount)

  const [open, setOpen] = useState(false)
  const menuId = useId()
  const root = useRef<HTMLDivElement>(null)
  const menu = useRef<HTMLDivElement>(null)
  const toggleButton = useRef<HTMLButtonElement>(null)

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosDropdown / HilosModal).
  useEffect(() => {
    if (!open) {
      return
    }
    const onDocumentClick = (event: MouseEvent): void => {
      if (root.current && !root.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('click', onDocumentClick)

    return () => document.removeEventListener('click', onDocumentClick)
  }, [open])

  // The per-row mark-read buttons the arrow keys rove over; the header/footer
  // controls (mark-all, see-all) stay Tab-reachable but out of the roving ring.
  function itemButtons(): HTMLButtonElement[] {
    return menu.current
      ? Array.from(
          menu.current.querySelectorAll<HTMLButtonElement>(
            'ul button:not(:disabled)',
          ),
        )
      : []
  }

  function focusItem(which: 'first' | 'last'): void {
    const buttons = itemButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }

  function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
    setOpen(true)
    if (focus !== 'none') {
      // The panel mounts on the same tick; focus after paint.
      requestAnimationFrame(() => focusItem(focus))
    }
  }

  function close(returnFocus = false): void {
    setOpen(false)
    if (returnFocus) {
      toggleButton.current?.focus()
    }
  }

  function moveFocus(delta: 1 | -1): void {
    const buttons = itemButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }

  function onToggleKeydown(event: React.KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      openMenu('first')
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      openMenu('last')
    } else if (event.key === 'Escape') {
      event.preventDefault()
      close(true)
    }
  }

  function onMenuKeydown(event: React.KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        moveFocus(1)
        break
      case 'ArrowUp':
        event.preventDefault()
        moveFocus(-1)
        break
      case 'Home':
        event.preventDefault()
        focusItem('first')
        break
      case 'End':
        event.preventDefault()
        focusItem('last')
        break
      case 'Escape':
        event.preventDefault()
        close(true)
        break
    }
  }

  // Mark-read is fire-and-forget: the store turns read only when the server fans
  // the READ signal back (no optimistic path), so a failed send simply leaves the
  // row unread rather than desyncing the badge.
  function markRead(row: HilosNotification): void {
    connection.sendAction(NOTIFICATION_ACTION_MARK_READ, { id: row.id })
  }

  function markAllRead(): void {
    connection.sendAction(NOTIFICATION_ACTION_MARK_ALL_READ, {})
  }

  // A notification's timestamp, formatted for the reader's locale; the wire value
  // is an ISO string, absent only on a malformed row.
  function formatTime(row: HilosNotification): string {
    return row.createdAt ? new Date(row.createdAt).toLocaleString() : ''
  }

  return (
    <div ref={root} className="dropdown" data-id="hilos-notification-bell">
      <button
        ref={toggleButton}
        type="button"
        className="btn btn-link nav-link position-relative d-inline-flex align-items-center p-0 fs-5"
        aria-expanded={open}
        aria-controls={menuId}
        aria-haspopup="true"
        aria-label="Notifications"
        data-id="hilos-notification-toggle"
        onClick={() => (open ? close() : openMenu())}
        onKeyDown={onToggleKeydown}
      >
        <i className="bi bi-bell" aria-hidden="true" />
        {unreadCount > 0 && (
          <span
            className="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle"
            data-id="hilos-notification-badge"
          >
            {badgeLabel}
            <span className="visually-hidden"> unread notifications</span>
          </span>
        )}
      </button>
      {open && (
        <div
          id={menuId}
          ref={menu}
          className="dropdown-menu dropdown-menu-end show p-0"
          data-id="hilos-notification-menu"
          onKeyDown={onMenuKeydown}
        >
          <div className="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span className="fw-semibold">Notifications</span>
            <button
              type="button"
              className="btn btn-link btn-sm p-0"
              disabled={unreadCount === 0}
              data-id="hilos-notification-mark-all"
              onClick={markAllRead}
            >
              Mark all read
            </button>
          </div>
          <ul className="list-unstyled mb-0" style={{ maxWidth: '22rem' }}>
            {notifications.length === 0 && (
              <li>
                <p
                  className="dropdown-item-text text-body-secondary small mb-0 px-3 py-3"
                  data-id="hilos-notification-empty"
                >
                  No notifications
                </p>
              </li>
            )}
            {notifications.map((row) => (
              <li
                key={row.id}
                className="border-bottom"
                data-id={`hilos-notification-item-${row.id}`}
              >
                <div className="d-flex align-items-start gap-2 px-3 py-2">
                  <div className="flex-grow-1">
                    <div className={row.readAt == null ? 'fw-bold' : undefined}>
                      {row.title}
                      {row.readAt == null && (
                        <span className="visually-hidden"> (Unread)</span>
                      )}
                    </div>
                    {row.body && (
                      <div className="small text-body-secondary">
                        {row.body}
                      </div>
                    )}
                    <div className="small text-body-tertiary">
                      {formatTime(row)}
                    </div>
                  </div>
                  {row.readAt == null && (
                    <button
                      type="button"
                      className="btn btn-link btn-sm p-0 flex-shrink-0"
                      aria-label={`Mark "${row.title}" read`}
                      data-id={`hilos-notification-mark-read-${row.id}`}
                      onClick={() => markRead(row)}
                    >
                      <i className="bi bi-check2" aria-hidden="true" />
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          <div className="border-top px-3 py-2 text-center">
            <button
              type="button"
              className="btn btn-link btn-sm p-0"
              disabled
              data-id="hilos-notification-see-all"
            >
              See all
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
