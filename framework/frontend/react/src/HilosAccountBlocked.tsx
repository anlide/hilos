// HilosAccountBlocked — the "Access closed" card of the app shell (HIL-289).
// HilosLayout draws it in place of the routed content, on every url, for as
// long as the session holds a blocked account: the account this browser lost,
// or was refused, because the project administration blocked it. The header and
// footer stay; what stood in the content — modals included — goes with it.
//
// The card stands in a narrow centered column of Bootstrap's grid, where a
// sign-in form would have stood; the difference is carried by the words and the
// color. The reason is its own block, saying there is none: an empty spot would
// read as "still loading". Sign out is a tracked action with the driver's
// defaults, busy until the answer — the card leaves by itself with the state
// the answer rides behind. Internal to the shell, not exported from the
// package. The words are the core's ACCOUNT_BLOCKED_COPY, one set for the three
// view packages. Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  ACCOUNT_BLOCKED_COPY,
  dismissAccountBlocked,
  type AccountBlockedNotice,
} from '@hilos/core'

import { LoadingButton } from './LoadingButton.js'
import { useTrackedAction } from './useTrackedAction.js'

/** Props for {@link HilosAccountBlocked}. */
export interface HilosAccountBlockedProps {
  /** The card the session holds: whose account it lost, when the server knows. */
  notice: AccountBlockedNotice
}

/**
 * The "Access closed" card the shell draws in place of the content.
 *
 * @param props The card the session holds.
 */
export function HilosAccountBlocked({ notice }: HilosAccountBlockedProps) {
  const signOut = useTrackedAction()
  const onSignOut = (): void => {
    if (signOut.busy) {
      return
    }
    void signOut.run(dismissAccountBlocked())
  }

  return (
    <div className="row justify-content-center" data-id="account-blocked">
      <section
        className="col-12 col-sm-8 col-md-6 col-lg-4"
        aria-labelledby="hilos-account-blocked-heading"
      >
        <div className="text-center mb-3">
          <span className="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-4 lh-1">
            <i className="bi bi-slash-circle" aria-hidden="true"></i>
          </span>
        </div>
        <h2
          id="hilos-account-blocked-heading"
          className="h5 text-center mb-2"
          data-id="account-blocked-heading"
        >
          {ACCOUNT_BLOCKED_COPY.title}
        </h2>
        <p
          className="small text-body-secondary text-center mb-3"
          data-id="account-blocked-account"
        >
          {notice.identifier !== null ? (
            <>
              {ACCOUNT_BLOCKED_COPY.accountLead}{' '}
              <span className="fw-semibold">{notice.identifier}</span>{' '}
              {ACCOUNT_BLOCKED_COPY.accountTail}
            </>
          ) : (
            ACCOUNT_BLOCKED_COPY.accountUnnamed
          )}
        </p>
        <div
          className="border border-danger-subtle bg-danger-subtle rounded px-3 py-2 mb-3"
          data-id="account-blocked-reason"
        >
          <div className="small fw-semibold mb-1">
            {ACCOUNT_BLOCKED_COPY.reasonTitle}
          </div>
          <div className="small text-body-secondary">
            {ACCOUNT_BLOCKED_COPY.reasonText}
          </div>
        </div>
        <p className="small text-body-secondary mb-3">
          {ACCOUNT_BLOCKED_COPY.contact}
        </p>
        <LoadingButton
          className="btn-outline-secondary w-100"
          data-id="account-blocked-sign-out"
          loading={signOut.busy}
          onClick={onSignOut}
        >
          {ACCOUNT_BLOCKED_COPY.signOut}
        </LoadingButton>
      </section>
    </div>
  )
}
