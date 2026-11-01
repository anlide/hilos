import { useEffect, useId, useMemo } from 'react'
import {
  createHilosLegalSettingsTable,
  createHilosLegalSettingsActions,
  createHilosLegalSettingEdit,
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
} from '@hilos/core/admin/legal'
import {
  HilosPages,
  isHiddenValue,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosTableEmptyState } from '../../HilosTableEmptyState.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { ConflictActions } from '../../ConflictActions.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/**
 * A setting value's label, or the value itself when it has none.
 *
 * @param value The value, already narrowed clear of the hidden mark.
 */
function legalValueLabel(value: string): string {
  return HILOS_LEGAL_VALUE_COPY[value] ?? value
}

/** Two legal settings edited through a modal-owned row merge and tracked writes. */
export function HilosLegalSettingsPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const table = useMemo(() => createHilosLegalSettingsTable(context), [context])
  const actions = useMemo(
    () => createHilosLegalSettingsActions(context),
    [context],
  )
  const editor = useMemo(
    () => createHilosLegalSettingEdit(table.controller, actions),
    [table, actions],
  )
  const settingsRows = useSignal(table.controller.rows)
  const settingsBody = useSignal(table.controller.frame.body)
  const settings = useMemo(
    () => settingsRows.flatMap(({ row }) => (row ? [row] : [])),
    [settingsRows],
  )
  const opened = useSignal(editor.opened),
    row = useSignal(editor.row),
    form = useSignal(editor.form),
    state = useSignal(editor.state),
    noticeText = useSignal(editor.noticeText),
    saveAvailable = useSignal(editor.saveAvailable),
    saveLabel = useSignal(editor.saveLabel),
    saving = useSignal(editor.saving)
  const action = useTrackedAction({ toast: false })
  const inputId = useId()
  useEffect(() => {
    table.start()
    editor.start()
    return () => {
      editor.dispose()
      table.dispose()
    }
  }, [table, editor])
  function open(key: string): void {
    if (action.busy) return
    action.clearError()
    editor.open(key)
  }
  // Save and Enter go through the session's one door: it refuses, closes an
  // unchanged choice, or sends through the tracked action and closes on success.
  function save(): void {
    void editor.save(action.run)
  }
  return (
    <HilosAdminPage page={HilosPages.LEGAL_SETTINGS}>
      <p className="small text-body-secondary">
        Two settings control how consent is given and what happens after a
        deadline. Document texts and deadlines stay in code.
      </p>
      {settingsBody === 'unavailable' && (
        <HilosTableEmptyState
          controller={table.controller}
          kind="unavailable"
        />
      )}
      {settings.map((setting) => {
        const label =
          HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ?? setting.rowKey
        const headingId = `${inputId}-${setting.rowKey}`
        return (
          <section
            key={setting.rowKey}
            className="mt-4"
            aria-labelledby={headingId}
          >
            <h2
              id={headingId}
              className="h6 text-uppercase text-body-secondary mb-2"
            >
              {label}
            </h2>
            <div className="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom">
              <div className="flex-grow-1">
                <div className="fw-semibold small">{label}</div>
                <div className="small text-body-secondary">
                  {HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.hint}
                </div>
              </div>
              <div className="d-flex align-items-center gap-3">
                <div className="text-end small">
                  <div
                    className="fw-semibold"
                    data-id={`legal-setting-value-${setting.rowKey}`}
                  >
                    <HilosHideable value={setting.value}>
                      {legalValueLabel}
                    </HilosHideable>
                  </div>
                  <dl className="d-flex flex-wrap justify-content-end gap-1 text-body-secondary mb-0">
                    <dt className="fw-normal">Default:</dt>
                    <dd className="mb-0">
                      <HilosHideable value={setting.defaultValue}>
                        {legalValueLabel}
                      </HilosHideable>
                    </dd>
                  </dl>
                </div>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  data-id={`legal-setting-edit-${setting.rowKey}`}
                  aria-label={`Edit ${label}`}
                  onClick={() => open(setting.rowKey)}
                >
                  Edit
                </button>
              </div>
            </div>
          </section>
        )
      })}
      <HilosModal
        open={opened}
        busy={saving}
        title={
          row
            ? (HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey)
            : 'Edit setting'
        }
        onClose={editor.close}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={state.conflict}
            disableSave={!saveAvailable}
            saveLabel={saveLabel}
            onSave={save}
            onAcceptMine={editor.keepMine}
            onAcceptTheirs={editor.takeTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={saving}
                data-id="legal-setting-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={action.busy}
                disabled={disabled}
                data-id="legal-setting-save"
                onClick={onSave}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError
          action={action}
          detailsTitle="Couldn't save the legal setting"
        />
        {row && (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              save()
            }}
          >
            {isHiddenValue(form.value) ? (
              <>
                <div className="form-label">
                  {HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey}
                </div>
                <HilosHiddenMark />
              </>
            ) : (
              <>
                <label className="form-label" htmlFor={inputId}>
                  {HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey}
                </label>
                <select
                  id={inputId}
                  value={form.value}
                  onChange={(event) =>
                    editor.setForm({ value: event.target.value })
                  }
                  className="form-select"
                  data-id="legal-setting-input"
                  data-autofocus
                  disabled={action.busy}
                >
                  {(HILOS_LEGAL_SETTING_COPY[row.rowKey]?.values ?? []).map(
                    (option) => (
                      <option key={option} value={option}>
                        {HILOS_LEGAL_VALUE_COPY[option] ?? option}
                      </option>
                    ),
                  )}
                </select>
              </>
            )}
            <HilosEditNotice
              kind={state.notice?.kind ?? null}
              text={noticeText}
              dataId="legal-setting-notice"
            />
          </form>
        )}
      </HilosModal>
    </HilosAdminPage>
  )
}
