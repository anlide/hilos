// The Maintenance Circle tab: the live verifier table and its Add/Remove dialogs.
// The core owns rows, actions and copy; this view binds its window and row focus to
// Circle and shares Maintenance's section head. Each dialog closes only on its own
// tracked answer, and no row is drawn ahead of the live table. Bootstrap classes
// only (styling-rules.md).
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  isHiddenValue,
} from '@hilos/core/base'

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

/** Props for {@link HilosMaintenanceCirclePage}. */
export interface HilosMaintenanceCirclePageProps {
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
 * The verifier circle with dialogs that add and remove its members, each closing
 * only on the server's answer.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenanceCirclePage({
  context,
}: HilosMaintenanceCirclePageProps) {
  // The add dialog. It closes on the server's word only: a refusal keeps it open with
  // what was typed, and the named row arrives over the live table, not from here.
  const [circleAddOpen, setCircleAddOpen] = useState(false)
  const [circleAddIdentifier, setCircleAddIdentifier] = useState('')
  const circleAdd = useTrackedAction()
  // Guard before creating the handle: tracked busy state follows the next render.
  const circleAddSending = useRef(false)
  const clearCircleAddError = circleAdd.clearError
  const openCircleAdd = useCallback(() => {
    clearCircleAddError()
    setCircleAddIdentifier('')
    setCircleAddOpen(true)
  }, [clearCircleAddError])
  const circle = useMemo(
    () =>
      createHilosMaintenanceCircleTable(
        context,
        { openAdd: openCircleAdd },
        HilosPages.MAINTENANCE_CIRCLE,
      ),
    [context, openCircleAdd],
  )
  const actions = useMemo(
    () => createHilosMaintenanceActions(context),
    [context],
  )
  // The row the remove dialog holds in focus, as it stands now — undefined once it is gone.
  const focusedRow = useSignal(circle.controller.focusedRow)

  // Bind the table to Circle on mount, consuming its initial page window, and
  // release its row focus and listeners on unmount.
  useEffect(() => {
    circle.start()

    return () => {
      circle.controller.releaseFocus()
      circle.dispose()
    }
  }, [circle])

  // The remove dialog. It closes on the server's word only, and the row leaves over
  // the live table, not from here. The dialog holds its row in focus while open.
  const [circleRemoveOpen, setCircleRemoveOpen] = useState(false)
  const [circleRemoveRow, setCircleRemoveRow] =
    useState<HilosMaintenanceCircleRow | null>(null)
  const circleRemove = useTrackedAction()
  // The live row the remove dialog is about; the row the dialog opened with stays on
  // screen once it is gone.
  const removeLive = circleRemoveRow ? focusedRow : undefined
  const removeShown = removeLive ?? circleRemoveRow
  // Gone elsewhere. Our own removal in flight is not that: its echo makes the row a
  // placeholder before the `::success` reply lands
  // (TableViewportController.applyOwnDelta), and that placeholder is our doing.
  const removeGone =
    circleRemoveRow !== null && !circleRemove.busy && removeLive === undefined

  function closeCircleAdd(): void {
    setCircleAddOpen(false)
  }

  async function submitCircleAdd(): Promise<void> {
    if (circleAddSending.current || circleAddIdentifier.trim() === '') {
      return
    }
    circleAddSending.current = true
    try {
      if (
        await circleAdd.run(
          actions.sendMaintenanceCircleAdd(circleAddIdentifier),
        )
      ) {
        closeCircleAdd()
      }
    } finally {
      circleAddSending.current = false
    }
  }

  function openCircleRemove(row: HilosMaintenanceCircleRow): void {
    // Flush pending and take the row into focus; a row already taken out by someone
    // else does not open the dialog.
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
    <HilosAdminPage
      page={HilosPages.MAINTENANCE_CIRCLE}
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <HilosViewportTable
        dataId="hilos-maintenance-circle-table"
        controller={circle.controller}
        cells={{
          [MAINTENANCE_CIRCLE_IDENTIFIER_FIELD]: (row) => (
            <span data-id={`hilos-maintenance-circle-row-${circleKey(row)}`}>
              <HilosHideable value={row.identifier} />
            </span>
          ),
          [MAINTENANCE_CIRCLE_ONLINE_FIELD]: (row) => (
            <span
              className={row.online ? 'text-success' : 'text-body-secondary'}
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

      <HilosModal
        open={circleAddOpen}
        title={HILOS_MAINTENANCE_CIRCLE_COPY.addTitle}
        busy={circleAdd.busy}
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
              type="submit"
              form="hilos-maintenance-circle-add-form"
              loading={circleAdd.busy}
              disabled={circleAddIdentifier.trim() === ''}
              data-id="hilos-maintenance-circle-add-confirm"
            >
              {HILOS_MAINTENANCE_CIRCLE_COPY.addConfirm}
            </LoadingButton>
          </>
        )}
      >
        <form
          id="hilos-maintenance-circle-add-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submitCircleAdd()
          }}
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
        </form>
      </HilosModal>

      <HilosModal
        open={circleRemoveOpen}
        title={HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle}
        busy={circleRemove.busy}
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
              loading={circleRemove.busy}
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
