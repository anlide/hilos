// HilosMaintenancePage — the framework maintenance section inside the admin shell
// (HIL-1119), with the verifier circle's add and remove dialogs (HIL-1120/1121).
// React counterpart of the Vue page (HIL-1123): the remove dialog holds its row in
// focus and reports a removal in another tab; the list and its online marks stay
// live. The table, row view-model, actions and words belong to the core headless.
// A project supplies only HilosMaintenanceContext. Bootstrap classes only.
import { useEffect, useMemo, useState } from 'react'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HilosPages,
  hiddenAsWord,
  HILOS_VIEW_MODE_COPY,
  isHiddenValue,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
  type HilosTableColumnOf,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
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

const CIRCLE_COLUMNS: HilosTableColumnOf<HilosMaintenanceCircleRow>[] = [
  {
    key: MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.addressColumn,
    sortable: true,
  },
  {
    key: MAINTENANCE_CIRCLE_ONLINE_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.onlineColumn,
  },
  {
    key: 'actions',
    label: '',
    headerClass: 'text-end',
    reads: [MAINTENANCE_CIRCLE_IDENTIFIER_FIELD],
  },
]

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
  const circle = useMemo(
    () => createHilosMaintenanceCircleTable(context),
    [context],
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

  const [circleAddOpen, setCircleAddOpen] = useState(false)
  const [circleAddIdentifier, setCircleAddIdentifier] = useState('')
  const circleAdd = useTrackedAction()
  const [circleRemoveOpen, setCircleRemoveOpen] = useState(false)
  const [circleRemoveRow, setCircleRemoveRow] =
    useState<HilosMaintenanceCircleRow | null>(null)
  const circleRemove = useTrackedAction()
  const removeLive = circleRemoveRow ? focusedRow : undefined
  const removeShown = removeLive ?? circleRemoveRow
  // Our own echo can make the row a placeholder before the action ack arrives.
  const removeGone =
    circleRemoveRow !== null && !circleRemove.busy && removeLive === undefined

  function openCircleAdd(): void {
    circleAdd.clearError()
    setCircleAddIdentifier('')
    setCircleAddOpen(true)
  }

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
          <div className="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <div>
              <div className="fw-semibold">
                {HILOS_MAINTENANCE_CIRCLE_COPY.title}
              </div>
              <div className="small text-body-secondary">
                {HILOS_MAINTENANCE_CIRCLE_COPY.rule}
              </div>
              <div className="small text-body-secondary">
                {HILOS_MAINTENANCE_CIRCLE_COPY.volatile}
              </div>
            </div>
            <button
              type="button"
              className="btn btn-outline-primary btn-sm text-nowrap"
              data-id="hilos-maintenance-circle-add"
              onClick={openCircleAdd}
            >
              {HILOS_MAINTENANCE_CIRCLE_COPY.addButton}
            </button>
          </div>
          <div className="mt-3">
            <HilosViewportTable
              dataId="hilos-maintenance-circle-table"
              label={HILOS_MAINTENANCE_CIRCLE_COPY.title}
              controller={circle.controller}
              columns={CIRCLE_COLUMNS}
              emptyText={HILOS_MAINTENANCE_CIRCLE_COPY.empty}
              row={(row) => (
                <>
                  <td
                    data-id={`hilos-maintenance-circle-row-${circleKey(row)}`}
                  >
                    {hiddenAsWord(row.identifier)}
                  </td>
                  <td>
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
                  </td>
                  <td className="text-end">
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
                  </td>
                </>
              )}
            />
          </div>
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
              HILOS_VIEW_MODE_COPY.hidden
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
