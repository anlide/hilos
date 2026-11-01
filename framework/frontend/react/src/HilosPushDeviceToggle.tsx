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
// and drops the row. With `channelRow` the same switch renders as a
// delivery-channel row — a decorative icon, the label and this browser's status
// on the left, the switch and its loader on the right — still one input and one
// action flow. Mirrors the Vue toggle. Bootstrap classes only.
import {
  HILOS_NOTIFICATION_PREFERENCES_COPY as COPY,
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
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
  /** Render a delivery-channel row instead of the compact device switch. */
  channelRow?: boolean
  /** Decorative Bootstrap Icons token for the delivery-channel row. */
  icon?: string
}

/**
 * The per-device web-push switch for the profile notification section.
 *
 * @param props The connection, the push channel's row fields, the optional
 *   delivery-channel row presentation, and an optional store override.
 */
export function HilosPushDeviceToggle({
  connection,
  channel,
  label,
  vapidPublicKey,
  store = hilosPushSubscription,
  channelRow = false,
  icon = 'bi-broadcast',
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
  const hintId = `${id}-hint`
  const subtitleId = `${id}-subtitle`

  // `denied` is terminal — the browser will not re-prompt — and an unsupported
  // browser cannot subscribe at all; both disable the switch and show a hint
  // instead of retrying.
  const hint = !supported
    ? COPY.pushUnsupported
    : permission === 'denied'
      ? COPY.pushBlocked
      : ''
  const describedBy = hint ? hintId : channelRow ? subtitleId : undefined

  // Per-device: subscribe or unsubscribe THIS browser rather than setting a
  // shared preference. The store owns the browser dance and the server round-trip.
  // The input stays controlled by the store, so it keeps the confirmed position
  // while the browser operation runs.
  function toggle(event: React.ChangeEvent<HTMLInputElement>): void {
    if (event.target.checked) {
      void store.enable(connection, vapidPublicKey)
    } else {
      void store.disable(connection)
    }
  }

  const pending = busy && (
    <span
      className={`spinner-border spinner-border-sm align-middle${channelRow ? '' : ' ms-2'}`}
      role="status"
      data-id={`hilos-notification-preference-pending-${channel}`}
    >
      <span className="visually-hidden">Saving…</span>
    </span>
  )

  const input = (
    <input
      id={id}
      className="form-check-input"
      type="checkbox"
      role="switch"
      checked={subscribed}
      disabled={hint !== '' || busy}
      aria-describedby={describedBy}
      aria-busy={busy}
      data-id={`hilos-notification-preference-toggle-${channel}`}
      onChange={toggle}
    />
  )

  if (!channelRow) {
    return (
      <div
        className="mb-2"
        data-id={`hilos-notification-preference-${channel}`}
      >
        <div className="form-check form-switch mb-0">
          {input}
          <label className="form-check-label" htmlFor={id}>
            {label}
          </label>
          {pending}
          {hint && (
            <div
              id={hintId}
              className="form-text mt-0"
              data-id={`hilos-notification-preference-hint-${channel}`}
            >
              {hint}
            </div>
          )}
        </div>
      </div>
    )
  }

  return (
    <div
      className="d-flex align-items-center gap-3 py-3 border-bottom"
      data-id={`hilos-notification-preference-${channel}`}
    >
      <i
        className={`bi ${icon} fs-5 text-body-secondary flex-shrink-0`}
        aria-hidden="true"
        data-id={`hilos-notification-preference-icon-${channel}`}
      />
      <div className="flex-grow-1 overflow-hidden">
        <label className="fw-semibold small" htmlFor={id}>
          {label}
        </label>
        <div
          className="hilos-stack small text-body-secondary text-break"
          data-id={`hilos-notification-preference-subtitle-${channel}`}
        >
          {[
            COPY.pushSubscribed,
            COPY.pushNotSubscribed,
            COPY.pushUnsupported,
            COPY.pushBlocked,
          ].map((text) => (
            <span key={text} className="invisible" aria-hidden="true" inert>
              {text}
            </span>
          ))}
          {hint ? (
            <div
              id={hintId}
              data-id={`hilos-notification-preference-hint-${channel}`}
            >
              {hint}
            </div>
          ) : (
            <div id={subtitleId}>
              {subscribed ? COPY.pushSubscribed : COPY.pushNotSubscribed}
            </div>
          )}
        </div>
      </div>
      <div className="flex-shrink-0 text-nowrap">
        <div className="form-check form-switch mb-0">
          {input}
          <span className="hilos-stack d-inline-grid ms-2 align-middle">
            <span
              className="spinner-border spinner-border-sm invisible"
              aria-hidden="true"
              inert
            />
            {pending}
          </span>
        </div>
      </div>
    </div>
  )
}
