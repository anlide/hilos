// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The ↺ beside the pencil resets
// through a confirm dialog built like the orphan delete — never in one click. The table, the frame it declares (its
// columns, search, and empty words), the row view-model, and the add/update/delete
// round-trips are the core headless's (createHilosSettingsTable /
// createHilosSettingsActions); this view owns only the markup, so a project mounts
// it by passing its HilosSettingsContext and declares the catalog on its backend.
// The edit dialog is the core row-edit session over the focused row
// (createHilosSettingEdit, rowEditSession.ts, conflict-resolution.md): it merges
// against the live row and says what happened elsewhere on one line of room held
// in advance (HilosEditNotice); this view binds the switch and the text.
// Authoritative-backend: a submit dispatches a tracked action and the dialog
// closes on its `::success` reply (useTrackedAction, step 7.4); a failure surfaces
// as a toast and leaves the dialog open with the entered value (toasts.md).
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  SETTING_KEY_FIELD,
  SETTING_VALUE_FIELD,
  createHilosSettingsActions,
  createHilosSettingEdit,
  createHilosSettingsTable,
  hasCustomValue,
  isOrphanSetting,
} from '@hilos/core'
import type { HilosSettingRow, HilosSettingsContext } from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { HilosSettingValueCell } from './HilosSettingValueCell.js'

/** Props for {@link HilosSettingsPage}. */
export interface HilosSettingsPageProps {
  /** The project context: scope stores and the action lifecycle. */
  context: HilosSettingsContext
}

/** Map a setting type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

function inputStep(type: string | undefined): 'any' | undefined {
  return type === 'float' ? 'any' : undefined
}

/**
 * The framework settings admin page: the cataloged table with per-row edit /
 * reset / delete dialogs.
 *
 * @param props The project context (scope stores + action lifecycle).
 */
export function HilosSettingsPage({ context }: HilosSettingsPageProps) {
  const settings = useMemo(() => createHilosSettingsTable(context), [context])
  const actions = useMemo(() => createHilosSettingsActions(context), [context])

  // Edit dialog: one row's custom value (or a reset back to the catalog
  // default), as the core window has it; this view binds the switch and the text.
  const editor = useMemo(
    () => createHilosSettingEdit(settings.controller, actions),
    [settings, actions],
  )
  const edit = useTrackedAction()

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount; the edit window listens for the merge's steps
  // over the same span.
  useEffect(() => {
    settings.start()
    editor.start()

    return () => {
      editor.dispose()
      settings.dispose()
    }
  }, [settings, editor])

  // Delete dialog: orphan keys only (not in the catalog).
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteRow, setDeleteRow] = useState<HilosSettingRow | null>(null)
  const del = useTrackedAction()

  // Reset dialog: back to the catalog default, only on confirm. It reads the live
  // row it holds in focus, so what it shows follows the other tabs.
  const [resetOpen, setResetOpen] = useState(false)
  const [resetRow, setResetRow] = useState<HilosSettingRow | null>(null)
  const reset = useTrackedAction()

  const editOpen = useSignal(editor.opened)
  const editRow = useSignal(editor.row)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const canSave = useSignal(editor.canSave)
  const editInputType = inputType(editRow?.type)
  const editStep = inputStep(editRow?.type)
  const editHidden = editForm.hidden
  const editUseCustom = editForm.useCustom
  const editValue = editForm.text
  const setEditUseCustom = (on: boolean) => editor.patchForm({ useCustom: on })
  const setEditValue = (text: string) => editor.patchForm({ text })
  // The live row the open delete or reset dialog is about: the row the table
  // holds in focus, which the server follows wherever it goes; undefined once the
  // row is gone.
  const liveRow = useSignal(settings.controller.focusedRow)
  const deleteGone = liveRow === undefined
  const resetShown = liveRow ?? resetRow
  const resetGone = liveRow === undefined || !hasCustomValue(liveRow)
  const editDirty = live.dirty
  const editTitle = editRow ? `Edit · ${editRow.key}` : 'Edit setting'
  const editNotice = live.notice?.kind ?? null

  function openEdit(row: HilosSettingRow): void {
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row removed by someone else (now
    // a placeholder) declines to open.
    edit.clearError()
    editor.open(row.key)
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

  function openDelete(row: HilosSettingRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a delete.
    const fresh = settings.controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    del.clearError()
    setDeleteRow(fresh)
    setDeleteOpen(true)
  }

  function closeDelete(): void {
    setDeleteOpen(false)
    settings.controller.releaseFocus()
  }

  async function submitDelete(): Promise<void> {
    if (!deleteRow || del.busy || deleteGone) {
      return
    }
    if (await del.run(actions.sendSettingDelete(deleteRow.key))) {
      closeDelete()
    }
  }

  function openReset(row: HilosSettingRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a reset.
    const fresh = settings.controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    reset.clearError()
    setResetRow(fresh)
    setResetOpen(true)
  }

  function closeReset(): void {
    setResetOpen(false)
    settings.controller.releaseFocus()
  }

  async function submitReset(): Promise<void> {
    if (!resetRow || reset.busy || resetGone) {
      return
    }
    if (await reset.run(actions.sendSettingReset(resetRow.key))) {
      closeReset()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SETTINGS}>
      <HilosViewportTable
        controller={settings.controller}
        cells={{
          [SETTING_KEY_FIELD]: (row) => (
            <code className="text-break">{row.key}</code>
          ),
          [SETTING_VALUE_FIELD]: (row) => (
            <div style={{ maxWidth: '18rem' }}>
              <HilosSettingValueCell
                value={row.value}
                type={row.type}
                valueSource={row.valueSource}
                defaultReferenceKey={row.defaultReferenceKey}
              />
            </div>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <>
              <button
                type="button"
                className="btn btn-sm btn-outline-primary"
                title={
                  hasCustomValue(row) || isOrphanSetting(row)
                    ? 'Edit'
                    : 'Set custom value'
                }
                aria-label={
                  hasCustomValue(row) || isOrphanSetting(row)
                    ? 'Edit'
                    : 'Set custom value'
                }
                data-id={`hilos-settings-edit-${row.key}`}
                onClick={() => openEdit(row)}
              >
                <i
                  className={
                    hasCustomValue(row) || isOrphanSetting(row)
                      ? 'bi bi-pencil'
                      : 'bi bi-plus-lg'
                  }
                  aria-hidden="true"
                />
              </button>
              {!isOrphanSetting(row) ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  title="Reset to default"
                  aria-label="Reset to default"
                  disabled={!hasCustomValue(row)}
                  data-id={`hilos-settings-reset-${row.key}`}
                  onClick={() => openReset(row)}
                >
                  <i
                    className="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  />
                </button>
              ) : null}
              {isOrphanSetting(row) ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-danger"
                  title="Delete orphan setting"
                  aria-label="Delete orphan setting"
                  data-id={`hilos-settings-delete-${row.key}`}
                  onClick={() => openDelete(row)}
                >
                  <i className="bi bi-trash" aria-hidden="true" />
                </button>
              ) : null}
            </>
          ),
        }}
      />

      <HilosModal
        open={editOpen}
        confirmOnClose={editDirty}
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
                data-id="hilos-settings-edit-cancel"
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
                data-id="hilos-settings-edit-save"
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
                <span className="form-label d-block">{editRow.key}</span>
                <HilosSettingValueCell
                  value={editRow.value}
                  type={editRow.type}
                  valueSource={editRow.valueSource}
                  defaultReferenceKey={editRow.defaultReferenceKey}
                />
              </div>
            ) : (
              <>
                {!isOrphanSetting(editRow) ? (
                  <div className="mb-3">
                    <span className="form-label d-block">Catalog default</span>
                    <HilosSettingValueCell
                      value={editRow.defaultValue}
                      type={editRow.type}
                      valueSource={editRow.valueSource}
                      defaultReferenceKey={editRow.defaultReferenceKey}
                    />
                  </div>
                ) : null}
                {!isOrphanSetting(editRow) ? (
                  <div className="form-check form-switch mb-3">
                    <input
                      id="hilos-settings-edit-custom"
                      type="checkbox"
                      className="form-check-input"
                      data-id="hilos-settings-edit-custom"
                      checked={editUseCustom}
                      onChange={(event) =>
                        setEditUseCustom(event.target.checked)
                      }
                    />
                    <label
                      className="form-check-label"
                      htmlFor="hilos-settings-edit-custom"
                    >
                      Custom value
                    </label>
                  </div>
                ) : null}
                {editUseCustom ? (
                  <div className="mb-0">
                    {editInputType === 'checkbox' ? (
                      <div className="form-check">
                        <input
                          id="hilos-settings-edit-value"
                          type="checkbox"
                          className="form-check-input"
                          data-id="hilos-settings-edit-value"
                          data-autofocus
                          checked={editValue === '1'}
                          onChange={(event) =>
                            setEditValue(event.target.checked ? '1' : '0')
                          }
                        />
                        <label
                          className="form-check-label"
                          htmlFor="hilos-settings-edit-value"
                        >
                          Enabled
                        </label>
                      </div>
                    ) : (
                      <>
                        <label
                          className="form-label"
                          htmlFor="hilos-settings-edit-value"
                        >
                          {editRow.key}
                        </label>
                        <input
                          id="hilos-settings-edit-value"
                          type={editInputType}
                          step={editStep}
                          className="form-control"
                          data-id="hilos-settings-edit-value"
                          data-autofocus
                          value={editValue}
                          onChange={(event) => setEditValue(event.target.value)}
                        />
                      </>
                    )}
                  </div>
                ) : null}
              </>
            )}
            <HilosEditNotice
              kind={editNotice}
              text={editNoticeText}
              dataId="hilos-settings-edit-notice"
            />
          </form>
        ) : null}
      </HilosModal>

      <HilosModal
        open={deleteOpen}
        title={deleteRow ? `Delete · ${deleteRow.key}` : 'Delete setting'}
        closeOnBackdrop={!del.busy}
        closeOnEsc={!del.busy}
        initialFocus="dialog"
        onClose={closeDelete}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={del.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={del.loading}
              disabled={del.busy || deleteGone}
              data-id="hilos-settings-delete-confirm"
              onClick={() => void submitDelete()}
            >
              Delete
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={del}
          detailsTitle="Couldn't delete the setting"
        />
        <p className="mb-0 text-body-secondary">
          This removes the orphan row from the database. Orphan keys are not in
          the catalog.
        </p>
        {deleteRow ? (
          <p className="mb-0 mt-2">
            <code className="text-break">{deleteRow.key}</code>
          </p>
        ) : null}
        {deleteGone ? (
          <p
            className="mb-0 mt-2 text-body-secondary"
            data-id="hilos-settings-delete-gone"
          >
            This setting was already deleted elsewhere.
          </p>
        ) : null}
      </HilosModal>

      <HilosModal
        open={resetOpen}
        title={resetRow ? `Reset · ${resetRow.key}` : 'Reset setting'}
        closeOnBackdrop={!reset.busy}
        closeOnEsc={!reset.busy}
        initialFocus="dialog"
        onClose={closeReset}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={reset.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={reset.loading}
              disabled={reset.busy || resetGone}
              data-id="hilos-settings-reset-confirm"
              onClick={() => void submitReset()}
            >
              Reset
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={reset}
          detailsTitle="Couldn't reset the setting"
        />
        {resetShown ? (
          <dl className="row mb-0">
            <dt className="col-4">Now</dt>
            <dd className="col-8" data-id="hilos-settings-reset-now">
              <HilosSettingValueCell
                value={resetShown.value}
                type={resetShown.type}
                valueSource={resetShown.valueSource}
                defaultReferenceKey={resetShown.defaultReferenceKey}
              />
            </dd>
            <dt className="col-4">Back to</dt>
            <dd className="col-8" data-id="hilos-settings-reset-default">
              <HilosSettingValueCell
                value={resetShown.defaultValue}
                type={resetShown.type}
                valueSource={
                  resetShown.defaultReferenceKey !== null
                    ? 'reference'
                    : 'default'
                }
                defaultReferenceKey={resetShown.defaultReferenceKey}
              />
            </dd>
          </dl>
        ) : null}
        {resetGone ? (
          <p
            className="mb-0 mt-2 text-body-secondary"
            data-id="hilos-settings-reset-gone"
          >
            Already reset elsewhere.
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
