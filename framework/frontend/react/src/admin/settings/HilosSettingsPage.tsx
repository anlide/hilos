// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The table, the row view-model, and the
// add/update/delete round-trips are the core headless's (createHilosSettingsTable /
// createHilosSettingsActions); this view owns only the markup, so a project mounts
// it by passing its HilosSettingsContext and declares the catalog on its backend.
// Authoritative-backend: a submit dispatches a tracked action and the dialog
// closes on its `::success` reply (useTrackedAction, step 7.4); a failure surfaces
// in the dialog. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HilosPages,
  createHilosSettingsActions,
  createHilosSettingsTable,
  isOrphanSetting,
  isPersistedSetting,
} from '@hilos/core'
import type {
  HilosSettingRow,
  HilosSettingsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { HilosSettingValueCell } from './HilosSettingValueCell.js'

/** Props for {@link HilosSettingsPage}. */
export interface HilosSettingsPageProps {
  /** The project context: scope stores and the action lifecycle. */
  context: HilosSettingsContext
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'key', label: 'Key', sortable: true },
  { key: 'value', label: 'Value', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

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

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    settings.start()

    return () => settings.dispose()
  }, [settings])

  // Edit dialog: one row's custom value (or a reset back to the catalog default).
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosSettingRow | null>(null)
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
  const editDirty = !!editRow && editOverride !== editRow.overrideValue

  function openEdit(row: HilosSettingRow): void {
    edit.clearError()
    setEditRow(row)
    setEditUseCustom(isPersistedSetting(row))
    setEditValue(row.overrideValue ?? row.value ?? '')
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure stays open with the reason shown.
  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy) {
      return
    }
    const next = editOverride
    if (next === editRow.overrideValue) {
      closeEdit()

      return
    }
    // A persisted row updates in place; an on-default catalog key adds a custom value.
    const handle = isPersistedSetting(editRow)
      ? actions.sendSettingUpdate(editRow.key, next)
      : actions.sendSettingAdd(editRow.key, next ?? '')
    if (await edit.run(handle)) {
      closeEdit()
    }
  }

  function openDelete(row: HilosSettingRow): void {
    del.clearError()
    setDeleteRow(row)
    setDeleteOpen(true)
  }

  function closeDelete(): void {
    setDeleteOpen(false)
  }

  async function submitDelete(): Promise<void> {
    if (!deleteRow || del.busy) {
      return
    }
    if (await del.run(actions.sendSettingDelete(deleteRow.key))) {
      closeDelete()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SETTINGS}>
      <HilosViewportTable
        controller={settings.controller}
        columns={COLUMNS}
        searchable
        searchPlaceholder="Search settings…"
        emptyText="No settings yet."
        row={(row) => (
          <>
            <td>
              <code>{row.key}</code>
            </td>
            <td style={{ maxWidth: '18rem' }}>
              <HilosSettingValueCell
                value={row.value}
                type={row.type}
                valueSource={row.valueSource}
                defaultReferenceKey={row.defaultReferenceKey}
              />
            </td>
            <td className="text-end">
              <div className="d-flex gap-1 justify-content-end">
                <button
                  type="button"
                  className="btn btn-sm btn-outline-primary"
                  title={isPersistedSetting(row) ? 'Edit' : 'Set custom value'}
                  aria-label={
                    isPersistedSetting(row) ? 'Edit' : 'Set custom value'
                  }
                  data-id={`hilos-settings-edit-${row.key}`}
                  onClick={() => openEdit(row)}
                >
                  <i
                    className={
                      isPersistedSetting(row) ? 'bi bi-pencil' : 'bi bi-plus-lg'
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
              </div>
            </td>
          </>
        )}
      />

      <HilosModal
        open={editOpen}
        title={editRow ? `Edit · ${editRow.key}` : 'Edit setting'}
        confirmOnClose={editDirty}
        onClose={closeEdit}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={edit.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={edit.loading}
              disabled={!editDirty || edit.busy}
              data-id="hilos-settings-edit-save"
              onClick={() => void submitEdit()}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        {edit.error ? (
          <div
            className="alert alert-danger"
            role="alert"
            data-id="hilos-settings-error"
          >
            {edit.error}
          </div>
        ) : null}
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
                      value={editValue}
                      onChange={(event) => setEditValue(event.target.value)}
                    />
                  </>
                )}
              </div>
            ) : null}
          </form>
        ) : null}
      </HilosModal>

      <HilosModal
        open={deleteOpen}
        title={deleteRow ? `Delete · ${deleteRow.key}` : 'Delete setting'}
        closeOnBackdrop={!del.busy}
        closeOnEsc={!del.busy}
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
              data-id="hilos-settings-delete-confirm"
              onClick={() => void submitDelete()}
            >
              Delete
            </LoadingButton>
          </>
        )}
      >
        {del.error ? (
          <div
            className="alert alert-danger"
            role="alert"
            data-id="hilos-settings-delete-error"
          >
            {del.error}
          </div>
        ) : null}
        <p className="mb-0 text-body-secondary">
          This removes the orphan row from the database. Orphan keys are not in
          the catalog.
        </p>
        {deleteRow ? (
          <p className="mb-0 mt-2">
            <code>{deleteRow.key}</code>
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
