// HilosStatusCancelWorkModal — the confirmation window for canceling one
// scheduled maintenance announcement. React counterpart of the Vue modal: the
// page-owned core controller holds the focused row's immutable public identity,
// follows that announcement's live caption and interval, decides whether it
// still allows the cancellation, and runs the tracked action with the live
// version; this view only draws that state. The window stays open on a refusal,
// an unconfirmed outcome or an announcement that ended, and closes on the
// server's answer alone; only a correlated success of this still-mounted window
// speaks to its initiator. Bootstrap classes only.
import { useId } from 'react'
import { formatMoment, hilosTimeZone, hilosToasts } from '@hilos/core/base'
import type { HilosStatusWorkCancel } from '@hilos/core/admin/status'

import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosStatusCancelWorkModal}. */
export interface HilosStatusCancelWorkModalProps {
  /** The page-owned focus and correlated cancellation lifecycle. */
  controller: HilosStatusWorkCancel
}

/** The window's name, its heading and the refusal's details title. */
const TITLE = 'Cancel scheduled maintenance'

/**
 * One end of the announcement's interval, to the second, in the reader zone.
 *
 * @param at The moment in epoch milliseconds.
 */
function moment(at: number): string {
  return (
    formatMoment(at, {
      format: {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
      },
    }) ?? '—'
  )
}

/**
 * The cancellation of the announcement focused when the window opened.
 *
 * @param props The page-owned cancellation controller.
 */
export function HilosStatusCancelWorkModal({
  controller,
}: HilosStatusCancelWorkModalProps) {
  const opened = useSignal(controller.opened)
  const target = useSignal(controller.target)
  const busy = useSignal(controller.busy)
  const canSubmit = useSignal(controller.canSubmit)
  const blockedReason = useSignal(controller.blockedReason)
  const error = useSignal(controller.error)
  const failure = useSignal(controller.failure)
  // The interval follows the reader zone without a server reread.
  useSignal(hilosTimeZone)
  const formId = useId()
  const descriptionId = useId()
  const copyText = failure?.errorDetail
    ? [failure.errorType, failure.errorDetail].filter(Boolean).join('\n')
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
      open={opened}
      title={TITLE}
      busy={busy}
      initialFocus="dialog"
      onClose={() => controller.close()}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            data-id="hilos-status-work-cancel-close"
            onClick={requestClose}
          >
            Back
          </button>
          <LoadingButton
            form={formId}
            type="submit"
            className="btn-danger"
            loading={busy}
            disabled={!busy && !canSubmit}
            data-id="hilos-status-work-cancel-confirm"
          >
            {!busy && target === undefined ? 'Deleted' : 'Cancel maintenance'}
          </LoadingButton>
        </>
      )}
    >
      {opened && (
        <form
          id={formId}
          aria-label={TITLE}
          aria-describedby={descriptionId}
          data-id="hilos-status-work-cancel-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <HilosFormError
            message={error}
            errorType={failure?.errorType}
            errorDetail={failure?.errorDetail}
            detailsTitle={TITLE}
            copyText={copyText}
            dataId="hilos-status-work-cancel-error"
            announce
          />
          <p
            className="fw-semibold text-break"
            data-id="hilos-status-work-cancel-target"
          >
            {target?.title ?? 'Announcement unavailable'}
          </p>
          {target && (
            <p data-id="hilos-status-work-cancel-interval">
              {moment(target.startsAt)} – {moment(target.endsAt)}
            </p>
          )}
          <p id={descriptionId} className="mb-0 text-body-secondary">
            This announcement will leave the upcoming list. Subscribers will be
            notified of the cancellation.
          </p>
          <HilosEditNotice
            kind={blockedReason ? 'deleted' : null}
            text={blockedReason ?? ''}
            dataId="hilos-status-work-cancel-blocked"
          />
        </form>
      )}
    </HilosModal>
  )
}
