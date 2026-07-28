// HilosPushDeviceToggle — the profile "Notifications" section's control for the
// web-push channel (HIL-199). Unlike the other channel switches, which set the
// per-user preference (allowed/muted, shared across the user's tabs), web push
// is per-device browser state: this switch subscribes or unsubscribes THIS
// browser. It renders the framework-owned push-subscription store
// (hilosPushSubscription), which reflects the live PushManager rather than a
// server snapshot, so it reads the device on mount. A denied or unsupported
// browser cannot subscribe, so the switch is disabled with a hint rather than
// retrying. Enabling prompts for permission, subscribes with the channel's VAPID
// public key, and registers the subscription server-side; disabling unsubscribes
// and drops the row. Mirrors the Vue toggle. Bootstrap classes only.
import {
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushSubscriptionStore,
} from '@hilos/core'
import { useEffect, useId } from 'react'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosPushDeviceToggle}. */
export interface HilosPushDeviceToggleProps {
  /** The connection the subscribe/unsubscribe action is sent over. */
  connection: HilosConnection
  /** The push channel's registry name, used for stable element ids. */
  channel: string
  /** The push channel's human label. */
  label: string
  /** The channel's VAPID public key (base64url) the browser subscribes with. */
  vapidPublicKey: string
  /** The store the toggle renders; defaults to the shared framework store. */
  store?: HilosPushSubscriptionStore
}

/**
 * The per-device web-push switch for the profile notification section.
 *
 * @param props The connection, the push channel's row fields, and an optional store override.
 */
export function HilosPushDeviceToggle({
  connection,
  channel,
  label,
  vapidPublicKey,
  store = hilosPushSubscription,
}: HilosPushDeviceToggleProps) {
  const supported = useSignal(store.supported)
  const permission = useSignal(store.permission)
  const subscribed = useSignal(store.subscribed)
  const busy = useSignal(store.busy)

  // Read the live browser state (support, permission, subscription) on mount so
  // the switch reflects this device; the store's defaults are safe until then.
  useEffect(() => {
    void store.refresh()
  }, [store])

  const id = useId()

  // `denied` is terminal — the browser will not re-prompt — and an unsupported
  // browser cannot subscribe at all; both disable the switch and show a hint
  // instead of retrying.
  const hint = !supported
    ? 'Push notifications are not supported on this device.'
    : permission === 'denied'
      ? 'Push notifications are blocked in your browser settings.'
      : ''

  // Per-device: subscribe or unsubscribe THIS browser rather than setting a
  // shared preference. The store owns the browser dance and the server round-trip.
  function toggle(event: React.ChangeEvent<HTMLInputElement>): void {
    if (event.target.checked) {
      void store.enable(connection, vapidPublicKey)
    } else {
      void store.disable(connection)
    }
  }

  return (
    <div
      className="form-check form-switch mb-2"
      data-id={`hilos-notification-preference-${channel}`}
    >
      <input
        id={id}
        className="form-check-input"
        type="checkbox"
        role="switch"
        checked={subscribed}
        disabled={hint !== '' || busy}
        aria-describedby={hint ? `${id}-hint` : undefined}
        aria-busy={busy}
        data-id={`hilos-notification-preference-toggle-${channel}`}
        onChange={toggle}
      />
      <label className="form-check-label" htmlFor={id}>
        {label}
      </label>
      {busy && (
        <span
          className="spinner-border spinner-border-sm ms-2 align-middle"
          role="status"
          data-id={`hilos-notification-preference-pending-${channel}`}
        >
          <span className="visually-hidden">Saving…</span>
        </span>
      )}
      {hint && (
        <div
          id={`${id}-hint`}
          className="form-text mt-0"
          data-id={`hilos-notification-preference-hint-${channel}`}
        >
          {hint}
        </div>
      )}
    </div>
  )
}
