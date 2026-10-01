// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
// each — who must use it, the days a device is trusted, the size of a set of
// backup codes, and the removal wait with its bounds. Each row shows its value in
// words and a pencil; the pencil opens a modal (the modal-only editing rule), and
// a value a setting's rule refuses stays in the modal with the refusal above it.
// The table, the row view-model, the edit round-trip and the words are the core
// headless's; this view owns only the markup, so a project mounts it by passing
// its HilosTwoFactorContext. The modal holds its row in focus and merges against
// it through the shared row-edit helper (rowEdit.ts, conflict-resolution.md),
// saying what happened elsewhere on one line of room held in advance
// (HilosEditNotice). The screen is built from text: the mockup's node is a debt
// (D-115). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
  HilosStepUpOperationRow,
  RowEditBaseline,
  RowEditNoticeKind,
  RowEditStep,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurity2faPage}. */
export interface HilosSecurity2faPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosTwoFactorSettingRow): string {
  return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

/** The one field the edit modal edits. */
interface SettingEditFields {
  value: string
}

/**
 * The one line the modal says about the other side, for what the helper found;
 * a value in words, the way its cell says it.
 */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosTwoFactorSettingRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow
        ? `Changed elsewhere to "${describeHilosSecondFactorSetting(liveRow.rowKey, liveRow.value)}".`
        : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/**
 * The two-step verification admin page.
 *
 * @param props The project's two-step verification context.
 */
export function HilosSecurity2faPage({ context }: HilosSecurity2faPageProps) {
  const settings = useMemo(
    () => createHilosSecurityTwoFactorTable(context),
    [context],
  )
  const actions = useMemo(
    () => createHilosSecurityTwoFactorActions(context),
    [context],
  )
  const operations = useMemo(
    () => createHilosSecurityStepUpTable(context),
    [context],
  )
  const operationActions = useMemo(
    () => createHilosSecurityStepUpActions(context),
    [context],
  )

  useEffect(() => {
    settings.start()
    operations.start()

    return () => {
      settings.dispose()
      operations.dispose()
    }
  }, [settings, operations])

  const operationToggle = useTrackedAction()
  const [pendingOperationKey, setPendingOperationKey] = useState<string | null>(
    null,
  )

  async function toggleOperation(
    row: HilosStepUpOperationRow,
    enabled: boolean,
  ): Promise<void> {
    setPendingOperationKey(row.operationKey)
    try {
      await operationToggle.run(
        operationActions.sendOperationSet(row.operationKey, enabled),
      )
    } finally {
      setPendingOperationKey(null)
    }
  }

  // The edit modal: one setting at a time, its value as typed until Save.
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosTwoFactorSettingRow | null>(null)
  const [editValue, setEditValue] = useState('')
  const [editBaseline, setEditBaseline] = useState<
    RowEditBaseline<SettingEditFields>
  >(() => openRowEdit<SettingEditFields>({ value: '' }))
  const edit = useTrackedAction()

  // The live row the open modal is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone.
  const liveRow = useSignal(settings.controller.focusedRow)
  const live = resolveRowEdit(
    liveRow ? { value: liveRow.value } : undefined,
    editBaseline,
    { value: editValue.trim() },
  )
  const editNotice = live.notice?.kind ?? null
  const editNoticeText = noticeText(editNotice, liveRow)
  const editSaveLabel = live.gone ? 'Deleted' : 'Save'
  const editTitle = editRow ? labelOf(editRow) : 'Edit setting'

  // Put a step of the helper into the modal: the snapshot moves, and a value the
  // step takes lands in the input.
  function applyStep(step: RowEditStep<SettingEditFields>): void {
    setEditBaseline(step.baseline)
    if (step.take.value !== undefined) {
      setEditValue(step.take.value)
    }
  }

  // The helper hands a step whenever the other side moved the value while the
  // person left it alone, or both arrived at the same one; the modal applies it
  // at once.
  const settle = live.settle
  useEffect(() => {
    if (editOpen && settle) {
      applyStep(settle)
    }
  }, [editOpen, settle])

  function openEdit(row: HilosTwoFactorSettingRow): void {
    // Flush pending and take the row into focus, so the modal edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    const fresh = settings.controller.focusRow(row.rowKey)
    if (!fresh) {
      return
    }
    edit.clearError()
    setEditRow(fresh)
    setEditValue(fresh.value)
    setEditBaseline(openRowEdit<SettingEditFields>({ value: fresh.value }))
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
    settings.controller.releaseFocus()
  }

  function acceptMine(): void {
    setEditBaseline(keepMineRowEdit(live, editBaseline))
  }

  function acceptTheirs(): void {
    applyStep(takeTheirsRowEdit(live, editBaseline))
  }

  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy || live.gone || live.conflict) {
      return
    }
    if (!live.dirty) {
      closeEdit()

      return
    }
    if (
      await edit.run(actions.sendSettingSet(editRow.rowKey, editValue.trim()))
    ) {
      closeEdit()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_2FA}>
      <HilosViewportTable
        controller={settings.controller}
        cells={{
          rowKey: (row) => (
            <>
              <div className="fw-semibold">{labelOf(row)}</div>
              <div className="small text-body-secondary">
                {HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.hint}
              </div>
            </>
          ),
          value: (row) => (
            <span data-id={`hilos-2fa-value-${row.rowKey}`}>
              {describeHilosSecondFactorSetting(row.rowKey, row.value)}
            </span>
          ),
          actions: (row) => (
            <button
              type="button"
              className="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label={`Edit ${labelOf(row)}`}
              data-id={`hilos-2fa-edit-${row.rowKey}`}
              onClick={() => openEdit(row)}
            >
              <i className="bi bi-pencil" aria-hidden="true"></i>
            </button>
          ),
        }}
      />

      <div className="mt-4">
        <HilosViewportTable
          dataId="hilos-step-up-table"
          controller={operations.controller}
          cells={{
            operationKey: (row) => (
              <span
                className="fw-semibold"
                data-id={`hilos-step-up-row-${row.operationKey}`}
              >
                {row.label}
              </span>
            ),
            owner: (row) => (
              <span className="badge text-bg-light border">
                {row.owner === 'framework'
                  ? HILOS_STEP_UP_ADMIN_COPY.framework
                  : HILOS_STEP_UP_ADMIN_COPY.project}
              </span>
            ),
            enabled: (row) => (
              <HilosSwitch
                className="mb-0"
                checked={row.enabled}
                busy={pendingOperationKey === row.operationKey}
                disabled={operationToggle.busy}
                aria-label={`Require confirmation for ${row.label}`}
                dataId={`hilos-step-up-switch-${row.operationKey}`}
                onToggle={(enabled) => void toggleOperation(row, enabled)}
              />
            ),
          }}
        />
      </div>

      <HilosModal
        open={editOpen}
        confirmOnClose={live.dirty}
        aria-label={editTitle}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!live.dirty || edit.busy || live.gone}
            saveLabel={editSaveLabel}
            onSave={() => void submitEdit()}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={edit.busy}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.loading}
                disabled={disabled}
                data-id="hilos-2fa-save"
                onClick={onSave}
              >
                {editSaveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError action={edit} detailsTitle="Couldn't save" />
        {editRow ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void submitEdit()
            }}
          >
            <label className="form-label" htmlFor="hilos-2fa-input">
              {labelOf(editRow)}
            </label>
            {editRow.rowKey === HilosSecondFactorSettingKey.required ? (
              <select
                id="hilos-2fa-input"
                className="form-select"
                data-id="hilos-2fa-input"
                data-autofocus
                value={editValue}
                onChange={(event) => setEditValue(event.target.value)}
              >
                {HILOS_SECOND_FACTOR_REQUIRED_VALUES.map((value) => (
                  <option key={value} value={value}>
                    {HILOS_SECOND_FACTOR_REQUIRED_COPY[value]}
                  </option>
                ))}
              </select>
            ) : (
              <input
                id="hilos-2fa-input"
                type="number"
                inputMode="numeric"
                className="form-control"
                data-id="hilos-2fa-input"
                data-autofocus
                value={editValue}
                onChange={(event) => setEditValue(event.target.value)}
              />
            )}
            <p className="form-text mb-0">
              {HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint} Default:{' '}
              {describeHilosSecondFactorSetting(
                editRow.rowKey,
                editRow.defaultValue,
              )}
              .
            </p>
            <HilosEditNotice
              kind={editNotice}
              text={editNoticeText}
              dataId="hilos-2fa-edit-notice"
            />
          </form>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
