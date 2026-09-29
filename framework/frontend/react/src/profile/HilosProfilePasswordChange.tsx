import { useEffect, useId, useRef } from 'react'
import {
  focusInitial,
  HILOS_PROFILE_PASSWORD_CHANGE_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosProfilePasswordChangeFlow,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { useSignal } from '../useSignal.js'

export interface HilosProfilePasswordChangeProps {
  flow: HilosProfilePasswordChangeFlow
}

/** Draw the password-change flow owned by the profile page. */
export function HilosProfilePasswordChange({
  flow,
}: HilosProfilePasswordChangeProps) {
  const step = useSignal(flow.step)
  const opening = useSignal(flow.opening)
  const code = useSignal(flow.code)
  const newPassword = useSignal(flow.newPassword)
  const signOutOthers = useSignal(flow.signOutOthers)
  const signedOutOthers = useSignal(flow.signedOutOthers)
  const busy = useSignal(flow.busy)
  const refusal = useSignal(flow.refusal)
  const stepUpRefusal = useSignal(flow.stepUp.refusal)
  const asksBeforeClosing = useSignal(flow.asksBeforeClosing)
  const body = useRef<HTMLDivElement | null>(null)
  const id = useId()
  const title =
    step === 'step-up' || step === 'refused'
      ? HILOS_STEP_UP_COPY.title
      : step === 'done'
        ? COPY.doneTitle
        : COPY.title
  const stepNumber =
    step === 'code' ? 2 : step === 'password' ? 3 : step === 'done' ? 4 : 1
  function fill(text: string): string {
    return text.replace('{destination}', opening?.destination ?? '')
  }
  useEffect(() => {
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  }, [step])
  return (
    <HilosModal
      open={step !== 'closed' && step !== 'opening'}
      onClose={() => flow.close()}
      title={title}
      initialFocus="inner"
      confirmOnClose={asksBeforeClosing}
      actions={({ requestClose }) =>
        step === 'done' ? (
          <>
            <LoadingButton
              className="btn-outline-secondary"
              loading={busy}
              data-id="profile-password-again"
              onClick={() => void flow.again()}
            >
              {COPY.again}
            </LoadingButton>
            <button
              type="button"
              className="btn btn-primary"
              data-id="profile-password-done"
              onClick={requestClose}
            >
              {COPY.done}
            </button>
          </>
        ) : (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-password-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            {step === 'step-up' ? (
              <LoadingButton
                type="submit"
                form={`${id}-step-up-form`}
                className="btn-primary"
                loading={busy}
                data-id="profile-password-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : null}
            {step === 'start' ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                data-id="profile-password-send-code"
                onClick={() => void flow.sendCode()}
              >
                {COPY.sendCode}
              </LoadingButton>
            ) : null}
            {step === 'code' ? (
              <LoadingButton
                type="submit"
                form={`${id}-code-form`}
                className="btn-primary"
                loading={busy}
                disabled={code.trim() === '' || busy}
                data-id="profile-password-confirm-code"
              >
                {COPY.continue}
              </LoadingButton>
            ) : null}
            {step === 'password' ? (
              <LoadingButton
                type="submit"
                form={`${id}-password-form`}
                className="btn-primary"
                loading={busy}
                disabled={newPassword === '' || busy}
                data-id="profile-password-save"
              >
                {COPY.save}
              </LoadingButton>
            ) : null}
          </>
        )
      }
    >
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
        data-id="profile-password-live"
      >
        {step === 'step-up' ? stepUpRefusal : refusal}
      </div>
      <div ref={body} data-id="profile-password-modal">
        <div className="hilos-stack">
          <div className="invisible" aria-hidden="true" inert>
            <ol className="list-unstyled d-flex flex-column gap-1 mb-3 small">
              {COPY.steps.map((label, index) => (
                <li key={label} className="d-flex align-items-center gap-2">
                  <span className="badge rounded-pill text-bg-secondary">
                    {index + 1}
                  </span>
                  <span>{label}</span>
                </li>
              ))}
            </ol>
            <div className="form-label">{COPY.newPassword}</div>
            <div className="form-control">&nbsp;</div>
            <div className="form-text">{COPY.newPasswordHint}</div>
            <div className="form-check mt-3">
              <span className="form-check-label">{COPY.signOutOthers}</span>
            </div>
          </div>
          <div className="align-self-start">
            {step === 'step-up' ? (
              <form
                id={`${id}-step-up-form`}
                data-id="profile-password-step-up"
                onSubmit={(event) => {
                  event.preventDefault()
                  void flow.confirmStepUp()
                }}
              >
                <HilosStepUpStep controller={flow.stepUp} />
              </form>
            ) : step !== 'refused' ? (
              <>
                {step !== 'done' ? (
                  <ol
                    className="list-unstyled d-flex flex-column gap-1 mb-3 small"
                    data-id="profile-password-steps"
                  >
                    {COPY.steps.map((label, index) => (
                      <li
                        key={label}
                        className={`d-flex align-items-center gap-2 ${index + 1 === stepNumber ? 'fw-semibold' : 'text-body-secondary'}`}
                        aria-current={
                          index + 1 === stepNumber ? 'step' : undefined
                        }
                      >
                        <span
                          className={`badge rounded-pill ${index + 1 === stepNumber ? 'text-bg-primary' : 'text-bg-secondary'}`}
                        >
                          {index + 1}
                        </span>
                        <span>{label}</span>
                      </li>
                    ))}
                  </ol>
                ) : null}
                {step === 'start' ? (
                  <p
                    className="small text-body-secondary mb-0 text-break"
                    data-id="profile-password-destination"
                  >
                    {fill(COPY.start)}
                  </p>
                ) : null}
                {step === 'code' ? (
                  <form
                    id={`${id}-code-form`}
                    onSubmit={(event) => {
                      event.preventDefault()
                      void flow.confirmCode()
                    }}
                  >
                    <label htmlFor={`${id}-code`} className="form-label">
                      {COPY.code}
                    </label>
                    <input
                      id={`${id}-code`}
                      className="form-control"
                      autoComplete="one-time-code"
                      inputMode="numeric"
                      data-autofocus
                      data-id="profile-password-code"
                      value={code}
                      aria-describedby={`${id}-code-hint`}
                      onChange={(event) => flow.code.set(event.target.value)}
                    />
                    <div
                      id={`${id}-code-hint`}
                      className="form-text text-break"
                    >
                      {fill(COPY.codeHint)}
                    </div>
                  </form>
                ) : null}
                {step === 'password' ? (
                  <form
                    id={`${id}-password-form`}
                    onSubmit={(event) => {
                      event.preventDefault()
                      void flow.save()
                    }}
                  >
                    <label htmlFor={`${id}-password`} className="form-label">
                      {COPY.newPassword}
                    </label>
                    <input
                      id={`${id}-password`}
                      type="password"
                      className="form-control"
                      autoComplete="new-password"
                      data-autofocus
                      data-id="profile-password-new"
                      value={newPassword}
                      aria-describedby={`${id}-password-hint`}
                      onChange={(event) =>
                        flow.newPassword.set(event.target.value)
                      }
                    />
                    <div id={`${id}-password-hint`} className="form-text">
                      {COPY.newPasswordHint}
                    </div>
                    <div className="form-check mt-3">
                      <input
                        id={`${id}-others`}
                        type="checkbox"
                        className="form-check-input"
                        data-id="profile-password-sign-out-others"
                        checked={signOutOthers}
                        onChange={(event) =>
                          flow.signOutOthers.set(event.target.checked)
                        }
                      />
                      <label
                        htmlFor={`${id}-others`}
                        className="form-check-label"
                      >
                        {COPY.signOutOthers}
                      </label>
                    </div>
                  </form>
                ) : null}
                {step === 'done' ? (
                  <div
                    className="text-center py-2"
                    data-id="profile-password-outcome"
                  >
                    <i
                      className="bi bi-check-circle-fill text-success d-block mb-2 fs-2"
                      aria-hidden="true"
                    ></i>
                    <div className="fw-semibold mb-1">{COPY.doneTitle}</div>
                    <p
                      className="small text-body-secondary mb-0"
                      data-id="profile-password-outcome-sessions"
                    >
                      {signedOutOthers ? COPY.doneSignedOut : COPY.doneKept}
                    </p>
                    <p className="small text-body-secondary mb-0">
                      {COPY.doneResetCodes}
                    </p>
                  </div>
                ) : null}
              </>
            ) : null}
          </div>
        </div>
        <HilosFormError message={refusal} dataId="profile-password-error" />
      </div>
    </HilosModal>
  )
}
