// HilosMaintenancePage — the framework maintenance section inside the admin shell
// (HIL-1119), with the verifier circle's add and remove dialogs (HIL-1120/1121).
// React counterpart of the Vue page (HIL-1123, HIL-1365): the live protected-mode
// state with manual entry, exit and code issuance, each waiting for its own
// tracked answer independently of the live state frame; the remove dialog holds
// its row in focus and reports a removal in another tab; the list and its online
// marks stay live. The state, table, row view-model, actions and words belong to
// the core headless. A project supplies only HilosMaintenanceContext. Bootstrap
// classes only.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ActionError,
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  hilosMaintenanceCard,
  hilosMaintenancePosition,
  isHiddenValue,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for the framework maintenance section. */
export interface HilosMaintenancePageProps {
  /** The project context: scope stores, the connection and the action lifecycle. */
  context: HilosMaintenanceContext
}

/**
 * What tells a circle row apart in its data-ids: the address, or the membership
 * id while the address is hidden — every hidden row would share one otherwise.
 *
 * @param row The circle row.
 */
function circleKey(row: HilosMaintenanceCircleRow): string {
  return isHiddenValue(row.identifier)
    ? `member-${row.memberId}`
    : row.identifier
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
 * The protected-mode block and the verifier circle, with dialogs that close only
 * on the server's answer.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenancePage({ context }: HilosMaintenancePageProps) {
  const stateHandle = useMemo(
    () => createHilosMaintenanceState(context),
    [context],
  )
  const maintenanceState = useSignal(stateHandle.state)
  const [nowMs, setNowMs] = useState(() => Date.now())
  const position = hilosMaintenancePosition(maintenanceState)
  const stateCard = hilosMaintenanceCard(maintenanceState, nowMs)

  useEffect(() => {
    stateHandle.start()

    return () => stateHandle.dispose()
  }, [stateHandle])

  useEffect(() => {
    const clockTick = setInterval(() => setNowMs(Date.now()), 30_000)

    return () => clearInterval(clockTick)
  }, [])

  const [circleAddOpen, setCircleAddOpen] = useState(false)
  const [circleAddIdentifier, setCircleAddIdentifier] = useState('')
  const circleAdd = useTrackedAction()
  const clearCircleAddError = circleAdd.clearError
  const openCircleAdd = useCallback(() => {
    clearCircleAddError()
    setCircleAddIdentifier('')
    setCircleAddOpen(true)
  }, [clearCircleAddError])
  const circle = useMemo(
    () =>
      createHilosMaintenanceCircleTable(context, { openAdd: openCircleAdd }),
    [context, openCircleAdd],
  )
  const actions = useMemo(
    () => createHilosMaintenanceActions(context),
    [context],
  )
  const focusedRow = useSignal(circle.controller.focusedRow)

  useEffect(() => {
    circle.start()

    return () => circle.dispose()
  }, [circle])

  const [circleRemoveOpen, setCircleRemoveOpen] = useState(false)
  const [circleRemoveRow, setCircleRemoveRow] =
    useState<HilosMaintenanceCircleRow | null>(null)
  const circleRemove = useTrackedAction()
  const removeLive = circleRemoveRow ? focusedRow : undefined
  const removeShown = removeLive ?? circleRemoveRow
  // Our own echo can make the row a placeholder before the action ack arrives.
  const removeGone =
    circleRemoveRow !== null && !circleRemove.busy && removeLive === undefined

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

  // Leaving the page forgets the code with the view's state, and an answer that
  // arrives later finds the view gone.
  useEffect(() => {
    passDisposed.current = false

    return () => {
      passDisposed.current = true
      passOpenNow.current = false
    }
  }, [])

  function closeCircleAdd(): void {
    setCircleAddOpen(false)
  }

  async function submitCircleAdd(): Promise<void> {
    if (circleAdd.busy || circleAddIdentifier.trim() === '') {
      return
    }
    if (
      await circleAdd.run(actions.sendMaintenanceCircleAdd(circleAddIdentifier))
    ) {
      closeCircleAdd()
    }
  }

  function openCircleRemove(row: HilosMaintenanceCircleRow): void {
    const fresh = circle.controller.focusRow(String(row.memberId))
    if (!fresh) {
      return
    }
    circleRemove.clearError()
    setCircleRemoveRow(fresh)
    setCircleRemoveOpen(true)
  }

  function closeCircleRemove(): void {
    setCircleRemoveOpen(false)
    circle.controller.releaseFocus()
  }

  async function submitCircleRemove(): Promise<void> {
    if (!circleRemoveRow || circleRemove.busy || removeGone) {
      return
    }
    if (
      await circleRemove.run(
        actions.sendMaintenanceCircleRemove(circleRemoveRow.memberId),
      )
    ) {
      closeCircleRemove()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.MAINTENANCE}>
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
                      <code>{stateCard.startedAt ?? '—'}</code>
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
                      {HILOS_MAINTENANCE_STATE_COPY.started}
                    </dt>
                    <dd className="col-sm-9">
                      <code>{stateCard.startedAt ?? '—'}</code>
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
                    loading={passAction.loading}
                    disabled={passAction.busy}
                    data-id="hilos-maintenance-pass"
                    onClick={openPass}
                  >
                    {HILOS_MAINTENANCE_PASS_COPY.issue}
                  </LoadingButton>
                  <LoadingButton
                    className="btn-warning btn-sm"
                    loading={disableAction.loading}
                    disabled={disableAction.busy}
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

      <HilosModal
        open={enableOpen}
        title={HILOS_MAINTENANCE_ENABLE_COPY.enableTitle}
        closeOnBackdrop={!enableAction.busy}
        closeOnEsc={!enableAction.busy}
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
              loading={enableAction.loading}
              disabled={enableAction.busy || position !== 'open'}
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
        closeOnBackdrop={!disableAction.busy}
        closeOnEsc={!disableAction.busy}
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
              loading={disableAction.loading}
              disabled={disableAction.busy || position !== 'manual'}
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
        closeOnBackdrop={!passAction.busy}
        closeOnEsc={!passAction.busy}
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
                loading={passAction.loading}
                disabled={passAction.busy || position !== 'manual'}
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

      <div className="card mb-3" data-id="hilos-maintenance-circle-panel">
        <div className="card-body">
          <HilosViewportTable
            dataId="hilos-maintenance-circle-table"
            controller={circle.controller}
            cells={{
              [MAINTENANCE_CIRCLE_IDENTIFIER_FIELD]: (row) => (
                <span
                  data-id={`hilos-maintenance-circle-row-${circleKey(row)}`}
                >
                  <HilosHideable value={row.identifier} />
                </span>
              ),
              [MAINTENANCE_CIRCLE_ONLINE_FIELD]: (row) => (
                <span
                  className={
                    row.online ? 'text-success' : 'text-body-secondary'
                  }
                  data-id={`hilos-maintenance-circle-online-${circleKey(row)}`}
                >
                  {row.online
                    ? HILOS_MAINTENANCE_CIRCLE_COPY.online
                    : HILOS_MAINTENANCE_CIRCLE_COPY.offline}
                </span>
              ),
              [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-danger"
                  title={HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle}
                  aria-label={HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle}
                  data-id={`hilos-maintenance-circle-remove-${circleKey(row)}`}
                  onClick={() => openCircleRemove(row)}
                >
                  <i className="bi bi-trash" aria-hidden="true" />
                </button>
              ),
            }}
          />
        </div>
      </div>

      <HilosModal
        open={circleAddOpen}
        title={HILOS_MAINTENANCE_CIRCLE_COPY.addTitle}
        closeOnBackdrop={!circleAdd.busy}
        closeOnEsc={!circleAdd.busy}
        onClose={closeCircleAdd}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={circleAdd.busy}
              data-id="hilos-maintenance-circle-add-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={circleAdd.loading}
              disabled={circleAddIdentifier.trim() === ''}
              data-id="hilos-maintenance-circle-add-confirm"
              onClick={() => void submitCircleAdd()}
            >
              {HILOS_MAINTENANCE_CIRCLE_COPY.addConfirm}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={circleAdd}
          detailsTitle={HILOS_MAINTENANCE_CIRCLE_COPY.addRefusalTitle}
        />
        <p className="mb-2 text-body-secondary">
          {HILOS_MAINTENANCE_CIRCLE_COPY.addLead}
        </p>
        <label
          className="form-label"
          htmlFor="hilos-maintenance-circle-add-field"
        >
          {HILOS_MAINTENANCE_CIRCLE_COPY.addField}
        </label>
        <input
          id="hilos-maintenance-circle-add-field"
          type="text"
          className="form-control"
          autoComplete="off"
          placeholder={HILOS_MAINTENANCE_CIRCLE_COPY.addPlaceholder}
          disabled={circleAdd.busy}
          data-id="hilos-maintenance-circle-add-field"
          data-autofocus
          value={circleAddIdentifier}
          onChange={(event) => setCircleAddIdentifier(event.target.value)}
        />
      </HilosModal>

      <HilosModal
        open={circleRemoveOpen}
        title={HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle}
        closeOnBackdrop={!circleRemove.busy}
        closeOnEsc={!circleRemove.busy}
        initialFocus="dialog"
        onClose={closeCircleRemove}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={circleRemove.busy}
              data-id="hilos-maintenance-circle-remove-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={circleRemove.loading}
              disabled={removeGone}
              data-id="hilos-maintenance-circle-remove-confirm"
              onClick={() => void submitCircleRemove()}
            >
              {removeGone
                ? HILOS_MAINTENANCE_CIRCLE_COPY.removeGoneConfirm
                : HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={circleRemove}
          detailsTitle={HILOS_MAINTENANCE_CIRCLE_COPY.removeRefusalTitle}
        />
        {removeShown ? (
          <p className="mb-0">
            {HILOS_MAINTENANCE_CIRCLE_COPY.removeAskBefore}{' '}
            {isHiddenValue(removeShown.identifier) ? (
              <HilosHiddenMark />
            ) : (
              <code>{removeShown.identifier}</code>
            )}{' '}
            {HILOS_MAINTENANCE_CIRCLE_COPY.removeAskAfter}
          </p>
        ) : null}
        <p className="mb-0 mt-2 text-body-secondary">
          {HILOS_MAINTENANCE_CIRCLE_COPY.removeNote}
        </p>
        <HilosEditNotice
          kind={removeGone ? 'deleted' : null}
          text={HILOS_MAINTENANCE_CIRCLE_COPY.removeGone}
          dataId="hilos-maintenance-circle-remove-notice"
        />
      </HilosModal>
    </HilosAdminPage>
  )
}
