import { useEffect, useRef } from 'react'
import {
  focusInitial,
  HILOS_PROFILE_RENAME_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosProfileRenameFlow,
} from '@hilos/core'
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
  const valid = useSignal(flow.valid)
  const busy = useSignal(flow.busy)
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

  // The confirmation's body is replaced by the form: focus its field.
  useEffect(() => {
    const from = previous.current
    previous.current = step
    if (from !== 'step-up' || step !== 'form') return
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  }, [step])

  return (
    <HilosModal
      open={step !== 'closed'}
      onClose={() => flow.close()}
      confirmOnClose={asksBeforeClosing}
      initialFocus="inner"
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
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              disabled={busy}
              data-id="profile-rename-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            <ConflictActions
              conflict={edit.conflict}
              disableSave={!valid || !edit.dirty || busy || edit.gone}
              saveLabel={saveLabel}
              onSave={() => flow.save()}
              onAcceptMine={() => flow.keepMine()}
              onAcceptTheirs={() => flow.takeTheirs()}
              saveButton={({ disabled, onSave }) => (
                <LoadingButton
                  className="btn-primary"
                  loading={busy}
                  disabled={disabled}
                  data-id="profile-rename-save"
                  onClick={onSave}
                >
                  {saveLabel}
                </LoadingButton>
              )}
            />
          </>
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
            <HilosStepUpStep controller={flow.stepUp} />
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
