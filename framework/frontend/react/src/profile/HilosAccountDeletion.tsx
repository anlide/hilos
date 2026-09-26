// HilosAccountDeletion — the danger zone at the bottom of a profile page and its
// one window (HIL-302). Without a scheduled deletion the zone offers "Delete my
// account…", and the window walks the operation's confirmation (when asked),
// step 1 "what will happen" and step 2 "the code"; with one, the zone is a
// warning with the date and the days left, and the window is "Deletion in
// progress" with a plain "Keep my account". Every open tab follows the person's
// state. The state, the actions and the window's steps are the core's
// (createHilosAccountDeletionStore / createHilosAccountDeletionFlow); this view
// owns only the markup. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ACCOUNT_DELETION_TICK_MS,
  accountDeletionDaysLeft,
  createHilosAccountDeletionFlow,
  createHilosAccountDeletionStore,
  focusInitial,
  formatAccountDeletionDays,
  formatCalendarDate,
  HILOS_ACCOUNT_DELETION_COPY as COPY,
  HILOS_STEP_UP_COPY,
} from '@hilos/core'
import type { HilosSecondFactorContext } from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { useSignal } from '../useSignal.js'

/** Props for {@link HilosAccountDeletion}. */
export interface HilosAccountDeletionProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecondFactorContext
}

/**
 * The danger zone of a profile page and its window.
 *
 * @param props The project's context.
 */
export function HilosAccountDeletion({ context }: HilosAccountDeletionProps) {
  const store = useMemo(
    () => createHilosAccountDeletionStore(context),
    [context],
  )
  const flow = useMemo(
    () => createHilosAccountDeletionFlow(context, store),
    [context, store],
  )
  const state = useSignal(store.state)
  const step = useSignal(flow.step)
  const opening = useSignal(flow.opening)
  const code = useSignal(flow.code)
  const busy = useSignal(flow.busy)
  const refusal = useSignal(flow.refusal)
  const stepUpOpening = useSignal(flow.stepUp.opening)
  const stepUpRefusal = useSignal(flow.stepUp.refusal)
  const [now, setNow] = useState(() => Date.now())
  const body = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    store.start()
    flow.follow()
    const tick = setInterval(() => setNow(Date.now()), ACCOUNT_DELETION_TICK_MS)

    return () => {
      clearInterval(tick)
      flow.dispose()
      store.dispose()
    }
  }, [store, flow])

  // The step the window moves to takes the focus - the code field on step 2 -
  // since the window stays and only its content changes.
  useEffect(() => {
    const dialog = body.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  }, [step])

  const deletion = state?.deletion ?? null
  const open = step !== 'closed' && step !== 'opening'
  const stepNumber = step === 'code' ? 2 : 1
  const daysLeft =
    deletion === null
      ? ''
      : formatAccountDeletionDays(
          accountDeletionDaysLeft(deletion.effectiveAt, now),
        )

  function fill(template: string): string {
    return template
      .replace(
        '{graceDays}',
        formatAccountDeletionDays(opening?.graceDays ?? 0),
      )
      .replace('{destination}', opening?.destination ?? '')
      .replace('{days}', daysLeft)
      .replace(
        '{date}',
        deletion === null ? '' : formatCalendarDate(deletion.effectiveAt),
      )
      .replace(
        '{requestedDate}',
        deletion === null ? '' : formatCalendarDate(deletion.requestedAt),
      )
  }

  return (
    <section className="mt-5" data-id="account-deletion">
      {deletion !== null ? (
        <div
          className="alert alert-warning d-flex flex-wrap align-items-center gap-3"
          data-id="account-deletion-scheduled"
        >
          <div className="flex-grow-1">
            <div className="fw-semibold small mb-1">
              <i className="bi bi-hourglass-split me-1" aria-hidden="true"></i>
              {COPY.scheduledTitle}
            </div>
            <p className="small mb-0">{fill(COPY.scheduledText)}</p>
          </div>
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            data-id="account-deletion-manage"
            onClick={() => void flow.open()}
          >
            {COPY.scheduledButton}
          </button>
        </div>
      ) : (
        <div className="border border-danger-subtle rounded bg-danger-subtle p-3">
          <div className="fw-semibold small mb-1">
            <i
              className="bi bi-exclamation-octagon me-1"
              aria-hidden="true"
            ></i>
            {COPY.zoneTitle}
          </div>
          <p className="small text-body-secondary mb-3">{COPY.zoneText}</p>
          <LoadingButton
            className="btn-sm btn-outline-danger"
            loading={step === 'opening'}
            data-id="account-deletion-open"
            onClick={() => void flow.open()}
          >
            {COPY.zoneButton}
          </LoadingButton>
        </div>
      )}

      <HilosModal
        open={open}
        title={step === 'in-progress' ? COPY.progressTitle : COPY.title}
        initialFocus="inner"
        onClose={() => flow.close()}
        actions={({ requestClose }) =>
          step === 'in-progress' ? (
            <>
              <button
                type="button"
                className="btn btn-outline-secondary"
                data-id="account-deletion-close"
                onClick={requestClose}
              >
                {COPY.close}
              </button>
              <LoadingButton
                className="btn-primary"
                loading={busy}
                data-id="account-deletion-keep"
                onClick={() => void flow.cancel()}
              >
                {COPY.keep}
              </LoadingButton>
            </>
          ) : (
            <>
              <button
                type="button"
                className="btn btn-outline-secondary"
                data-id="account-deletion-cancel"
                onClick={requestClose}
              >
                {COPY.cancel}
              </button>
              {step === 'step-up' && stepUpOpening !== null ? (
                <LoadingButton
                  className="btn-primary"
                  loading={busy}
                  data-id="account-deletion-confirm"
                  onClick={() => void flow.confirmStepUp()}
                >
                  {HILOS_STEP_UP_COPY.confirm}
                </LoadingButton>
              ) : step === 'explain' ? (
                <LoadingButton
                  className="btn-danger"
                  loading={busy}
                  data-autofocus
                  data-id="account-deletion-continue"
                  onClick={() => void flow.next()}
                >
                  {opening?.channel == null ? COPY.start : COPY.continue}
                </LoadingButton>
              ) : step === 'code' ? (
                <LoadingButton
                  className="btn-danger"
                  loading={busy}
                  data-id="account-deletion-start"
                  onClick={() => void flow.start()}
                >
                  {COPY.start}
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
          data-id="account-deletion-live"
        >
          {step === 'step-up' ? stepUpRefusal : refusal}
        </div>
        <div ref={body} data-id="account-deletion-modal">
          {step === 'step-up' ? (
            <HilosStepUpStep controller={flow.stepUp} />
          ) : null}
          {step === 'in-progress' && deletion !== null ? (
            <div className="text-center py-2">
              <i
                className="bi bi-hourglass-split text-danger d-block mb-2 fs-2"
                aria-hidden="true"
              ></i>
              <div className="fw-semibold mb-1">{fill(COPY.progressLead)}</div>
              <p className="small text-body-secondary mb-0">
                {fill(COPY.progressText)}
              </p>
            </div>
          ) : null}
          {step === 'explain' || step === 'code' ? (
            <>
              <ol
                className="list-unstyled d-flex flex-column gap-1 mb-3 small"
                data-id="account-deletion-steps"
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
              {step === 'explain' ? (
                <>
                  <div className="alert alert-danger small py-2 mb-3">
                    <ul className="mb-0 ps-3">
                      <li>{fill(COPY.explainErase)}</li>
                      <li>{COPY.explainProviders}</li>
                      <li>{COPY.explainSubscriptions}</li>
                    </ul>
                  </div>
                  <p className="small text-body-secondary mb-0">
                    {COPY.explainChangeMind}
                  </p>
                </>
              ) : (
                <form
                  onSubmit={(event) => {
                    event.preventDefault()
                    void flow.start()
                  }}
                >
                  <p className="small text-body-secondary mb-3">
                    {fill(COPY.codeSent)}
                  </p>
                  <label
                    className="form-label"
                    htmlFor="hilos-account-deletion-code"
                  >
                    {COPY.codeLabel}
                  </label>
                  <input
                    id="hilos-account-deletion-code"
                    className="form-control"
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    data-id="account-deletion-code"
                    data-autofocus
                    value={code}
                    onChange={(event) => flow.code.set(event.target.value)}
                  />
                </form>
              )}
            </>
          ) : null}
          {step !== 'step-up' ? (
            <HilosFormError message={refusal} dataId="account-deletion-error" />
          ) : null}
        </div>
      </HilosModal>
    </section>
  )
}
