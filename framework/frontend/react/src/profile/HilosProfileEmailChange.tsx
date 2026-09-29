import { useEffect, useRef, type ChangeEvent } from 'react'
import {
  focusInitial,
  HILOS_PROFILE_EMAIL_CHANGE_COPY as COPY,
  HILOS_PROFILE_EMAIL_CHANGE_STEPS,
  HILOS_STEP_UP_COPY,
  type HilosProfileEmailChangeFlow,
  type WritableSignal,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { useSignal } from '../useSignal.js'

export interface HilosProfileEmailChangeProps {
  flow: HilosProfileEmailChangeFlow
}

/**
 * A change handler that puts a typed value into the flow.
 *
 * @param target The flow's value the field edits.
 */
function typed(target: WritableSignal<string>) {
  return (event: ChangeEvent<HTMLInputElement>) =>
    target.set(event.target.value)
}

/**
 * Draw the profile root's email window (HIL-299, HIL-1169): one modal, five
 * steps, the content changing in place. Every step is one server-confirmed
 * submit; a refusal stays on the step in the room HilosFormError holds for it.
 * Closing on steps 2 to 4 asks first, because a code is already out.
 */
export function HilosProfileEmailChange({
  flow,
}: HilosProfileEmailChangeProps) {
  const step = useSignal(flow.step)
  const was = useSignal(flow.was)
  const currentCode = useSignal(flow.currentCode)
  const newEmail = useSignal(flow.newEmail)
  const newCode = useSignal(flow.newCode)
  const now = useSignal(flow.now)
  const busy = useSignal(flow.busy)
  const refusal = useSignal(flow.refusal)
  const canSubmit = useSignal(flow.canSubmit)
  const asksBeforeClosing = useSignal(flow.asksBeforeClosing)
  const stepUpOpening = useSignal(flow.stepUp.opening)
  const stepUpRefusal = useSignal(flow.stepUp.refusal)
  const body = useRef<HTMLDivElement | null>(null)
  const previous = useRef(step)
  const title =
    step === 'step-up'
      ? HILOS_STEP_UP_COPY.title
      : step === 'done'
        ? COPY.doneTitle
        : COPY.title
  // The step's place in the list: 1 to 5, 0 on the confirmation.
  const stepNumber =
    HILOS_PROFILE_EMAIL_CHANGE_STEPS.indexOf(
      step as (typeof HILOS_PROFILE_EMAIL_CHANGE_STEPS)[number],
    ) + 1
  function submit(event: { preventDefault(): void }): void {
    event.preventDefault()
    void flow.submit()
  }

  // The confirmation's body is replaced by step one: focus it.
  useEffect(() => {
    const from = previous.current
    previous.current = step
    if (from !== 'step-up' || step !== 'send-current') return
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  }, [step])

  return (
    <HilosModal
      open={step !== 'closed'}
      onClose={() => flow.close()}
      title={title}
      confirmOnClose={asksBeforeClosing}
      initialFocus="inner"
      actions={({ requestClose }) =>
        step === 'step-up' ? (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-email-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            {stepUpOpening !== null ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                data-id="profile-email-step-up-confirm"
                onClick={() => void flow.submit()}
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : null}
          </>
        ) : step !== 'done' ? (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-email-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            {step === 'send-current' ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                data-autofocus
                data-id="profile-email-send-current"
                onClick={() => void flow.submit()}
              >
                {COPY.sendCode}
              </LoadingButton>
            ) : step === 'confirm-current' ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                disabled={!canSubmit}
                data-id="profile-email-confirm-current"
                onClick={() => void flow.submit()}
              >
                {COPY.continue}
              </LoadingButton>
            ) : step === 'new-address' ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                disabled={!canSubmit}
                data-id="profile-email-send-new"
                onClick={() => void flow.submit()}
              >
                {COPY.sendCode}
              </LoadingButton>
            ) : (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                disabled={!canSubmit}
                data-id="profile-email-confirm-new"
                onClick={() => void flow.submit()}
              >
                {COPY.changeEmail}
              </LoadingButton>
            )}
          </>
        ) : (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-email-again"
              onClick={() => void flow.again()}
            >
              {COPY.again}
            </button>
            <button
              type="button"
              className="btn btn-primary"
              data-autofocus
              data-id="profile-email-done"
              onClick={requestClose}
            >
              {COPY.done}
            </button>
          </>
        )
      }
    >
      {/* This dialog's own voice; one region for all the steps, since only one
          step is on screen at a time. */}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-email-live-assertive"
      >
        {step === 'step-up' ? stepUpRefusal : refusal}
      </div>
      <div ref={body}>
        {step === 'step-up' ? (
          <HilosStepUpStep controller={flow.stepUp} />
        ) : null}
        {step !== 'step-up' && step !== 'done' ? (
          <ol
            className="list-unstyled d-flex flex-column gap-1 mb-3 small"
            data-id="profile-email-steps"
          >
            {COPY.steps.map((label, index) => (
              <li
                key={label}
                className={`d-flex align-items-center gap-2 ${
                  index + 1 === stepNumber
                    ? 'fw-semibold'
                    : 'text-body-secondary'
                }`}
                aria-current={index + 1 === stepNumber ? 'step' : undefined}
              >
                <span
                  className={`badge rounded-pill ${
                    index + 1 === stepNumber
                      ? 'text-bg-primary'
                      : 'text-bg-secondary'
                  }`}
                >
                  {index + 1}
                </span>
                <span>{label}</span>
              </li>
            ))}
          </ol>
        ) : null}
        {step === 'send-current' ? (
          <form onSubmit={submit}>
            <p className="small text-body-secondary mb-0">
              {COPY.sendCurrentLead} <strong>{was}</strong>{' '}
              {COPY.sendCurrentTail}
            </p>
            <HilosFormError message={refusal} dataId="profile-email-error" />
          </form>
        ) : step === 'confirm-current' ? (
          <form onSubmit={submit}>
            <label className="form-label" htmlFor="profile-email-code-current">
              {COPY.code}
            </label>
            <input
              id="profile-email-code-current"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              className="form-control"
              data-autofocus
              data-id="profile-email-code-current"
              value={currentCode}
              onChange={typed(flow.currentCode)}
            />
            <div className="form-text">
              {COPY.sentTo.replace('{address}', was)}
            </div>
            <HilosFormError message={refusal} dataId="profile-email-error" />
          </form>
        ) : step === 'new-address' ? (
          <form onSubmit={submit}>
            <label className="form-label" htmlFor="profile-email-new">
              {COPY.newEmail}
            </label>
            <input
              id="profile-email-new"
              type="email"
              autoComplete="email"
              className="form-control"
              data-autofocus
              data-id="profile-email-new"
              value={newEmail}
              onChange={typed(flow.newEmail)}
            />
            <div className="form-text">{COPY.newEmailHint}</div>
            <HilosFormError message={refusal} dataId="profile-email-error" />
          </form>
        ) : step === 'confirm-new' ? (
          <form onSubmit={submit}>
            <label className="form-label" htmlFor="profile-email-code-new">
              {COPY.code}
            </label>
            <input
              id="profile-email-code-new"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              className="form-control"
              data-autofocus
              data-id="profile-email-code-new"
              value={newCode}
              onChange={typed(flow.newCode)}
            />
            <div className="form-text">
              {COPY.sentTo.replace('{address}', newEmail.trim())}
            </div>
            <HilosFormError message={refusal} dataId="profile-email-error" />
          </form>
        ) : step === 'done' ? (
          <div className="text-center py-2" data-id="profile-email-outcome">
            <i
              className="bi bi-check-circle-fill text-success fs-2 d-block mb-2"
              aria-hidden="true"
            ></i>
            <div className="fw-semibold mb-1">{COPY.changed}</div>
            <p className="small text-body-secondary mb-0">
              {COPY.outcomeWas} <s data-id="profile-email-was">{was}</s>,{' '}
              {COPY.outcomeNow}{' '}
              <strong data-id="profile-email-now">{now}</strong>.{' '}
              {COPY.outcomeTail}
            </p>
          </div>
        ) : null}
      </div>
    </HilosModal>
  )
}
