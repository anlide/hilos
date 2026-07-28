// HilosNotificationPreferences — the profile "Notifications" section (HIL-485):
// one switch per globally-enabled channel letting the signed-in user opt in or
// out, plus an always-on note when the project declares any mandatory
// notification type. It renders the framework-owned preference store
// (hilosNotificationPreferences), which the mounting profile surface feeds from
// the profile page data (the section snapshot) and the
// `notification_preferences_changed` signal (live multi-device sync) — see
// notificationPreferences.ts. A toggle is tracked, never optimistic: it marks the
// row pending (a per-row loader on the switch) and fires the
// `profile_notification_channel_set` action; the switch turns only when the
// server fans the changed signal back to every one of the user's tabs, and a send
// that never leaves simply settles the loader and snaps the row back. A channel
// with no address for it is shown disabled with a hint to add one rather than
// hidden, so the user sees the whole channel set. Mandatory types carry no toggle
// — a switch that cannot be turned off is worse than none — only the note. Sparse
// opt-out: no muted row means allowed. Mirrors the Vue section. Bootstrap classes
// only.
import {
  hilosNotificationPreferences,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
} from '@hilos/core'
import { useId } from 'react'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosNotificationPreferences}. */
export interface HilosNotificationPreferencesProps {
  /** The connection the toggle action is sent over. */
  connection: HilosConnection
  /** The store the section renders; defaults to the shared framework store. */
  store?: HilosNotificationPreferencesStore
}

/**
 * The profile notification-preferences section: a switch per channel plus the
 * mandatory-types note.
 *
 * @param props The connection and an optional store override.
 */
export function HilosNotificationPreferences({
  connection,
  store = hilosNotificationPreferences,
}: HilosNotificationPreferencesProps) {
  const channels = useSignal(store.channels)
  const mandatoryNote = useSignal(store.mandatoryNote)
  const pending = useSignal(store.pending)

  // One id base for this instance so two sections never collide on label `for`.
  const baseId = useId()

  // Toggling is tracked, not optimistic: mark the row pending, send the action,
  // and let the changed signal settle it. A send that never leaves (no live
  // connection) settles the loader here so the row snaps back to its last
  // confirmed state instead of hanging spinning.
  function toggle(
    row: HilosNotificationChannelState,
    event: React.ChangeEvent<HTMLInputElement>,
  ): void {
    const enabled = event.target.checked
    store.markPending(row.channel)
    const sent = connection.sendAction(NOTIFICATION_ACTION_CHANNEL_SET, {
      channel: row.channel,
      enabled,
    })
    if (!sent) {
      store.clearPending(row.channel)
    }
  }

  return (
    <section
      aria-labelledby={`${baseId}-heading`}
      data-id="hilos-notification-preferences"
    >
      <h2 id={`${baseId}-heading`} className="h5">
        Notifications
      </h2>
      {channels.length === 0 && (
        <p
          className="text-body-secondary mb-0"
          data-id="hilos-notification-preferences-empty"
        >
          No notification channels are available.
        </p>
      )}
      {channels.map((row) => {
        const id = `${baseId}-${row.channel}`
        const isPending = pending.has(row.channel)

        return (
          <div
            key={row.channel}
            className="form-check form-switch mb-2"
            data-id={`hilos-notification-preference-${row.channel}`}
          >
            <input
              id={id}
              className="form-check-input"
              type="checkbox"
              role="switch"
              checked={row.allowed}
              disabled={!row.hasAddress || isPending}
              aria-describedby={row.hasAddress ? undefined : `${id}-hint`}
              aria-busy={isPending}
              data-id={`hilos-notification-preference-toggle-${row.channel}`}
              onChange={(event) => toggle(row, event)}
            />
            <label className="form-check-label" htmlFor={id}>
              {row.label}
            </label>
            {isPending && (
              <span
                className="spinner-border spinner-border-sm ms-2 align-middle"
                role="status"
                data-id={`hilos-notification-preference-pending-${row.channel}`}
              >
                <span className="visually-hidden">Saving…</span>
              </span>
            )}
            {!row.hasAddress && (
              <div
                id={`${id}-hint`}
                className="form-text mt-0"
                data-id={`hilos-notification-preference-hint-${row.channel}`}
              >
                Add an address in your profile to enable this channel.
              </div>
            )}
          </div>
        )
      })}
      {mandatoryNote && (
        <p
          className="text-body-secondary small mb-0 mt-2"
          data-id="hilos-notification-preferences-mandatory"
        >
          Security messages are always delivered.
        </p>
      )}
    </section>
  )
}
