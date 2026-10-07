// HilosMaintenancePage — the framework maintenance section inside the admin shell
// (HIL-1119), with the verifier circle's add and remove dialogs (HIL-1120/1121).
// React counterpart of the Vue page (HIL-1123): the remove dialog holds its row in
// focus and reports a removal in another tab; the list and its online marks stay
// live. The table, row view-model, actions and words belong to the core headless.
// A project supplies only HilosMaintenanceContext. Bootstrap classes only.
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
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
 * The verifier circle, with dialogs that close only on the server's answer.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenancePage({ context }: HilosMaintenancePageProps) {
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
