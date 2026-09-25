// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The table, the frame it declares (its
// columns, search, and empty words), the row view-model, and the add/update/delete
// round-trips are the core headless's (createHilosSettingsTable /
// createHilosSettingsActions); this view owns only the markup, so a project mounts
// it by passing its HilosSettingsContext and declares the catalog on its backend.
// The edit dialog merges against the live row through the shared row-edit helper
// (rowEdit.ts, conflict-resolution.md) and says what happened elsewhere on one
// line of room held in advance (HilosEditNotice).
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
  createHilosSettingsTable,
  hasCustomValue,
  isOrphanSetting,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosSettingRow,
  HilosSettingsContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
} from '@hilos/core'

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

/** The one field the dialog edits: the row's own value, null for the catalog default. */
interface SettingEditFields {
  overrideValue: string | null
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(live: RowEditState<SettingEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return live.fields.overrideValue.incoming === null
        ? 'Reset elsewhere to the catalog default.'
        : `Changed elsewhere to "${live.fields.overrideValue.incoming}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
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

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    settings.start()

    return () => settings.dispose()
  }, [settings])

  // Edit dialog: one row's custom value (or a reset back to the catalog default).
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosSettingRow | null>(null)
  const [editBaseline, setEditBaseline] = useState<
    RowEditBaseline<SettingEditFields>
  >(() => openRowEdit<SettingEditFields>({ overrideValue: null }))
  const [editValue, setEditValue] = useState('')
  const [editUseCustom, setEditUseCustom] = useState(false)
  const edit = useTrackedAction()

  // Delete dialog: orphan keys only (not in the catalog).
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteRow, setDeleteRow] = useState<HilosSettingRow | null>(null)
  const del = useTrackedAction()

  const editInputType = inputType(editRow?.type)
  const editStep = inputStep(editRow?.type)
  // The custom value the dialog would persist, normalized to a string: a number
  // input yields a number, while the row override and the wire are strings, so an
  // un-normalized value would never match the echoed row. Null leaves the default.
  const editOverride: string | null = editUseCustom ? String(editValue) : null
  // The live row the open dialog is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone.
  const liveRow = useSignal(settings.controller.focusedRow)
  const live = resolveRowEdit(
    liveRow ? { overrideValue: liveRow.overrideValue } : undefined,
    editBaseline,
    { overrideValue: editOverride },
  )
  const deleteGone = liveRow === undefined
  const editDirty = live.dirty
  const editTitle = editRow ? `Edit · ${editRow.key}` : 'Edit setting'
  const editSaveLabel = live.gone ? 'Deleted' : 'Save'
  const editNotice = live.notice?.kind ?? null
  const editNoticeText = noticeText(live)

  // Put a step of the helper into the dialog: the snapshot moves, and a value
  // the step takes lands in the switch and the text the way the dialog opened
  // with it. A reset taken from the other side leaves the text on the value now
  // in effect — the live row's, not the one the dialog opened on, which is the
  // override the other side just removed.
  function applyStep(
    row: HilosSettingRow,
    step: RowEditStep<SettingEditFields>,
  ): void {
    setEditBaseline(step.baseline)
    const taken = step.take.overrideValue
    if (taken !== undefined) {
      setEditUseCustom(taken !== null || isOrphanSetting(row))
      setEditValue(taken ?? liveRow?.value ?? row.value ?? '')
    }
  }

  // The helper hands a step whenever the other side moved a field the person
  // left alone, or both arrived at the same value; the dialog applies it at once.
  const settle = live.settle
  useEffect(() => {
    if (editOpen && editRow && settle) {
      applyStep(editRow, settle)
    }
  }, [editOpen, editRow, settle])

  function openEdit(row: HilosSettingRow): void {
    // Flush pending and take the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row removed by someone else (now
    // a placeholder) declines to open.
    const fresh = settings.controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    edit.clearError()
    setEditRow(fresh)
    // An orphan has no catalog default behind it and no switch in the dialog, so its
    // value is always its own; a cataloged key opens with the switch on only when it
    // carries a value of its own.
    setEditUseCustom(isOrphanSetting(fresh) || hasCustomValue(fresh))
    setEditValue(fresh.overrideValue ?? fresh.value ?? '')
    setEditBaseline(openRowEdit({ overrideValue: fresh.overrideValue }))
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
    settings.controller.releaseFocus()
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure toasts and stays open so the entered value survives.
  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy || live.gone) {
      return
    }
    if (!live.dirty) {
      closeEdit()

      return
    }
    const next = editOverride
    // The switch turned off means "back to the catalog default", which resets the key
    // by dropping its row. With a value, an orphan updates in place and a cataloged
    // key adds by key (the add is idempotent, so the row need not exist yet).
    let handle
    if (next === null) {
      handle = actions.sendSettingReset(editRow.key)
    } else {
      handle = isOrphanSetting(editRow)
        ? actions.sendSettingUpdate(editRow.key, next)
        : actions.sendSettingAdd(editRow.key, next)
    }
    if (await edit.run(handle)) {
      closeEdit()
    }
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

  function acceptMine(): void {
    setEditBaseline(keepMineRowEdit(live, editBaseline))
  }

  function acceptTheirs(): void {
    if (editRow) {
      applyStep(editRow, takeTheirsRowEdit(live, editBaseline))
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
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="hilos-settings-edit-cancel"
              disabled={edit.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <ConflictActions
              conflict={live.conflict}
              disableSave={!editDirty || edit.busy || live.gone}
              mergeable={false}
              saveLabel={editSaveLabel}
              onSave={() => void submitEdit()}
              onAcceptMine={acceptMine}
              onAcceptTheirs={acceptTheirs}
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
          </>
        )}
      >
        <HilosActionError action={edit} />
        {editRow ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void submitEdit()
            }}
          >
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
                  onChange={(event) => setEditUseCustom(event.target.checked)}
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
        <HilosActionError action={del} />
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
    </HilosAdminPage>
  )
}
