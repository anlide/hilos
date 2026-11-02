// HilosStatusIncidentModal — the one window for the three parameterized incident
// operations of the Status overview: Do not publish, Publish the outage now and
// Add an update. React counterpart of the Vue modal: the page-owned core
// controller captures the opening incident and the draft, decides whether the
// target still allows the operation, and runs the tracked action; this view only
// draws that state. The window stays open with its draft on a refusal, an
// unconfirmed outcome or a target that ended, and closes on the server's answer
// alone. Bootstrap classes only.
import { useId } from 'react'
import type { KeyboardEvent } from 'react'
import {
  HILOS_STATUS_ACTION_COPY,
  type HilosStatusIncidentController,
} from '@hilos/core/admin/status'

import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosStatusIncidentModal}. */
export interface HilosStatusIncidentModalProps {
  /** The page-owned incident window, including its tracked submit. */
  controller: HilosStatusIncidentController
}

/**
 * Three incident operations sharing the core's captured target and draft.
 *
 * @param props The page-owned incident controller.
 */
export function HilosStatusIncidentModal({
  controller,
}: HilosStatusIncidentModalProps) {
  const dialog = useSignal(controller.dialog)
  const target = useSignal(controller.target)
  const draft = useSignal(controller.draft)
  const busy = useSignal(controller.busy)
  const error = useSignal(controller.error)
  const failure = useSignal(controller.failure)
  const blockedReason = useSignal(controller.blockedReason)
  const canSubmit = useSignal(controller.canSubmit)
  const formId = useId()
  const fieldId = useId()
  const copy = dialog ? HILOS_STATUS_ACTION_COPY[dialog.operation] : null
  const detail = failure?.errorDetail
  const copyText = detail
    ? [failure?.errorType, detail].filter(Boolean).join('\n')
    : (error ?? '')

  /**
   * Enter confirms; a multiline update keeps Enter and submits with
   * Ctrl/Command+Enter. A key pressed in a details window the form's refusal
   * or notice opened is not this form's: that window is a portal outside the
   * form's DOM, yet its key still bubbles up the React tree to here.
   *
   * @param event The key pressed inside the form.
   */
  function onKeyDown(event: KeyboardEvent<HTMLFormElement>): void {
    if (event.key !== 'Enter') return
    if (!event.currentTarget.contains(event.target as Node)) return
    if (event.target instanceof Element && event.target.closest('button, a'))
      return
    if (dialog?.operation === 'add-update' && !event.ctrlKey && !event.metaKey)
      return
    event.preventDefault()
    void controller.submit()
  }

  return (
    <HilosModal
      open={dialog !== null}
      title={copy?.title ?? ''}
      busy={busy}
      initialFocus={dialog?.operation === 'add-update' ? 'inner' : 'dialog'}
      onClose={() => controller.close()}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            data-id="hilos-status-incident-cancel"
            onClick={requestClose}
          >
            {HILOS_STATUS_ACTION_COPY.cancel}
          </button>
          <LoadingButton
            form={formId}
            type="submit"
            className={
              dialog?.operation === 'add-update' ? 'btn-primary' : 'btn-danger'
            }
            loading={busy}
            disabled={!busy && !canSubmit}
            data-id="hilos-status-incident-confirm"
          >
            {copy?.confirm}
          </LoadingButton>
        </>
      )}
    >
      {dialog && copy && (
        <form
          id={formId}
          data-id="hilos-status-incident-form"
          onSubmit={(event) => {
            event.preventDefault()
            void controller.submit()
          }}
          onKeyDown={onKeyDown}
        >
          <HilosFormError
            message={error}
            errorType={failure?.errorType}
            errorDetail={failure?.errorDetail}
            detailsTitle={copy.refusalTitle}
            copyText={copyText}
            dataId="hilos-status-incident-error"
            announce
          />
          <p
            className="fw-semibold text-break"
            data-id="hilos-status-incident-target"
          >
            {dialog.incident.componentName} · #{dialog.incident.incidentId}
          </p>
          <p>{copy.lead}</p>
          {target && (
            <p
              className="small text-body-secondary"
              data-id="hilos-status-incident-time"
            >
              {target.label} ·{' '}
              {target.publishedAtLabel ?? target.publishAtLabel}
            </p>
          )}
          <HilosEditNotice
            kind={blockedReason ? 'deleted' : null}
            text={blockedReason ?? ''}
            dataId="hilos-status-incident-blocked"
          />
          {dialog.operation === 'add-update' && (
            <>
              <label htmlFor={fieldId} className="form-label">
                {HILOS_STATUS_ACTION_COPY.updateField}
              </label>
              <textarea
                id={fieldId}
                value={draft}
                className="form-control"
                rows={5}
                placeholder={HILOS_STATUS_ACTION_COPY.updatePlaceholder}
                data-id="hilos-status-update-text"
                data-autofocus
                onChange={(event) => controller.setDraft(event.target.value)}
              />
              <p className="small text-body-secondary mb-0 mt-2">
                Ctrl/Command+Enter to publish.
              </p>
            </>
          )}
        </form>
      )}
    </HilosModal>
  )
}
