// HilosStatusRemoveSubscriberModal — the confirmation window for removing one
// Status subscriber. React counterpart of the Vue modal: the page-owned core
// controller captures the subscription the trash opened on — its id and its
// address — decides whether that subscription still allows the removal, and
// runs the tracked action; this view only draws that state. Removal always
// addresses the captured subscription, never its replacement. The window stays
// open on a refusal, an unconfirmed outcome or a subscription that is gone, and
// closes on the server's answer alone; only a correlated success of this
// still-mounted window speaks to its initiator. Bootstrap classes only.
import { useId } from 'react'
import { hilosToasts } from '@hilos/core/base'
import {
  HILOS_STATUS_SUBSCRIBERS_COPY,
  type HilosStatusSubscriberController,
} from '@hilos/core/admin/status'

import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosStatusRemoveSubscriberModal}. */
export interface HilosStatusRemoveSubscriberModalProps {
  /** The page-owned removal window and its correlated action lifecycle. */
  controller: HilosStatusSubscriberController
}

/**
 * The removal of the subscription captured when the window opened.
 *
 * @param props The page-owned removal controller.
 */
export function HilosStatusRemoveSubscriberModal({
  controller,
}: HilosStatusRemoveSubscriberModalProps) {
  const dialog = useSignal(controller.dialog)
  const target = useSignal(controller.target)
  const busy = useSignal(controller.busy)
  const error = useSignal(controller.error)
  const failure = useSignal(controller.failure)
  const blockedReason = useSignal(controller.blockedReason)
  const canSubmit = useSignal(controller.canSubmit)
  const formId = useId()
  const descriptionId = useId()
  const detail = failure?.errorDetail
  const copyText = detail
    ? [failure?.errorType, detail].filter(Boolean).join('\n')
    : (error ?? '')

  /** Only a correlated success from this still-mounted window speaks to its initiator. */
  async function submit(): Promise<void> {
    if (await controller.submit()) {
      const message = controller.success.get()
      if (message !== null) hilosToasts.push(message, { severity: 'success' })
    }
  }

  return (
    <HilosModal
      open={dialog !== null}
      title={HILOS_STATUS_SUBSCRIBERS_COPY.remove}
      busy={busy}
      initialFocus="dialog"
      onClose={() => controller.close()}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            data-id="hilos-status-subscriber-remove-cancel"
            onClick={requestClose}
          >
            {HILOS_STATUS_SUBSCRIBERS_COPY.cancel}
          </button>
          <LoadingButton
            form={formId}
            type="submit"
            className="btn-danger"
            loading={busy}
            disabled={!busy && !canSubmit}
            data-id="hilos-status-subscriber-remove-confirm"
          >
            {!busy && target === null
              ? HILOS_STATUS_SUBSCRIBERS_COPY.deletedLabel
              : HILOS_STATUS_SUBSCRIBERS_COPY.removeConfirm}
          </LoadingButton>
        </>
      )}
    >
      {dialog && (
        <form
          id={formId}
          aria-label={HILOS_STATUS_SUBSCRIBERS_COPY.remove}
          aria-describedby={descriptionId}
          data-id="hilos-status-subscriber-remove-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <HilosFormError
            message={error}
            errorType={failure?.errorType}
            errorDetail={failure?.errorDetail}
            detailsTitle={HILOS_STATUS_SUBSCRIBERS_COPY.remove}
            copyText={copyText}
            dataId="hilos-status-subscriber-remove-error"
            announce
          />
          <p
            className="fw-semibold text-break"
            data-id="hilos-status-subscriber-remove-target"
          >
            <HilosHideable value={dialog.email} /> · #{dialog.subscriberId}
          </p>
          <p id={descriptionId}>{HILOS_STATUS_SUBSCRIBERS_COPY.removeLead}</p>
          <p className="text-body-secondary">
            {HILOS_STATUS_SUBSCRIBERS_COPY.removeHint}
          </p>
          <HilosEditNotice
            kind={blockedReason ? 'deleted' : null}
            text={blockedReason ?? ''}
            dataId="hilos-status-subscriber-remove-blocked"
          />
        </form>
      )}
    </HilosModal>
  )
}
