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
import { useEffect, useMemo } from 'react'
import {
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosTwoFactorSettingEdit,
  describeHilosSecondFactorSetting,
} from '@hilos/core'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
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

  // The edit modal: one setting at a time, as the core window has it; this view
  // binds the list or the number input.
  const editor = useMemo(
    () => createHilosTwoFactorSettingEdit(settings.controller, actions),
    [settings, actions],
  )

  useEffect(() => {
    settings.start()
    editor.start()

    return () => {
      editor.dispose()
      settings.dispose()
    }
  }, [settings, editor])

  const editOpen = useSignal(editor.opened)
  const editRow = useSignal(editor.row)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const canSave = useSignal(editor.canSave)
  const edit = useTrackedAction()
  const editHidden = editForm.hidden
  const editValue = editForm.text
  const setEditValue = (text: string) => editor.patchForm({ text })
  const editNotice = live.notice?.kind ?? null
  const editTitle = editRow ? labelOf(editRow) : 'Edit setting'

  function openEdit(row: HilosTwoFactorSettingRow): void {
    // The window takes the row into focus, so the modal edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    edit.clearError()
    editor.open(row.rowKey)
  }

  function closeEdit(): void {
    editor.close()
  }

  function acceptMine(): void {
    editor.keepMine()
  }

  function acceptTheirs(): void {
    editor.takeTheirs()
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with the entered value.
  function submitEdit(): void {
    void editor.save(edit.run)
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
              <HilosHideable value={row.value}>
                {(value) => describeHilosSecondFactorSetting(row.rowKey, value)}
              </HilosHideable>
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

      <HilosModal
        open={editOpen}
        confirmOnClose={live.dirty}
        aria-label={editTitle}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!canSave}
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
            {editHidden ? (
              <div className="mb-3">
                <div className="form-label">{labelOf(editRow)}</div>
                <HilosHiddenMark />
              </div>
            ) : (
              <>
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
              </>
            )}
            <p className="form-text mb-0">
              {HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint} Default:{' '}
              <HilosHideable value={editRow.defaultValue}>
                {(value) =>
                  describeHilosSecondFactorSetting(editRow.rowKey, value)
                }
              </HilosHideable>
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
