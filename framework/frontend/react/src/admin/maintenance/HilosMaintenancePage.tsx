// HilosMaintenancePage — the framework maintenance section's Overview inside the
// admin shell (HIL-1119): the live protected-mode state with manual entry, exit
// and code issuance, each waiting for its own tracked answer independently of the
// live state frame, and the operation card with the restore facts. In a restore's
// verification window the browser that started it gets the ladder — mint a code,
// open the system, close the window again — and every other browser the line it
// reads instead. The watchdog's current server alarm holds its reserved row above
// the state, and its effective thresholds follow the state and the operator
// actions read-only in every position. React counterpart of the Vue page
// (HIL-1123, HIL-1365, HIL-1679, HIL-1680). The verifier circle has its own page
// (HilosMaintenanceCirclePage). The state, actions and words belong to the core
// headless. A project supplies only HilosMaintenanceContext. Bootstrap classes only.
import { Fragment, useEffect, useMemo, useRef, useState } from 'react'
import {
  ActionError,
  formatMoment,
  HilosPages,
  hilosTimeZone,
} from '@hilos/core/base'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
  HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
  HILOS_MAINTENANCE_RESTORE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  hilosMaintenanceCard,
  hilosMaintenanceElapsed,
  hilosMaintenanceOtherBrowserLine,
  hilosMaintenancePosition,
  hilosMaintenanceRestoreLadder,
  hilosMaintenanceWatchdogAlert,
  hilosMaintenanceWatchdogThresholds,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { HilosMaintenanceWatchdogAlert } from './HilosMaintenanceWatchdogAlert.js'

/** Props for the framework maintenance section. */
export interface HilosMaintenancePageProps {
  /** The project context: scope stores, the connection and the action lifecycle. */
  context: HilosMaintenanceContext
}

/**
 * How a manual action reports a failure: a refusal in the server's own words,
 * anything else as the action's note that its outcome is unconfirmed.
 *
 * @param unconfirmedNote The action's own note for an outcome nobody confirmed.
 */
function describeManualError(
  unconfirmedNote: string,
): (error: unknown) => string {
  return (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : unconfirmedNote
}

/**
 * A published moment in the reader's zone; an absent or invalid one is a dash.
 *
 * @param moment ISO-8601 UTC moment from the frame; null or undefined when absent.
 */
function readerMoment(moment: string | null | undefined): string {
  return formatMoment(moment ?? '', { kind: 'dateTime' }) ?? '—'
}

/**
 * The protected-mode block with its manual and restore dialogs, which close
 * only on the server's answer.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenancePage({ context }: HilosMaintenancePageProps) {
  useSignal(hilosTimeZone)
  const stateHandle = useMemo(
    () => createHilosMaintenanceState(context),
    [context],
  )
  const maintenanceState = useSignal(stateHandle.state)
  const [nowMs, setNowMs] = useState(() => Date.now())
  const position = hilosMaintenancePosition(maintenanceState)
  const stateCard = hilosMaintenanceCard(maintenanceState, nowMs)
  const startedAt = readerMoment(stateCard?.startedAt)
  const activatedAt = readerMoment(stateCard?.activatedAt)
  const progressAt = readerMoment(stateCard?.progressAt)
  // A missing or unreadable progress mark is not "just now": it gets no age at all.
  const progressElapsed =
    stateCard?.progressAt && progressAt !== '—'
      ? hilosMaintenanceElapsed(stateCard.progressAt, nowMs)
      : null
  const restoreLadder = hilosMaintenanceRestoreLadder(maintenanceState)
  // The server's verdict and its published configuration, never a judge of our
  // own; the reader-zone subscription above redraws the alarm's moments.
  const watchdogAlert = hilosMaintenanceWatchdogAlert(maintenanceState)
  const watchdogThresholds =
    hilosMaintenanceWatchdogThresholds(maintenanceState)

  useEffect(() => {
    stateHandle.start()

    return () => stateHandle.dispose()
  }, [stateHandle])

  useEffect(() => {
    const clockTick = setInterval(() => setNowMs(Date.now()), 30_000)

    return () => clearInterval(clockTick)
  }, [])

  const actions = useMemo(
    () => createHilosMaintenanceActions(context),
    [context],
  )

  // A state push may replace the open banner while this action still awaits its ack.
  const [enableOpen, setEnableOpen] = useState(false)
  const enableAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
    ),
  })

  function openEnable(): void {
    if (enableAction.busy || position !== 'open') {
      return
    }
    enableAction.clearError()
    setEnableOpen(true)
  }

  function closeEnable(): void {
    // The controlled model guards the header's close button as well as Cancel.
    if (!enableAction.busy) {
      setEnableOpen(false)
    }
  }

  async function submitEnable(): Promise<void> {
    if (enableAction.busy || position !== 'open') {
      return
    }
    if (await enableAction.run(actions.sendMaintenanceEnable())) {
      setEnableOpen(false)
    }
  }

  // The dialog survives a state push; the shared lift may reload before its ack arrives.
  const [disableOpen, setDisableOpen] = useState(false)
  const disableAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote,
    ),
  })

  function openDisable(): void {
    if (disableAction.busy || position !== 'manual') {
      return
    }
    disableAction.clearError()
    setDisableOpen(true)
  }

  function closeDisable(): void {
    if (!disableAction.busy) {
      setDisableOpen(false)
    }
  }

  async function submitDisable(): Promise<void> {
    if (disableAction.busy || position !== 'manual') {
      return
    }
    if (await disableAction.run(actions.sendMaintenanceDisable())) {
      setDisableOpen(false)
    }
  }

  // The clear code belongs only to this dialog's result and is forgotten on every
  // exit. An answer is read after an await, where this render's values are stale:
  // whether the dialog is still open and whether the view has left live in refs.
  const [passOpen, setPassOpen] = useState(false)
  const [pass, setPass] = useState<string | null>(null)
  const [passStep, setPassStep] = useState<'confirm' | 'result'>('confirm')
  const passValue = useRef<HTMLDivElement>(null)
  const passOpenNow = useRef(false)
  const passDisposed = useRef(false)
  const passAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    ),
  })

  function openPass(): void {
    if (passDisposed.current || passAction.busy || position !== 'manual') {
      return
    }
    setPass(null)
    setPassStep('confirm')
    passAction.clearError()
    passOpenNow.current = true
    setPassOpen(true)
  }

  function closePass(): void {
    if (passAction.busy) {
      return
    }
    passOpenNow.current = false
    setPassOpen(false)
    setPass(null)
    setPassStep('confirm')
  }

  async function submitPass(): Promise<void> {
    if (
      passDisposed.current ||
      !passOpenNow.current ||
      passAction.busy ||
      passStep !== 'confirm' ||
      position !== 'manual'
    ) {
      return
    }
    const handle = actions.sendMaintenancePass()
    if (await passAction.run(handle)) {
      const result = await handle.done
      if (passDisposed.current || !passOpenNow.current) {
        return
      }
      setPass(result.reply!.pass)
      setPassStep('result')
    }
  }

  // The value takes focus once the result is on screen.
  useEffect(() => {
    if (passStep === 'result') {
      passValue.current?.focus()
    }
  }, [passStep])

  // The clear restore code belongs only to this dialog's result and is forgotten
  // on every exit, read after an await through refs as the manual code is.
  const [restorePassOpen, setRestorePassOpen] = useState(false)
  const [restorePass, setRestorePass] = useState<string | null>(null)
  const [restorePassStep, setRestorePassStep] = useState<'confirm' | 'result'>(
    'confirm',
  )
  const restorePassValue = useRef<HTMLDivElement>(null)
  const restorePassOpenNow = useRef(false)
  const restorePassDisposed = useRef(false)
  const restorePassAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote,
    ),
  })

  function openRestorePass(): void {
    if (
      restorePassDisposed.current ||
      restorePassAction.busy ||
      restoreLadder !== 'initiator'
    ) {
      return
    }
    setRestorePass(null)
    setRestorePassStep('confirm')
    restorePassAction.clearError()
    restorePassOpenNow.current = true
    setRestorePassOpen(true)
  }

  function closeRestorePass(): void {
    if (restorePassAction.busy) {
      return
    }
    restorePassOpenNow.current = false
    setRestorePassOpen(false)
    setRestorePass(null)
    setRestorePassStep('confirm')
  }

  async function submitRestorePass(): Promise<void> {
    if (
      restorePassDisposed.current ||
      !restorePassOpenNow.current ||
      restorePassAction.busy ||
      restorePassStep !== 'confirm' ||
      restoreLadder !== 'initiator'
    ) {
      return
    }
    const handle = actions.sendMaintenanceRestorePass()
    if (await restorePassAction.run(handle)) {
      const result = await handle.done
      if (restorePassDisposed.current || !restorePassOpenNow.current) {
        return
      }
      setRestorePass(result.reply!.pass)
      setRestorePassStep('result')
    }
  }

  // The restore code takes focus once its result is on screen.
  useEffect(() => {
    if (restorePassStep === 'result') {
      restorePassValue.current?.focus()
    }
  }, [restorePassStep])

  // Opening the system lifts the freeze for everyone; the shared lift may reload
  // this page before the ack arrives, and that reload is the answer.
  const [restoreOpenOpen, setRestoreOpenOpen] = useState(false)
  const restoreOpenAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_RESTORE_OPEN_COPY.unconfirmedNote,
    ),
  })

  function openRestoreOpen(): void {
    if (restoreOpenAction.busy || restoreLadder !== 'initiator') {
      return
    }
    restoreOpenAction.clearError()
    setRestoreOpenOpen(true)
  }

  function closeRestoreOpen(): void {
    if (!restoreOpenAction.busy) {
      setRestoreOpenOpen(false)
    }
  }

  async function submitRestoreOpen(): Promise<void> {
    if (restoreOpenAction.busy || restoreLadder !== 'initiator') {
      return
    }
    if (await restoreOpenAction.run(actions.sendMaintenanceRestoreOpen())) {
      setRestoreOpenOpen(false)
    }
  }

  const [restoreCloseOpen, setRestoreCloseOpen] = useState(false)
  const restoreCloseAction = useTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.unconfirmedNote,
    ),
  })

  function openRestoreClose(): void {
    if (restoreCloseAction.busy || restoreLadder !== 'initiator') {
      return
    }
    restoreCloseAction.clearError()
    setRestoreCloseOpen(true)
  }

  function closeRestoreClose(): void {
    if (!restoreCloseAction.busy) {
      setRestoreCloseOpen(false)
    }
  }

  async function submitRestoreClose(): Promise<void> {
    if (restoreCloseAction.busy || restoreLadder !== 'initiator') {
      return
    }
    if (await restoreCloseAction.run(actions.sendMaintenanceRestoreClose())) {
      setRestoreCloseOpen(false)
    }
  }

  // Leaving the page forgets both codes with the view's state, and an answer that
  // arrives later finds the view gone.
  useEffect(() => {
    passDisposed.current = false
    restorePassDisposed.current = false

    return () => {
      passDisposed.current = true
      passOpenNow.current = false
      restorePassDisposed.current = true
      restorePassOpenNow.current = false
    }
  }, [])

  return (
    <HilosAdminPage
      page={HilosPages.MAINTENANCE}
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <HilosMaintenanceWatchdogAlert alert={watchdogAlert} />

      <div aria-live="polite" data-id="hilos-maintenance-state">
        {position === 'open' ? (
          <div
            className="alert alert-success d-flex flex-column flex-sm-row gap-3 align-items-start mb-3"
            data-id="hilos-maintenance-state-open"
          >
            <div className="d-flex gap-3 flex-grow-1">
              <i className="bi bi-check-circle-fill fs-5" aria-hidden="true" />
              <div>
                <h2 className="h6 fw-semibold mb-1">
                  {HILOS_MAINTENANCE_STATE_COPY.openTitle}
                </h2>
                <p className="small mb-0">
                  {HILOS_MAINTENANCE_STATE_COPY.openDescription}
                </p>
              </div>
            </div>
            <LoadingButton
              className="btn-warning btn-sm text-nowrap"
              disabled={enableAction.busy}
              data-id="hilos-maintenance-enable"
              onClick={openEnable}
            >
              {HILOS_MAINTENANCE_ENABLE_COPY.enableTitle}
            </LoadingButton>
          </div>
        ) : stateCard ? (
          <section
            className="card border-warning mb-3"
            data-id={`hilos-maintenance-state-${stateCard.position}`}
            aria-label={stateCard.title}
          >
            <div className="card-header d-flex flex-wrap align-items-center gap-2 bg-warning-subtle">
              <h2 className="h6 fw-semibold mb-0">{stateCard.title}</h2>
              <span className="badge text-bg-warning">{stateCard.phase}</span>
              <span className="small text-body-secondary ms-auto">
                {HILOS_MAINTENANCE_STATE_COPY.operationPrefix}{' '}
                <code>{stateCard.operation ?? '—'}</code>
              </span>
            </div>
            <div className="card-body">
              <dl className="row mb-0">
                <dt className="col-sm-3">
                  {HILOS_MAINTENANCE_STATE_COPY.phase}
                </dt>
                <dd className="col-sm-9">
                  <span className="badge text-bg-warning">
                    {stateCard.phase}
                  </span>
                  {stateCard.phaseNote ? (
                    <span className="text-body-secondary ms-2">
                      {stateCard.phaseNote}
                    </span>
                  ) : null}
                </dd>

                {stateCard.position === 'manual' ? (
                  <>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.closedSince}
                    </dt>
                    <dd className="col-sm-9">
                      <code data-id="hilos-maintenance-started-at">
                        {startedAt}
                      </code>
                      <span className="text-body-secondary ms-2">
                        {stateCard.elapsed}
                      </span>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.turnedOn}
                    </dt>
                    <dd className="col-sm-9">{stateCard.turnedOn}</dd>
                  </>
                ) : (
                  <>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.operation}
                    </dt>
                    <dd className="col-sm-9">
                      <code>{stateCard.operation ?? '—'}</code>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.initiator}
                    </dt>
                    <dd
                      className="col-sm-9"
                      data-id="hilos-maintenance-initiator"
                    >
                      <code>{stateCard.initiatorCaption}</code>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.started}
                    </dt>
                    <dd className="col-sm-9">
                      <code data-id="hilos-maintenance-started-at">
                        {startedAt}
                      </code>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.frozen}
                    </dt>
                    <dd className="col-sm-9">
                      <code data-id="hilos-maintenance-activated-at">
                        {activatedAt}
                      </code>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.lastMovement}
                    </dt>
                    <dd className="col-sm-9">
                      <code data-id="hilos-maintenance-progress-at">
                        {progressAt}
                      </code>
                      {progressElapsed !== null ? (
                        <span
                          className="text-body-secondary ms-2"
                          data-id="hilos-maintenance-progress-elapsed"
                        >
                          {progressElapsed}
                        </span>
                      ) : null}
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.agentsStopped}
                    </dt>
                    <dd
                      className="col-sm-9"
                      data-id="hilos-maintenance-stopped-agents"
                    >
                      {stateCard.stoppedAgentsCaption}
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.diagnosticsNode}
                    </dt>
                    <dd
                      className="col-sm-9"
                      data-id="hilos-maintenance-diagnostics-node"
                    >
                      <code>{stateCard.diagnosticsNodeId ?? '—'}</code>
                    </dd>
                    <dt className="col-sm-3">
                      {HILOS_MAINTENANCE_STATE_COPY.agentStart}
                    </dt>
                    <dd
                      className="col-sm-9"
                      data-id="hilos-maintenance-agent-start"
                    >
                      {stateCard.agentStartCaption}
                    </dd>
                  </>
                )}

                <dt className="col-sm-3">
                  {HILOS_MAINTENANCE_STATE_COPY.codesInHand}
                </dt>
                <dd className="col-sm-9" data-id="hilos-maintenance-pass-count">
                  {stateCard.passCount}
                </dd>
                <dt className="col-sm-3">
                  {HILOS_MAINTENANCE_STATE_COPY.circle}
                </dt>
                <dd className="col-sm-9">{stateCard.circle}</dd>
              </dl>
              {stateCard.position === 'manual' ? (
                <div className="hilos-button-row mt-3">
                  <LoadingButton
                    className="btn-outline-primary btn-sm"
                    loading={passAction.busy}
                    data-id="hilos-maintenance-pass"
                    onClick={openPass}
                  >
                    {HILOS_MAINTENANCE_PASS_COPY.issue}
                  </LoadingButton>
                  <LoadingButton
                    className="btn-warning btn-sm"
                    loading={disableAction.busy}
                    data-id="hilos-maintenance-disable"
                    onClick={openDisable}
                  >
                    {HILOS_MAINTENANCE_DISABLE_COPY.disableTitle}
                  </LoadingButton>
                </div>
              ) : null}
            </div>
          </section>
        ) : null}
      </div>

      {restoreLadder === 'initiator' ? (
        <div className="card mb-3" data-id="hilos-maintenance-restore-ladder">
          <div className="card-body d-flex flex-wrap gap-2 align-items-center">
            <LoadingButton
              className="btn-outline-primary btn-sm"
              loading={restorePassAction.busy}
              data-id="hilos-maintenance-restore-pass"
              onClick={openRestorePass}
            >
              {HILOS_MAINTENANCE_RESTORE_PASS_COPY.issue}
            </LoadingButton>
            <LoadingButton
              className="btn-warning btn-sm"
              loading={restoreOpenAction.busy}
              data-id="hilos-maintenance-restore-open"
              onClick={openRestoreOpen}
            >
              {HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue}
            </LoadingButton>
            <LoadingButton
              className="btn-outline-danger btn-sm ms-auto"
              loading={restoreCloseAction.busy}
              data-id="hilos-maintenance-restore-close"
              onClick={openRestoreClose}
            >
              {HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.issue}
            </LoadingButton>
          </div>
        </div>
      ) : restoreLadder === 'other' && maintenanceState !== null ? (
        <div
          className="alert alert-secondary small mb-3"
          role="status"
          data-id="hilos-maintenance-other-browser"
        >
          <i className="bi bi-person-workspace me-1" aria-hidden="true" />
          {hilosMaintenanceOtherBrowserLine(maintenanceState)}
        </div>
      ) : null}

      {watchdogThresholds ? (
        <section
          className="card mb-3"
          data-id="hilos-maintenance-watchdog-thresholds"
        >
          <div className="card-header">
            <h2 className="h6 mb-0">{watchdogThresholds.title}</h2>
          </div>
          <div className="card-body">
            <dl className="row mb-0">
              {watchdogThresholds.rows.map((row) => (
                <Fragment key={row.label}>
                  <dt className="col-sm-5">{row.label}</dt>
                  <dd className="col-sm-7 text-break">
                    <HilosHideable value={row.value} />
                  </dd>
                </Fragment>
              ))}
            </dl>
          </div>
          <div className="card-footer small text-body-secondary">
            {watchdogThresholds.footer}
          </div>
        </section>
      ) : null}

      <HilosModal
        open={enableOpen}
        title={HILOS_MAINTENANCE_ENABLE_COPY.enableTitle}
        busy={enableAction.busy}
        initialFocus="dialog"
        onClose={closeEnable}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={enableAction.busy}
              data-id="hilos-maintenance-enable-cancel"
              onClick={requestClose}
            >
              {HILOS_MAINTENANCE_ENABLE_COPY.cancel}
            </button>
            <LoadingButton
              className="btn-warning"
              loading={enableAction.busy}
              disabled={position !== 'open'}
              data-id="hilos-maintenance-enable-confirm"
              onClick={() => void submitEnable()}
            >
              {HILOS_MAINTENANCE_ENABLE_COPY.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="hilos-maintenance-enable-modal">
          <HilosActionError
            action={enableAction}
            detailsTitle={HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle}
          />
          <p className="mb-2">{HILOS_MAINTENANCE_ENABLE_COPY.lead}</p>
          <ul className="mb-2 ps-3">
            <li>
              <strong>{HILOS_MAINTENANCE_ENABLE_COPY.outside}</strong> —{' '}
              {HILOS_MAINTENANCE_ENABLE_COPY.outsideNote}
            </li>
            <li>
              <strong>{HILOS_MAINTENANCE_ENABLE_COPY.inside}</strong> —{' '}
              {HILOS_MAINTENANCE_ENABLE_COPY.insideNote}
            </li>
            <li>
              <strong>{HILOS_MAINTENANCE_ENABLE_COPY.agents}</strong> —{' '}
              {HILOS_MAINTENANCE_ENABLE_COPY.agentsNote}
            </li>
          </ul>
          <p className="mb-0 text-body-secondary">
            {HILOS_MAINTENANCE_ENABLE_COPY.footer}
          </p>
        </div>
      </HilosModal>

      <HilosModal
        open={disableOpen}
        title={HILOS_MAINTENANCE_DISABLE_COPY.disableTitle}
        busy={disableAction.busy}
        initialFocus="dialog"
        onClose={closeDisable}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={disableAction.busy}
              data-id="hilos-maintenance-disable-cancel"
              onClick={requestClose}
            >
              {HILOS_MAINTENANCE_DISABLE_COPY.cancel}
            </button>
            <LoadingButton
              className="btn-warning"
              loading={disableAction.busy}
              disabled={position !== 'manual'}
              data-id="hilos-maintenance-disable-confirm"
              onClick={() => void submitDisable()}
            >
              {HILOS_MAINTENANCE_DISABLE_COPY.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="hilos-maintenance-disable-modal">
          <HilosActionError
            action={disableAction}
            detailsTitle={HILOS_MAINTENANCE_DISABLE_COPY.refusalTitle}
          />
          <p className="mb-2">{HILOS_MAINTENANCE_DISABLE_COPY.lead}</p>
          <p className="mb-0 text-body-secondary">
            {HILOS_MAINTENANCE_DISABLE_COPY.footer}
          </p>
        </div>
      </HilosModal>

      <HilosModal
        open={passOpen}
        title={
          passStep === 'confirm'
            ? HILOS_MAINTENANCE_PASS_COPY.mintTitle
            : HILOS_MAINTENANCE_PASS_COPY.codeTitle
        }
        busy={passAction.busy}
        copyText={pass ?? ''}
        initialFocus="dialog"
        onClose={closePass}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={passAction.busy}
              data-id="hilos-maintenance-pass-cancel"
              onClick={requestClose}
            >
              {passStep === 'confirm'
                ? HILOS_MAINTENANCE_PASS_COPY.cancel
                : HILOS_MAINTENANCE_PASS_COPY.close}
            </button>
            {passStep === 'confirm' ? (
              <LoadingButton
                className="btn-primary"
                loading={passAction.busy}
                disabled={position !== 'manual'}
                data-id="hilos-maintenance-pass-confirm"
                onClick={() => void submitPass()}
              >
                {HILOS_MAINTENANCE_PASS_COPY.mint}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <div data-id="hilos-maintenance-pass-modal">
          {passStep === 'confirm' ? (
            <>
              <HilosActionError
                action={passAction}
                detailsTitle={HILOS_MAINTENANCE_PASS_COPY.refusalTitle}
              />
              <p className="mb-2">{HILOS_MAINTENANCE_PASS_COPY.lead}</p>
              <p className="mb-0 text-body-secondary">
                {HILOS_MAINTENANCE_PASS_COPY.reuseNote}
              </p>
            </>
          ) : (
            <div data-id="hilos-maintenance-pass-result">
              <p className="mb-2 text-body-secondary">
                {HILOS_MAINTENANCE_PASS_COPY.resultLead}
              </p>
              <div
                ref={passValue}
                tabIndex={-1}
                className="border rounded-3 p-3 bg-body-tertiary text-center user-select-all text-break"
                data-id="hilos-maintenance-pass-value"
              >
                <code className="fs-5">{pass}</code>
              </div>
              <p className="small text-body-secondary mt-2 mb-0">
                {HILOS_MAINTENANCE_PASS_COPY.resultNote}
              </p>
            </div>
          )}
        </div>
      </HilosModal>

      <HilosModal
        open={restorePassOpen}
        title={
          restorePassStep === 'confirm'
            ? HILOS_MAINTENANCE_RESTORE_PASS_COPY.mintTitle
            : HILOS_MAINTENANCE_RESTORE_PASS_COPY.codeTitle
        }
        busy={restorePassAction.busy}
        copyText={restorePass ?? ''}
        initialFocus="dialog"
        onClose={closeRestorePass}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={restorePassAction.busy}
              data-id="hilos-maintenance-restore-pass-cancel"
              onClick={requestClose}
            >
              {restorePassStep === 'confirm'
                ? HILOS_MAINTENANCE_RESTORE_PASS_COPY.cancel
                : HILOS_MAINTENANCE_RESTORE_PASS_COPY.close}
            </button>
            {restorePassStep === 'confirm' ? (
              <LoadingButton
                className="btn-primary"
                loading={restorePassAction.busy}
                disabled={restoreLadder !== 'initiator'}
                data-id="hilos-maintenance-restore-pass-confirm"
                onClick={() => void submitRestorePass()}
              >
                {HILOS_MAINTENANCE_RESTORE_PASS_COPY.mint}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <div data-id="hilos-maintenance-restore-pass-modal">
          {restorePassStep === 'confirm' ? (
            <>
              <HilosActionError
                action={restorePassAction}
                detailsTitle={HILOS_MAINTENANCE_RESTORE_PASS_COPY.refusalTitle}
              />
              <p className="mb-2">{HILOS_MAINTENANCE_RESTORE_PASS_COPY.lead}</p>
              <p className="mb-0 text-body-secondary">
                {HILOS_MAINTENANCE_RESTORE_PASS_COPY.reuseNote}
              </p>
            </>
          ) : (
            <div data-id="hilos-maintenance-restore-pass-result">
              <p className="mb-2 text-body-secondary">
                {HILOS_MAINTENANCE_RESTORE_PASS_COPY.resultLead}
              </p>
              <div
                ref={restorePassValue}
                tabIndex={-1}
                className="border rounded-3 p-3 bg-body-tertiary text-center user-select-all text-break"
                data-id="hilos-maintenance-restore-pass-value"
              >
                <code className="fs-5">{restorePass}</code>
              </div>
              <p className="small text-body-secondary mt-2 mb-0">
                {HILOS_MAINTENANCE_RESTORE_PASS_COPY.resultNote}
              </p>
            </div>
          )}
        </div>
      </HilosModal>

      <HilosModal
        open={restoreOpenOpen}
        title={HILOS_MAINTENANCE_RESTORE_OPEN_COPY.title}
        busy={restoreOpenAction.busy}
        initialFocus="dialog"
        onClose={closeRestoreOpen}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={restoreOpenAction.busy}
              data-id="hilos-maintenance-restore-open-cancel"
              onClick={requestClose}
            >
              {HILOS_MAINTENANCE_RESTORE_OPEN_COPY.cancel}
            </button>
            <LoadingButton
              className="btn-warning"
              loading={restoreOpenAction.busy}
              disabled={restoreLadder !== 'initiator'}
              data-id="hilos-maintenance-restore-open-confirm"
              onClick={() => void submitRestoreOpen()}
            >
              {HILOS_MAINTENANCE_RESTORE_OPEN_COPY.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="hilos-maintenance-restore-open-modal">
          <HilosActionError
            action={restoreOpenAction}
            detailsTitle={HILOS_MAINTENANCE_RESTORE_OPEN_COPY.refusalTitle}
          />
          <p className="mb-2">{HILOS_MAINTENANCE_RESTORE_OPEN_COPY.lead}</p>
          <p className="mb-0 text-body-secondary">
            {HILOS_MAINTENANCE_RESTORE_OPEN_COPY.noWayBack}
          </p>
        </div>
      </HilosModal>

      <HilosModal
        open={restoreCloseOpen}
        title={HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.title}
        busy={restoreCloseAction.busy}
        initialFocus="dialog"
        onClose={closeRestoreClose}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={restoreCloseAction.busy}
              data-id="hilos-maintenance-restore-close-cancel"
              onClick={requestClose}
            >
              {HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.cancel}
            </button>
            <LoadingButton
              className="btn-danger"
              loading={restoreCloseAction.busy}
              disabled={restoreLadder !== 'initiator'}
              data-id="hilos-maintenance-restore-close-confirm"
              onClick={() => void submitRestoreClose()}
            >
              {HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="hilos-maintenance-restore-close-modal">
          <HilosActionError
            action={restoreCloseAction}
            detailsTitle={HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.refusalTitle}
          />
          <p className="mb-2">{HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.lead}</p>
          <p className="mb-0 text-body-secondary">
            {HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.footer}
          </p>
        </div>
      </HilosModal>
    </HilosAdminPage>
  )
}
