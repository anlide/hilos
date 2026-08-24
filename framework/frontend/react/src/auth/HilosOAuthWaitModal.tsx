// The OAuth waiting modal (HIL-633), the React peer of
// framework/frontend/vue/src/auth/HilosOAuthWaitModal.vue. What a person sees in
// the page they stayed on while a provider window is open in front of it. The shell
// mounts it once, beside the toast host, so no project wires it and no page has to
// know a trip may be running over it.
//
// It shows for a LINK and not for a sign-in: a link is started from a live page that
// must be held still while the trip runs, whereas a sign-in is already parked on the
// auth surface's waiting screen, and a modal over that would be the same wait said
// twice. Cancel is offered only while the person is at the provider — once the code
// is being exchanged the window has closed itself and there is nothing left to take
// back. Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  cancelOAuthTrip,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
} from '@hilos/core'

import { HilosModal } from '../HilosModal.js'
import { useSignal } from '../useSignal.js'

/**
 * The wait an OAuth link puts over the page that started it.
 */
export function HilosOAuthWaitModal() {
  const trip = useSignal(oauthTrip)
  const open = trip?.intent === 'link'
  // Only the provider leg can be taken back; the exchange leg is our own round trip
  // and closing over it would leave the account half-linked in the person's head.
  const cancelable = trip?.phase === 'authorizing'

  return (
    <HilosModal
      open={open}
      title={trip ? oauthTripTitle(trip) : ''}
      closeOnEsc={cancelable}
      closeOnBackdrop={cancelable}
      // The trip owns whether this is open, so refusing to cancel simply leaves
      // it open.
      onClose={cancelable ? cancelOAuthTrip : undefined}
      actions={() =>
        cancelable ? (
          <button
            type="button"
            className="btn btn-outline-secondary"
            data-id="auth-oauth-wait-cancel"
            onClick={cancelOAuthTrip}
          >
            Cancel
          </button>
        ) : null
      }
    >
      <div className="text-center py-2" data-id="auth-oauth-wait">
        <div className="spinner-border text-primary mb-3" role="status">
          <span className="visually-hidden">Waiting</span>
        </div>
        <p className="mb-0 small text-body-secondary">
          {trip ? oauthTripMessage(trip) : ''}
        </p>
      </div>
    </HilosModal>
  )
}
