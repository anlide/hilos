// HilosSecurityImpersonationPage — the framework impersonation settings page
// (HilosPages.SECURITY_IMPERSONATION, HIL-1170): the seven settings of
// impersonation, one row each — whether it exists in the product, what may be
// done inside someone else's account, whether its sign-in may be touched,
// whether the administrator carries their own rights in, and whom it may take
// over. The six yes-or-no rows carry a switch; the scope shows its value in
// words and a pencil that opens a modal (the modal-only editing rule).
// The table, the row view-model, the words and the two writes are the core
// headless's (createHilosSecurityImpersonationTable /
// createHilosSecurityImpersonationActions); this view owns only the markup, so a
// project mounts it by passing its HilosImpersonationContext.
// A switch is a tracked action, as on the sign-in methods page: a spinner on its
// own row while it flies, the other switches disabled, no toast on success, and
// the switch moves only when the table's row does; a refusal is the action's
// toast. The scope's modal is the two-factor page's: it holds its row in focus
// and merges against it through the shared row-edit helper (rowEdit.ts,
// conflict-resolution.md), saying what happened elsewhere on one line of room
// held in advance (HilosEditNotice); Save closes it on the server's answer,
// whose sentence is the toast, and a refusal stays in it.
// The screen is built from text: the mockup still draws these rows on the
// two-factor page (D-143). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_IMPERSONATION_SCOPE_HINT,
  HILOS_IMPERSONATION_SCOPE_VALUES,
  HILOS_IMPERSONATION_SETTING_COPY,
  HilosPages,
  createHilosImpersonationScopeEdit,
  createHilosSecurityImpersonationActions,
  createHilosSecurityImpersonationTable,
  hilosImpersonationScopeOf,
  isHilosImpersonationSwitch,
} from '@hilos/core'
import type {
  HilosImpersonationContext,
  HilosImpersonationScope,
  HilosImpersonationSettingRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurityImpersonationPage}. */
export interface HilosSecurityImpersonationPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosImpersonationContext
}

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosImpersonationSettingRow): string {
  return HILOS_IMPERSONATION_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

/**
 * The impersonation settings page: six switches and the scope in a modal.
 *
 * @param props The project's impersonation context.
 */
export function HilosSecurityImpersonationPage({
  context,
}: HilosSecurityImpersonationPageProps) {
  const settings = useMemo(
    () => createHilosSecurityImpersonationTable(context),
    [context],
  )
  const actions = useMemo(
    () => createHilosSecurityImpersonationActions(context),
    [context],
  )

  // The scope's modal: the choice as made until Save, as the core window has it;
  // this view binds the radio group.
  const editor = useMemo(
    () => createHilosImpersonationScopeEdit(settings.controller, actions),
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

  // One tracked runner for every switch: a single in-flight guard across rows is
  // enough, and the busy flag disables every switch while one write is settling.
  const toggle = useTrackedAction()
  const [pendingSwitchKey, setPendingSwitchKey] = useState<string | null>(null)

  // Dispatch the switch as a tracked action. Nothing is set optimistically: the
  // switch follows the row, which moves when the setting is written.
  async function toggleSwitch(
    row: HilosImpersonationSettingRow,
    next: boolean,
  ): Promise<void> {
    setPendingSwitchKey(row.rowKey)
    try {
      await toggle.run(actions.sendSwitchSet(row.rowKey, next))
    } finally {
      setPendingSwitchKey(null)
    }
  }

  const editOpen = useSignal(editor.opened)
  const editRow = useSignal(editor.row)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const canSave = useSignal(editor.canSave)
  const edit = useTrackedAction()
  const editHidden = editForm.hidden
  const editScope = editForm.scope
  const setEditScope = (scope: HilosImpersonationScope) =>
    editor.patchForm({ scope })
  const editNotice = live.notice?.kind ?? null
  const editTitle = editRow ? labelOf(editRow) : 'Edit setting'

  function openEdit(row: HilosImpersonationSettingRow): void {
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
    <HilosAdminPage page={HilosPages.SECURITY_IMPERSONATION}>
      <HilosViewportTable
        dataId="hilos-impersonation-table"
        controller={settings.controller}
        cells={{
          rowKey: (row) => (
            <>
              <div className="fw-semibold">{labelOf(row)}</div>
              <div className="small text-body-secondary">
                {HILOS_IMPERSONATION_SETTING_COPY[row.rowKey]?.hint}
              </div>
            </>
          ),
          value: (row) =>
            isHilosImpersonationSwitch(row.rowKey) ? (
              <HilosHideable value={row.enabled}>
                {(value) => (
                  <HilosSwitch
                    className="mb-0"
                    checked={value}
                    busy={pendingSwitchKey === row.rowKey}
                    disabled={toggle.busy}
                    aria-label={labelOf(row)}
                    dataId={`hilos-impersonation-switch-${row.rowKey}`}
                    onToggle={(next) => void toggleSwitch(row, next)}
                  />
                )}
              </HilosHideable>
            ) : (
              <span data-id="hilos-impersonation-scope-value">
                <HilosHideable value={row.value}>
                  {() =>
                    HILOS_IMPERSONATION_SCOPE_COPY[
                      hilosImpersonationScopeOf(row)
                    ]
                  }
                </HilosHideable>
              </span>
            ),
          actions: (row) =>
            isHilosImpersonationSwitch(row.rowKey) ? null : (
              <button
                type="button"
                className="btn btn-sm btn-outline-primary"
                title="Edit"
                aria-label={`Edit ${labelOf(row)}`}
                data-id="hilos-impersonation-scope-edit"
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
                data-id="hilos-impersonation-scope-save"
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
                <div className="form-label fs-6">{labelOf(editRow)}</div>
                <HilosHiddenMark />
              </div>
            ) : (
              <fieldset aria-describedby="hilos-impersonation-scope-hint">
                <legend className="form-label fs-6">{labelOf(editRow)}</legend>
                {HILOS_IMPERSONATION_SCOPE_VALUES.map((value) => (
                  <div key={value} className="form-check">
                    <input
                      id={`hilos-impersonation-scope-${value}-field`}
                      className="form-check-input"
                      type="radio"
                      name="hilos-impersonation-scope"
                      value={value}
                      checked={editScope === value}
                      data-id={`hilos-impersonation-scope-${value}`}
                      data-autofocus
                      onChange={() => setEditScope(value)}
                    />
                    <label
                      className="form-check-label"
                      htmlFor={`hilos-impersonation-scope-${value}-field`}
                    >
                      {HILOS_IMPERSONATION_SCOPE_COPY[value]}
                    </label>
                  </div>
                ))}
              </fieldset>
            )}
            <p id="hilos-impersonation-scope-hint" className="form-text mb-0">
              {HILOS_IMPERSONATION_SCOPE_HINT}
            </p>
            <HilosEditNotice
              kind={editNotice}
              text={editNoticeText}
              dataId="hilos-impersonation-scope-notice"
            />
          </form>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
