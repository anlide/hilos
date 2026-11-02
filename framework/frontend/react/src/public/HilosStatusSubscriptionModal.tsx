// HilosStatusSubscriptionModal — the public /status window that subscribes an
// address to outage letters or unsubscribes the one an emailed link names.
// React counterpart of the Vue modal. The page owns the controller's lifetime;
// this window only draws its form: the core controller decides what may be
// sent, holds the private link token, waits for a usable connection and closes
// on the server's answer alone.
//
// Not exported from the package. It is the public Status page's own window and
// has no second caller. Bootstrap classes only (styling-rules.md).
import { useId } from 'react'
import {
  STATUS_SUBSCRIPTION_COPY as COPY,
  type StatusSubscription,
} from '@hilos/core/status'

import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'

/** Props for {@link HilosStatusSubscriptionModal}. */
export interface HilosStatusSubscriptionModalProps {
  /** The single mounted public page's subscription controller. */
  controller: StatusSubscription
}

/**
 * The subscribe or unsubscribe window of the public Status page.
 *
 * @param props The page-owned subscription controller.
 */
export function HilosStatusSubscriptionModal({
  controller,
}: HilosStatusSubscriptionModalProps) {
  const operation = useSignal(controller.operation)
  const dialog = useSignal(controller.dialog)
  const draft = useSignal(controller.draft)
  const busy = useSignal(controller.busy)
  const usable = useSignal(controller.usable)
  const step = useSignal(controller.step)
  const error = useSignal(controller.error)
  const maskedEmail = useSignal(controller.maskedEmail)
  const canSubmit = useSignal(controller.canSubmit)
  const formId = `status-subscription-${useId()}`
  const emailId = `${formId}-email`
  const hintId = `${formId}-hint`
  const errorId = `${formId}-error`
  const subscribing = operation === 'subscribe'
  // Only subscribing and unsubscribing open this window; a confirmation link
  // and a broken one answer in the page's own slot.
  const title = subscribing ? COPY.subscribe : COPY.unsubscribe
  const progress =
    !usable || step === 'waiting'
      ? COPY.waiting
      : step !== 'pending'
        ? ''
        : operation === 'unsubscribe' && maskedEmail === null
          ? 'Checking your unsubscribe link…'
          : 'Waiting for confirmation…'

  return (
    <HilosModal
      open={dialog}
      title={title}
      busy={busy}
      initialFocus={subscribing ? '' : 'dialog'}
      onClose={() => controller.close()}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            data-id="status-subscription-cancel"
            onClick={requestClose}
          >
            {subscribing ? 'Cancel' : COPY.keep}
          </button>
          {subscribing || maskedEmail !== null ? (
            <LoadingButton
              type="submit"
              form={formId}
              className={subscribing ? 'btn-primary' : 'btn-danger'}
              loading={busy}
              disabled={!busy && !canSubmit}
              data-id="status-subscription-submit"
            >
              {subscribing ? 'Subscribe' : COPY.unsubscribe}
            </LoadingButton>
          ) : operation === 'unsubscribe' ? (
            <span className="btn btn-danger invisible" aria-hidden="true" inert>
              {COPY.unsubscribe}
            </span>
          ) : null}
        </>
      )}
    >
      <form
        id={formId}
        noValidate
        data-id="status-subscription-form"
        onSubmit={(event) => {
          event.preventDefault()
          void controller.submit()
        }}
      >
        {subscribing ? (
          <>
            <p className="text-body-secondary mb-2">
              Letters about outages. You do not need an account.
            </p>
            <label className="form-label" htmlFor={emailId}>
              Email address
            </label>
            <input
              id={emailId}
              value={draft.email}
              type="email"
              name="email"
              autoComplete="email"
              className="form-control form-control-sm"
              data-autofocus
              data-id="status-subscription-email"
              aria-describedby={`${hintId} ${errorId}`}
              aria-invalid={error ? true : undefined}
              onChange={(event) =>
                controller.setDraft({ email: event.target.value })
              }
            />
            <p id={hintId} className="small text-body-secondary mt-2 mb-0">
              We will send a link to confirm the address. Nothing else is sent
              until you confirm, and every letter has a link to unsubscribe. If
              you did not ask for the email, ignore it.
            </p>
          </>
        ) : operation === 'unsubscribe' ? (
          <p className="mb-0 text-break" data-id="status-subscription-address">
            Stop sending status updates to <strong>{maskedEmail ?? '…'}</strong>
            ?
          </p>
        ) : null}

        <div className="hilos-stack small mt-2">
          <p className="invisible mb-0" aria-hidden="true" inert>
            {COPY.waiting}
          </p>
          <p className="mb-0" data-id="status-subscription-progress">
            {progress}
          </p>
        </div>
        <div
          className="visually-hidden"
          role="status"
          aria-live="polite"
          aria-atomic="true"
          data-id="status-subscription-live"
        >
          {progress}
        </div>
        <div id={errorId}>
          <HilosFormError
            message={error}
            dataId="status-subscription-error"
            announce
          />
        </div>
      </form>
    </HilosModal>
  )
}
