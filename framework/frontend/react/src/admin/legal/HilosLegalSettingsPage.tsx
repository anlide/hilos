import { useEffect, useId, useMemo } from 'react'
import {
  createHilosLegalSettingsTable,
  createHilosLegalSettingsActions,
  createHilosLegalSettingEdit,
  HilosPages,
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
  HILOS_LEGAL_SETTING_PREVIEWS,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { ConflictActions } from '../../ConflictActions.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

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
    () => createHilosLegalSettingEdit(table.controller),
    [table],
  )
  const row = useSignal(editor.row),
    value = useSignal(editor.value),
    state = useSignal(editor.state),
    noticeText = useSignal(editor.noticeText)
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
  async function save(): Promise<void> {
    if (row === null || action.busy || state.gone || state.conflict) return
    if (!state.dirty) {
      editor.close()
      return
    }
    if (await action.run(actions.sendSettingSet(row.rowKey, value)))
      editor.close()
  }
  return (
    <HilosAdminPage page={HilosPages.LEGAL_SETTINGS}>
      <p className="small text-body-secondary">
        Two settings control how consent is given and what happens after a
        deadline. Document texts and deadlines stay in code.
      </p>
      <HilosViewportTable
        controller={table.controller}
        cells={{
          [HilosLegalRowKey.rowKey]: (setting) => (
            <>
              <strong>
                {HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ??
                  setting.rowKey}
              </strong>
              <div className="small text-body-secondary">
                {HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.hint}
              </div>
            </>
          ),
          [HilosLegalRowKey.value]: (setting) => (
            <>
              <span data-id={`legal-setting-value-${setting.rowKey}`}>
                {HILOS_LEGAL_VALUE_COPY[setting.value] ?? setting.value}
              </span>
              <div className="small text-body-secondary">
                Default:{' '}
                {HILOS_LEGAL_VALUE_COPY[setting.defaultValue] ??
                  setting.defaultValue}
              </div>
            </>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (setting) => (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              data-id={`legal-setting-edit-${setting.rowKey}`}
              onClick={() => open(setting.rowKey)}
            >
              Edit
            </button>
          ),
        }}
      />
      <section className="mt-4">
        <h2 className="h5">Previews</h2>
        <div className="row g-3">
          {HILOS_LEGAL_SETTING_PREVIEWS.map((preview) => (
            <div key={preview.key} className="col-md-6">
              <div
                className="border rounded p-3 h-100"
                data-id={`legal-setting-preview-${preview.key}`}
              >
                <h3 className="h6">{preview.title}</h3>
                {preview.key === 'checkbox' && (
                  <label className="small d-flex align-items-start gap-2 mb-2">
                    <input
                      type="checkbox"
                      className="form-check-input flex-shrink-0"
                      disabled
                    />
                    {preview.text}
                  </label>
                )}
                {(preview.key === 'checkbox' || preview.key === 'line') && (
                  <button
                    type="button"
                    className="btn btn-sm btn-primary w-100 mb-2"
                    disabled
                  >
                    Create account
                  </button>
                )}
                {preview.key !== 'checkbox' && (
                  <blockquote
                    className={`small ${preview.key === 'freeze' ? 'alert alert-info' : preview.key === 'remind' ? 'alert alert-warning' : ''}`}
                  >
                    {preview.text}
                  </blockquote>
                )}
                <p className="small text-body-secondary mb-0">{preview.hint}</p>
              </div>
            </div>
          ))}
        </div>
      </section>
      <section className="mt-4 small text-body-secondary">
        <h2 className="h6">What is not a setting</h2>
        <p className="mb-0">
          The acceptance window ends on the revision's effective date. There is
          no global window length, switch to bypass consent, or editor for
          document text.
        </p>
      </section>
      <HilosModal
        open={row !== null}
        title={
          row
            ? (HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey)
            : 'Edit setting'
        }
        onClose={editor.close}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={action.busy}
              data-id="legal-setting-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <ConflictActions
              conflict={state.conflict}
              disableSave={!state.dirty || action.busy || state.gone}
              mergeable={false}
              saveLabel={state.gone ? 'Deleted' : 'Save'}
              onSave={() => void save()}
              onAcceptMine={editor.keepMine}
              onAcceptTheirs={editor.takeTheirs}
              saveButton={({ disabled, onSave }) => (
                <LoadingButton
                  className="btn-primary"
                  loading={action.loading}
                  disabled={disabled}
                  data-id="legal-setting-save"
                  onClick={onSave}
                >
                  {state.gone ? 'Deleted' : 'Save'}
                </LoadingButton>
              )}
            />
          </>
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
              void save()
            }}
          >
            <label className="form-label" htmlFor={inputId}>
              {HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey}
            </label>
            <select
              id={inputId}
              value={value}
              onChange={(event) => editor.setValue(event.target.value)}
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
