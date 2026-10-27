import { useEffect, useRef } from 'react'
import {
  focusInitial,
  HILOS_PROFILE_RENAME_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosProfileRenameFlow,
} from '@hilos/core/base'
import { ConflictActions } from '../ConflictActions.js'
import { ConflictHeader } from '../ConflictHeader.js'
import { HilosEditNotice } from '../HilosEditNotice.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { useSignal } from '../useSignal.js'

export interface HilosProfileRenameProps {
  flow: HilosProfileRenameFlow
}

/** Draw the profile root's name window over the project's rename (HIL-1169). */
export function HilosProfileRename({ flow }: HilosProfileRenameProps) {
  const step = useSignal(flow.step)
  const draft = useSignal(flow.draft)
  const edit = useSignal(flow.edit)
  const notice = useSignal(flow.notice)
  const pending = useSignal(flow.pending)
  const checking = useSignal(flow.checking)
  const canSave = useSignal(flow.canSave)
  const refusal = useSignal(flow.refusal)
  const asksBeforeClosing = useSignal(flow.asksBeforeClosing)
  const stepUpOpening = useSignal(flow.stepUp.opening)
  const stepUpBusy = useSignal(flow.stepUp.busy)
  const stepUpRefusal = useSignal(flow.stepUp.refusal)
  const body = useRef<HTMLDivElement | null>(null)
  const previous = useRef(step)
  const saveLabel = edit.gone ? COPY.deleted : COPY.save
  const bounds = COPY.bounds
    .replace('{min}', String(flow.minLength))
    .replace('{max}', String(flow.maxLength))

  // Focus the field when confirmation arrives or gives way to the name form.
  useEffect(() => {
    const from = previous.current
    previous.current = step
    const confirmationReady =
      step === 'step-up' && stepUpOpening !== null && !stepUpBusy
    if (!confirmationReady && (from !== 'step-up' || step !== 'form')) return
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  }, [step, stepUpOpening, stepUpBusy])

  return (
    <HilosModal
      open={step !== 'closed'}
      onClose={() => flow.close()}
      confirmOnClose={asksBeforeClosing}
      busy={stepUpBusy}
      initialFocus="inner"
      aria-label={step === 'form' ? COPY.title : HILOS_STEP_UP_COPY.title}
      header={
        step !== 'form' ? (
          <h2 className="modal-title h5 mb-0">{HILOS_STEP_UP_COPY.title}</h2>
        ) : (
          <ConflictHeader title={COPY.title} conflict={edit.conflict} />
        )
      }
      actions={({ requestClose }) =>
        step !== 'form' ? (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-name-step-up-cancel"
              disabled={stepUpBusy}
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            {stepUpOpening !== null ? (
              <LoadingButton
                type="submit"
                form="hilos-profile-rename-step-up"
                className="btn-primary"
                loading={stepUpBusy}
                data-id="profile-name-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : null}
          </>
        ) : (
          <ConflictActions
            conflict={edit.conflict}
            disableSave={!canSave}
            saveLabel={saveLabel}
            onSave={() => flow.save()}
            onAcceptMine={() => flow.keepMine()}
            onAcceptTheirs={() => flow.takeTheirs()}
            cancelButton={
              <button
                type="button"
                className="btn btn-outline-secondary"
                disabled={stepUpBusy}
                data-id="profile-rename-cancel"
                onClick={requestClose}
              >
                {COPY.cancel}
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={pending}
                disabled={disabled}
                data-id="profile-rename-save"
                onClick={onSave}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        )
      }
    >
      {/* This dialog's own voice: the page region behind it is under the
          backdrop, and the dialog is aria-modal, so from inside here that
          region is not there to be read. */}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-rename-live-assertive"
      >
        {step === 'form' ? refusal : stepUpRefusal}
      </div>
      <div ref={body}>
        {step !== 'form' ? (
          <form
            id="hilos-profile-rename-step-up"
            data-id="profile-name-step-up"
            onSubmit={(event) => {
              event.preventDefault()
              void flow.confirmStepUp()
            }}
          >
            <fieldset className="border-0 m-0 p-0" disabled={stepUpBusy}>
              <HilosStepUpStep controller={flow.stepUp} />
            </fieldset>
          </form>
        ) : (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              flow.save()
            }}
          >
            <label className="form-label" htmlFor="profile-name-field">
              {COPY.label}
            </label>
            <input
              id="profile-name-field"
              type="text"
              className="form-control"
              data-autofocus
              data-id="profile-name-input"
              minLength={flow.minLength}
              maxLength={flow.maxLength}
              value={draft}
              onChange={(event) => flow.draft.set(event.target.value)}
            />
            <div className="form-text">{bounds}</div>
            <div className="hilos-stack mb-1">
              <div
                className="small text-body-secondary d-flex align-items-center gap-2 invisible"
                aria-hidden="true"
              >
                <span
                  className="spinner-border spinner-border-sm"
                  aria-hidden="true"
                />
                {COPY.checking}
              </div>
              <div
                className="small text-body-secondary d-flex align-items-center gap-2"
                role="status"
                aria-live="polite"
              >
                {checking ? (
                  <>
                    <span
                      className="spinner-border spinner-border-sm"
                      aria-hidden="true"
                    />
                    {COPY.checking}
                  </>
                ) : null}
              </div>
            </div>
            <HilosEditNotice
              kind={edit.notice?.kind ?? null}
              text={notice}
              dataId="profile-edit-notice"
            />
            <HilosFormError message={refusal} dataId="profile-rename-error" />
          </form>
        )}
      </div>
    </HilosModal>
  )
}
