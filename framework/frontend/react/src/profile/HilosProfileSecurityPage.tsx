// HilosProfileSecurityPage — the profile's security page
// (HilosPages.PROFILE_SECURITY, /profile/security, HIL-494): two-step
// verification for the person signed in. The connected authenticator apps, each
// with Remove; the backup codes left of the set, with Show; Add an app; and "If
// you lose access" — the removal wait and the delayed removal itself. Every
// mutation is a modal (the modal-only editing rule), and every one but the first
// connection, the wait and the removal starts with a code from an app or a
// backup code: a stolen live session must not strip or copy the factor.
// The section, its live copy and the actions are the core's; this view owns only
// the markup. The page is drawn from text — the mockup's node is a debt (D-113).
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  createHilosSecondFactorActions,
  createHilosSecondFactorStore,
  focusInitial,
  formatCalendarDate,
} from '@hilos/core'
import type {
  HilosBackupCodeEntry,
  HilosSecondFactorAuthenticator,
  HilosSecondFactorContext,
  HilosSecondFactorEnrollment,
  HilosSecondFactorProof,
} from '@hilos/core'

import { HilosActionError } from '../HilosActionError.js'
import { HilosBackupCodes } from '../HilosBackupCodes.js'
import { HilosModal } from '../HilosModal.js'
import { HilosQrCode } from '../HilosQrCode.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

/** One day in ms — the unit of the removal wait. */
const DAY_MS = 86_400_000

/** Props for {@link HilosProfileSecurityPage}. */
export interface HilosProfileSecurityPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecondFactorContext
}

type EnrollStep = 'name' | 'proof' | 'scan' | 'codes' | 'more'
type CodesStep = 'proof' | 'list' | 'renew' | 'new'

const ENROLL_SUBMIT: Record<EnrollStep, string> = {
  name: 'Next',
  proof: 'Next',
  scan: 'Connect',
  codes: 'Done',
  more: 'Connect another',
}

const CODES_SUBMIT: Record<CodesStep, string> = {
  proof: 'Show',
  list: 'Issue new codes',
  renew: 'Issue',
  new: 'Done',
}

/**
 * Put focus on the field of the step a modal has just moved to, or on the
 * dialog when the step has none: the field that held it is gone, and the modal
 * places focus only when it opens.
 *
 * @param form The modal's form, drawn at the new step.
 */
function focusStep(form: HTMLFormElement | null): void {
  const dialog = form?.closest<HTMLElement>('[role="dialog"]')
  if (dialog) {
    focusInitial(dialog)
  }
}

/** Props for {@link ProofField}. */
interface ProofFieldProps {
  code: string
  backup: boolean
  checkboxId: string
  onCode: (code: string) => void
  onBackup: (backup: boolean) => void
}

/**
 * The code a modal starts with, and whether it is a backup code.
 *
 * @param props The field's value and its setters.
 * @param props.code The code as typed.
 * @param props.backup Whether it is a backup code.
 * @param props.checkboxId The id of the checkbox, unique per modal.
 * @param props.onCode Called with the code as typed.
 * @param props.onBackup Called with the checkbox.
 */
function ProofField({
  code,
  backup,
  checkboxId,
  onCode,
  onBackup,
}: ProofFieldProps) {
  return (
    <>
      <input
        type="text"
        className="form-control mb-2"
        autoComplete="one-time-code"
        aria-label="Code"
        data-id="profile-2fa-proof"
        data-autofocus
        value={code}
        onChange={(event) => onCode(event.target.value)}
      />
      <div className="form-check">
        <input
          id={checkboxId}
          className="form-check-input"
          type="checkbox"
          checked={backup}
          onChange={(event) => onBackup(event.target.checked)}
        />
        <label className="form-check-label small" htmlFor={checkboxId}>
          This is a backup code
        </label>
      </div>
    </>
  )
}

/**
 * The profile's security page.
 *
 * @param props The project's second-factor context.
 */
export function HilosProfileSecurityPage({
  context,
}: HilosProfileSecurityPageProps) {
  const store = useMemo(() => createHilosSecondFactorStore(context), [context])
  const actions = useMemo(
    () => createHilosSecondFactorActions(context),
    [context],
  )
  useEffect(() => {
    store.start()

    return () => store.dispose()
  }, [store])

  const state = useSignal(store.state)
  const apps = state?.authenticators ?? []
  const factorOn = apps.length > 0
  const removalLocked = (state?.required ?? false) && apps.length === 1

  // The proof a modal starts with.
  const [proofCode, setProofCode] = useState('')
  const [proofBackup, setProofBackup] = useState(false)
  const resetProof = (): void => {
    setProofCode('')
    setProofBackup(false)
  }
  const proof = (): HilosSecondFactorProof => ({
    code: proofCode.trim(),
    backupCode: proofBackup,
  })

  // Connect an app.
  const [enrollOpen, setEnrollOpen] = useState(false)
  const [enrollStep, setEnrollStep] = useState<EnrollStep>('name')
  const [enrollLabel, setEnrollLabel] = useState('')
  const [enrollCode, setEnrollCode] = useState('')
  const [enrollment, setEnrollment] =
    useState<HilosSecondFactorEnrollment | null>(null)
  const [issuedCodes, setIssuedCodes] = useState<readonly string[]>([])
  const [issuedSaved, setIssuedSaved] = useState(false)
  const enrollAction = useTrackedAction()
  const enrollForm = useRef<HTMLFormElement>(null)
  useEffect(() => focusStep(enrollForm.current), [enrollStep])

  function openEnroll(): void {
    enrollAction.clearError()
    resetProof()
    setEnrollLabel('')
    setEnrollCode('')
    setEnrollment(null)
    setIssuedCodes([])
    setIssuedSaved(false)
    setEnrollStep('name')
    setEnrollOpen(true)
  }

  async function enrollSubmit(): Promise<void> {
    // Enter submits the form past the disabled button, so the guard is here too.
    if (enrollAction.busy || enrollDisabled) {
      return
    }
    if (enrollStep === 'name' && factorOn) {
      setEnrollStep('proof')

      return
    }
    if (enrollStep === 'name' || enrollStep === 'proof') {
      const handle = actions.enrollStart(factorOn ? proof() : null)
      if (await enrollAction.run(handle)) {
        setEnrollment((await handle.done).reply ?? null)
        setEnrollStep('scan')
      }

      return
    }
    if (enrollStep === 'scan' && enrollment !== null) {
      const handle = actions.enrollConfirm(
        enrollment.authenticatorId,
        enrollCode.trim(),
        enrollLabel.trim(),
      )
      if (await enrollAction.run(handle)) {
        const codes = (await handle.done).reply?.backupCodes
        if (codes !== undefined) {
          setIssuedCodes(codes)
          setEnrollStep('codes')
        } else {
          setEnrollStep('more')
        }
      }

      return
    }
    if (enrollStep === 'codes') {
      setEnrollStep('more')

      return
    }
    openEnroll()
  }

  const enrollDisabled =
    (enrollStep === 'proof' && proofCode.trim() === '') ||
    (enrollStep === 'scan' && enrollCode.trim() === '') ||
    (enrollStep === 'codes' && !issuedSaved)

  // Show the backup codes, and issue a new set.
  const [codesOpen, setCodesOpen] = useState(false)
  const [codesStep, setCodesStep] = useState<CodesStep>('proof')
  const [listedCodes, setListedCodes] = useState<
    readonly HilosBackupCodeEntry[]
  >([])
  const [renewedCodes, setRenewedCodes] = useState<readonly string[]>([])
  const [renewedSaved, setRenewedSaved] = useState(false)
  const codesAction = useTrackedAction()
  const codesForm = useRef<HTMLFormElement>(null)
  useEffect(() => focusStep(codesForm.current), [codesStep])

  function openCodes(): void {
    codesAction.clearError()
    resetProof()
    setListedCodes([])
    setRenewedCodes([])
    setRenewedSaved(false)
    setCodesStep('proof')
    setCodesOpen(true)
  }

  async function codesSubmit(): Promise<void> {
    if (codesAction.busy || codesDisabled) {
      return
    }
    if (codesStep === 'list') {
      // A new set asks for the NEXT code: the one that opened the list is spent.
      resetProof()
      codesAction.clearError()
      setCodesStep('renew')

      return
    }
    if (codesStep === 'new') {
      setCodesOpen(false)

      return
    }
    if (codesStep === 'proof') {
      const handle = actions.showCodes(proof())
      if (await codesAction.run(handle)) {
        setListedCodes((await handle.done).reply?.codes ?? [])
        setCodesStep('list')
      }

      return
    }
    const handle = actions.renewCodes(proof())
    if (await codesAction.run(handle)) {
      setRenewedCodes((await handle.done).reply?.backupCodes ?? [])
      setCodesStep('new')
    }
  }

  const codesDisabled =
    codesStep === 'new'
      ? !renewedSaved
      : codesStep !== 'list' && proofCode.trim() === ''

  // Remove an app.
  const [removeOpen, setRemoveOpen] = useState(false)
  const [removeTarget, setRemoveTarget] =
    useState<HilosSecondFactorAuthenticator | null>(null)
  const removeAction = useTrackedAction()

  function openRemove(app: HilosSecondFactorAuthenticator): void {
    removeAction.clearError()
    resetProof()
    setRemoveTarget(app)
    setRemoveOpen(true)
  }

  const removeDisabled = proofCode.trim() === ''

  async function removeSubmit(): Promise<void> {
    if (removeTarget === null || removeAction.busy || removeDisabled) {
      return
    }
    if (await removeAction.run(actions.remove(removeTarget.id, proof()))) {
      setRemoveOpen(false)
    }
  }

  // The removal wait.
  const [waitOpen, setWaitOpen] = useState(false)
  const [waitDays, setWaitDays] = useState('')
  const waitAction = useTrackedAction()
  const currentWait = state?.resetWait.pendingDays ?? state?.resetWait.days ?? 0
  const waitChosen = Number.parseInt(waitDays, 10)
  // A shorter wait waits out the wait in force first; the modal names that day.
  const waitTakesEffect =
    state !== null &&
    !Number.isNaN(waitChosen) &&
    waitChosen < state.resetWait.days
      ? Date.now() + state.resetWait.days * DAY_MS
      : null

  function openWait(): void {
    waitAction.clearError()
    setWaitDays(String(currentWait))
    setWaitOpen(true)
  }

  const waitDisabled = Number.isNaN(waitChosen) || waitChosen === currentWait

  async function waitSubmit(): Promise<void> {
    if (waitAction.busy || waitDisabled) {
      return
    }
    if (await waitAction.run(actions.setResetWait(waitChosen))) {
      setWaitOpen(false)
    }
  }

  // The delayed removal.
  const [resetOpen, setResetOpen] = useState(false)
  const resetAction = useTrackedAction()
  const resetCancelAction = useTrackedAction()

  async function resetSubmit(): Promise<void> {
    if (resetAction.busy) {
      return
    }
    if (await resetAction.run(actions.requestReset())) {
      setResetOpen(false)
    }
  }

  return (
    <section data-id="profile-security" className="mx-auto py-3">
      <h1 className="h4 mb-4">Security</h1>

      {state === null ? (
        <div className="text-body-secondary" role="status">
          Loading…
        </div>
      ) : (
        <>
          <h2 className="h6 text-uppercase text-body-secondary mb-2">
            Two-step verification
          </h2>
          {state.required ? (
            <p className="small mb-2" data-id="profile-2fa-required">
              Your administrator requires two-step verification.
            </p>
          ) : null}
          <ul className="list-group mb-3" data-id="profile-2fa-apps">
            {apps.map((app) => (
              <li
                key={app.id}
                className="list-group-item d-flex align-items-center gap-3"
                data-id={`profile-2fa-app-${app.id}`}
              >
                <i className="bi bi-phone-vibrate fs-5" aria-hidden="true" />
                <div className="flex-grow-1">
                  <div className="fw-semibold">{app.label}</div>
                  <div className="small text-body-secondary">
                    Connected {formatCalendarDate(app.createdAt)}
                  </div>
                  {removalLocked ? (
                    <div
                      className="small text-body-secondary"
                      data-id="profile-2fa-remove-locked"
                    >
                      Your administrator requires two-step verification, so the
                      last app stays.
                    </div>
                  ) : null}
                </div>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-danger"
                  disabled={removalLocked}
                  data-id={`profile-2fa-remove-${app.id}`}
                  onClick={() => openRemove(app)}
                >
                  Remove
                </button>
              </li>
            ))}
            {factorOn ? (
              <li className="list-group-item d-flex align-items-center gap-3">
                <i className="bi bi-key fs-5" aria-hidden="true" />
                <div className="flex-grow-1">
                  <div className="fw-semibold">Backup codes</div>
                  <div
                    className="small text-body-secondary"
                    data-id="profile-2fa-codes-left"
                  >
                    {state.backupCodesLeft} of {state.backupCodesTotal} left
                  </div>
                </div>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  data-id="profile-2fa-codes-show"
                  onClick={openCodes}
                >
                  Show
                </button>
              </li>
            ) : (
              <li
                className="list-group-item text-body-secondary small"
                data-id="profile-2fa-off"
              >
                Two-step verification is off. Connect an authenticator app to
                turn it on.
              </li>
            )}
          </ul>
          <button
            type="button"
            className="btn btn-sm btn-outline-primary mb-4"
            data-id="profile-2fa-add"
            onClick={openEnroll}
          >
            <i className="bi bi-plus-lg me-1" aria-hidden="true" />
            Add an authenticator app
          </button>

          <h2 className="h6 text-uppercase text-body-secondary mb-2">
            If you lose access
          </h2>
          <ul className="list-group mb-3">
            <li className="list-group-item d-flex align-items-center gap-3">
              <i className="bi bi-hourglass-split fs-5" aria-hidden="true" />
              <div className="flex-grow-1">
                <div className="fw-semibold">Wait before removal</div>
                <div
                  className="small text-body-secondary"
                  data-id="profile-2fa-wait"
                >
                  {state.resetWait.days} days
                  {state.resetWait.pendingDays !== null
                    ? ` · ${state.resetWait.pendingDays} days from ${formatCalendarDate(state.resetWait.pendingFrom ?? Date.now())}`
                    : null}
                </div>
              </div>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="profile-2fa-wait-edit"
                onClick={openWait}
              >
                Change
              </button>
            </li>
          </ul>
          {state.reset !== null ? (
            <div
              className="alert alert-warning d-flex align-items-center gap-3"
              data-id="profile-2fa-reset-pending"
            >
              <span className="flex-grow-1">
                Two-step verification will be removed on{' '}
                <strong>{formatCalendarDate(state.reset.effectiveAt)}</strong>.
              </span>
              <LoadingButton
                className="btn-sm btn-outline-secondary"
                loading={resetCancelAction.loading}
                disabled={resetCancelAction.busy}
                data-id="profile-2fa-reset-cancel"
                onClick={() =>
                  void resetCancelAction.run(actions.cancelReset())
                }
              >
                Cancel
              </LoadingButton>
            </div>
          ) : factorOn ? (
            <button
              type="button"
              className="btn btn-sm btn-outline-danger mb-3"
              data-id="profile-2fa-reset-request"
              onClick={() => {
                resetAction.clearError()
                setResetOpen(true)
              }}
            >
              Request removal
            </button>
          ) : null}
          <HilosActionError action={resetCancelAction} />
          <p className="small text-body-secondary mb-0">
            While a removal waits, every channel you have is told about it, and
            any of those messages stops it. A longer wait makes the account
            harder to take — and makes you wait longer if your phone is really
            gone. A shorter wait takes effect only after the wait in force.
          </p>
        </>
      )}

      <HilosModal
        open={enrollOpen}
        title="Add an authenticator app"
        confirmOnClose={enrollStep === 'codes' && !issuedSaved}
        confirmTitle="Close before saving the codes?"
        confirmMessage="You have not marked these codes as saved. You can still show them later with a code from your app."
        confirmOkText="Close"
        confirmCancelText="Back to the codes"
        onClose={() => setEnrollOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={enrollAction.busy}
              onClick={requestClose}
            >
              {enrollStep === 'more' ? 'Not now' : 'Cancel'}
            </button>
            <LoadingButton
              className="btn-primary"
              loading={enrollAction.loading}
              disabled={enrollAction.busy || enrollDisabled}
              data-id="profile-2fa-enroll-submit"
              onClick={() => void enrollSubmit()}
            >
              {ENROLL_SUBMIT[enrollStep]}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={enrollAction} />
        <form
          ref={enrollForm}
          data-id="profile-2fa-enroll"
          onSubmit={(event) => {
            event.preventDefault()
            void enrollSubmit()
          }}
        >
          {enrollStep === 'name' ? (
            <>
              <label className="form-label" htmlFor="profile-2fa-enroll-label">
                Name of this app
              </label>
              <input
                id="profile-2fa-enroll-label"
                type="text"
                className="form-control"
                maxLength={64}
                placeholder="Authenticator app"
                data-id="profile-2fa-enroll-label"
                data-autofocus
                value={enrollLabel}
                onChange={(event) => setEnrollLabel(event.target.value)}
              />
            </>
          ) : enrollStep === 'proof' ? (
            <>
              <p className="small">
                Enter a code from an app you already connected, or a backup
                code.
              </p>
              <ProofField
                code={proofCode}
                backup={proofBackup}
                checkboxId="profile-2fa-enroll-backup"
                onCode={setProofCode}
                onBackup={setProofBackup}
              />
            </>
          ) : enrollStep === 'scan' && enrollment !== null ? (
            <>
              <p className="small">
                Scan this code with your authenticator app, then enter the code
                the app shows.
              </p>
              <HilosQrCode
                text={enrollment.otpauthUri}
                label="QR code for your authenticator app"
                className="mb-2"
              />
              <p
                className="font-monospace small text-center text-break"
                data-id="profile-2fa-enroll-secret"
              >
                {enrollment.secret}
              </p>
              <input
                type="text"
                inputMode="numeric"
                className="form-control"
                autoComplete="one-time-code"
                aria-label="Code"
                data-id="profile-2fa-enroll-code"
                data-autofocus
                value={enrollCode}
                onChange={(event) => setEnrollCode(event.target.value)}
              />
            </>
          ) : enrollStep === 'codes' ? (
            <>
              <p className="small">
                Keep these codes somewhere safe. Each one signs you in once if
                you lose your authenticator app.
              </p>
              <HilosBackupCodes
                codes={issuedCodes}
                saved={issuedSaved}
                onSavedChange={setIssuedSaved}
              />
            </>
          ) : (
            <p className="small mb-0" data-id="profile-2fa-enroll-more">
              The app is connected. A second app is the quickest way back in if
              you lose this one — connect another now?
            </p>
          )}
        </form>
      </HilosModal>

      <HilosModal
        open={codesOpen}
        title="Backup codes"
        confirmOnClose={codesStep === 'new' && !renewedSaved}
        confirmTitle="Close before saving the codes?"
        confirmMessage="You have not marked the new codes as saved, and the old ones no longer work. You can still show them later with a code from your app."
        confirmOkText="Close"
        confirmCancelText="Back to the codes"
        initialFocus="inner"
        onClose={() => setCodesOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={codesAction.busy}
              onClick={requestClose}
            >
              Close
            </button>
            <LoadingButton
              className="btn-primary"
              loading={codesAction.loading}
              disabled={codesAction.busy || codesDisabled}
              data-id="profile-2fa-codes-submit"
              onClick={() => void codesSubmit()}
            >
              {CODES_SUBMIT[codesStep]}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={codesAction} />
        <form
          ref={codesForm}
          data-id="profile-2fa-codes"
          onSubmit={(event) => {
            event.preventDefault()
            void codesSubmit()
          }}
        >
          {codesStep === 'proof' || codesStep === 'renew' ? (
            <>
              <p className="small">
                {codesStep === 'renew'
                  ? 'Enter the next code from your app, or a backup code. The old codes stop working.'
                  : 'Enter a code from your app, or a backup code.'}
              </p>
              <ProofField
                code={proofCode}
                backup={proofBackup}
                checkboxId="profile-2fa-codes-backup"
                onCode={setProofCode}
                onBackup={setProofBackup}
              />
            </>
          ) : codesStep === 'list' ? (
            <ul
              className="list-unstyled row row-cols-2 g-2 font-monospace mb-0"
              data-id="profile-2fa-codes-list"
            >
              {listedCodes.map((entry) => (
                <li key={entry.code} className="col text-center">
                  <span
                    className={`d-block border rounded py-1${entry.used ? ' text-decoration-line-through text-body-secondary' : ''}`}
                  >
                    {entry.code}
                    {entry.used ? (
                      <span className="visually-hidden"> (used)</span>
                    ) : null}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <HilosBackupCodes
              codes={renewedCodes}
              saved={renewedSaved}
              onSavedChange={setRenewedSaved}
            />
          )}
        </form>
      </HilosModal>

      <HilosModal
        open={removeOpen}
        title={removeTarget ? `Remove ${removeTarget.label}` : 'Remove app'}
        initialFocus="inner"
        onClose={() => setRemoveOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={removeAction.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={removeAction.loading}
              disabled={removeAction.busy || removeDisabled}
              data-id="profile-2fa-remove-submit"
              onClick={() => void removeSubmit()}
            >
              Remove
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={removeAction} />
        <form
          data-id="profile-2fa-remove"
          onSubmit={(event) => {
            event.preventDefault()
            void removeSubmit()
          }}
        >
          {apps.length === 1 ? (
            <p className="small" data-id="profile-2fa-remove-last">
              This is your last app: two-step verification turns off, your
              backup codes and trusted devices stop working, and a removal that
              waits is dropped.
            </p>
          ) : null}
          <p className="small">Enter a code from your app, or a backup code.</p>
          <ProofField
            code={proofCode}
            backup={proofBackup}
            checkboxId="profile-2fa-remove-backup"
            onCode={setProofCode}
            onBackup={setProofBackup}
          />
        </form>
      </HilosModal>

      <HilosModal
        open={waitOpen}
        title="Wait before removal"
        onClose={() => setWaitOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={waitAction.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={waitAction.loading}
              disabled={waitAction.busy || waitDisabled}
              data-id="profile-2fa-wait-save"
              onClick={() => void waitSubmit()}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={waitAction} />
        <form
          data-id="profile-2fa-wait-form"
          onSubmit={(event) => {
            event.preventDefault()
            void waitSubmit()
          }}
        >
          <label className="form-label" htmlFor="profile-2fa-wait-days">
            Days
          </label>
          <input
            id="profile-2fa-wait-days"
            type="number"
            inputMode="numeric"
            className="form-control"
            min={state?.resetWait.minDays}
            max={state?.resetWait.maxDays}
            data-id="profile-2fa-wait-days"
            data-autofocus
            value={waitDays}
            onChange={(event) => setWaitDays(event.target.value)}
          />
          <p className="form-text mb-0">
            From {state?.resetWait.minDays} to {state?.resetWait.maxDays} days.
            {waitTakesEffect !== null
              ? ` A shorter wait takes effect on ${formatCalendarDate(waitTakesEffect)}.`
              : null}
          </p>
        </form>
      </HilosModal>

      <HilosModal
        open={resetOpen}
        title="Request removal"
        initialFocus="dialog"
        onClose={() => setResetOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={resetAction.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={resetAction.loading}
              disabled={resetAction.busy}
              data-id="profile-2fa-reset-submit"
              onClick={() => void resetSubmit()}
            >
              Request removal
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={resetAction} />
        <p className="small" data-id="profile-2fa-reset-date">
          Two-step verification will be removed on{' '}
          <strong>
            {formatCalendarDate(
              Date.now() + (state?.resetWait.days ?? 0) * DAY_MS,
            )}
          </strong>
          .
        </p>
        <p className="small mb-0">
          We tell you at once and then every day, on every channel you have —
          email, text message, push and the bell in the app — and each message
          lets you cancel.
        </p>
      </HilosModal>
    </section>
  )
}
