// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the eight second-factor settings, one row
// each — whether the second factor is on at all and whether turning it off
// forgets the trusted browsers (HIL-1303), then who must use it, the days a
// device is trusted, the size of a set of backup codes, and the removal wait
// with its bounds. The two first rows carry a switch, as on the Impersonation
// tab; each other row shows its value in words and a pencil that opens a modal
// (the modal-only editing rule), and a value a setting's rule refuses stays in
// the modal with the refusal above it.
// A switch is a tracked action: a spinner on its own row while it flies, the
// other switch disabled, no toast on success, and the switch moves only when the
// table's row does; a refusal is the action's toast. The first switch, while on,
// does not move at all: it opens the window that turns the factor off — the
// operation's "Confirm it's you" first, then what turning it off does and "Turn
// off" (createHilosTwoFactorDisable, in the order of the account card's
// windows). While the factor is off every row below the first is dimmed, its
// pencil or its switch locked (the table's locked).
// The table, the row view-model, the edit round-trip, the window and the words
// are the core headless's; this view owns only the markup, so a project mounts
// it by passing its HilosTwoFactorContext. The modal holds its row in focus and
// merges against it through the shared row-edit helper (rowEdit.ts,
// conflict-resolution.md), saying what happened elsewhere on one line of room
// held in advance (HilosEditNotice). The screen is built from text: the
// mockup's node is a debt (D-115, and for the switches, the dimmed rows and the
// window D-234). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_TWO_FACTOR_DISABLE_COPY as DISABLE_COPY,
  HilosSecondFactorSettingKey,
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosTwoFactorDisable,
  createHilosTwoFactorSettingEdit,
  describeHilosSecondFactorSetting,
  isHilosSecondFactorSwitch,
} from '@hilos/core/admin/security'
import { HILOS_STEP_UP_COPY, HilosPages, focusInitial } from '@hilos/core/base'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
} from '@hilos/core/admin/security'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HilosStepUpStep } from '../../auth/HilosStepUpStep.js'
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
  // The window that turns the factor off, over the table; this view draws it.
  const disable = useMemo(
    () => createHilosTwoFactorDisable(context, settings.controller),
    [context, settings],
  )

  useEffect(() => {
    settings.start()
    editor.start()
    disable.start()

    return () => {
      disable.dispose()
      editor.dispose()
      settings.dispose()
    }
  }, [settings, editor, disable])

  const locked = useSignal(settings.locked)

  // One tracked runner for both switches: a single in-flight guard is enough,
  // and the busy flag disables the other switch while one write is settling.
  const toggle = useTrackedAction()
  const [pendingSwitchKey, setPendingSwitchKey] = useState<string | null>(null)

  const disableStep = useSignal(disable.step)
  const disableBusy = useSignal(disable.busy)
  const disableRefusal = useSignal(disable.refusal)
  const stepUpBusy = useSignal(disable.stepUp.busy)
  const stepUpRefusal = useSignal(disable.stepUp.refusal)
  const consequences = useSignal(disable.consequences)
  const disableOpen =
    disableStep === 'step-up' ||
    disableStep === 'consequences' ||
    disableStep === 'refused'
  const disableBody = useRef<HTMLDivElement>(null)

  // The step the window moves to takes the focus once it is drawn: the field
  // that held it is gone, and the modal places focus only when it opens.
  useEffect(() => {
    const dialog = disableBody.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  }, [disableStep])

  /**
   * Whether a row is locked: below the first while the factor is off.
   *
   * @param row The row of the setting.
   */
  function isLocked(row: HilosTwoFactorSettingRow): boolean {
    return locked.includes(row.rowKey)
  }

  // Turning the factor off opens its window and leaves the switch where it is;
  // anything else is the switch's own tracked write. Nothing is set
  // optimistically: the switch follows the row, which moves when the setting is
  // written.
  async function toggleSwitch(
    row: HilosTwoFactorSettingRow,
    next: boolean,
  ): Promise<void> {
    if (row.rowKey === HilosSecondFactorSettingKey.enabled && !next) {
      void disable.open()

      return
    }
    setPendingSwitchKey(row.rowKey)
    try {
      await toggle.run(actions.switchSet(row.rowKey, next))
    } finally {
      setPendingSwitchKey(null)
    }
  }

  const editOpen = useSignal(editor.opened)
  const editSaving = useSignal(editor.saving)
  const editRow = useSignal(editor.row)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const saveAvailable = useSignal(editor.saveAvailable)
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
            <div className={isLocked(row) ? 'opacity-50' : undefined}>
              <div className="fw-semibold">{labelOf(row)}</div>
              <div className="small text-body-secondary">
                {HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.hint}
              </div>
            </div>
          ),
          value: (row) =>
            isHilosSecondFactorSwitch(row.rowKey) ? (
              <HilosHideable value={row.enabled}>
                {(value) => (
                  <HilosSwitch
                    className="mb-0"
                    checked={value}
                    busy={
                      pendingSwitchKey === row.rowKey ||
                      (row.rowKey === HilosSecondFactorSettingKey.enabled &&
                        disableStep === 'opening')
                    }
                    disabled={toggle.busy || isLocked(row)}
                    aria-label={labelOf(row)}
                    dataId={`hilos-2fa-switch-${row.rowKey}`}
                    onToggle={(next) => void toggleSwitch(row, next)}
                  />
                )}
              </HilosHideable>
            ) : (
              <span
                className={isLocked(row) ? 'opacity-50' : undefined}
                data-id={`hilos-2fa-value-${row.rowKey}`}
              >
                <HilosHideable value={row.value}>
                  {(value) =>
                    describeHilosSecondFactorSetting(row.rowKey, value)
                  }
                </HilosHideable>
              </span>
            ),
          actions: (row) =>
            isHilosSecondFactorSwitch(row.rowKey) ? null : (
              <button
                type="button"
                className="btn btn-sm btn-outline-primary"
                title={`Edit ${labelOf(row)}`}
                aria-label={`Edit ${labelOf(row)}`}
                disabled={isLocked(row)}
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
        busy={editSaving}
        confirmOnClose={live.dirty}
        aria-label={editTitle}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!saveAvailable}
            saveLabel={editSaveLabel}
            onSave={() => void submitEdit()}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={editSaving}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.busy}
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

      <HilosModal
        open={disableOpen}
        onClose={() => disable.close()}
        title={
          disableStep === 'step-up'
            ? HILOS_STEP_UP_COPY.title
            : DISABLE_COPY.title
        }
        initialFocus="inner"
        busy={disableBusy}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={disableBusy}
              data-id="hilos-2fa-disable-cancel"
              onClick={requestClose}
            >
              {DISABLE_COPY.cancel}
            </button>
            {disableStep === 'step-up' ? (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form="hilos-2fa-disable-proof"
                loading={stepUpBusy}
                data-id="hilos-2fa-disable-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : disableStep === 'consequences' ? (
              <LoadingButton
                className="btn-danger"
                loading={disableBusy}
                data-id="hilos-2fa-disable-confirm"
                onClick={() => void disable.confirm()}
              >
                {DISABLE_COPY.confirm}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <div ref={disableBody} data-id="hilos-2fa-disable-window">
          <div className="visually-hidden" role="alert" aria-live="assertive">
            {disableStep === 'step-up' ? stepUpRefusal : disableRefusal}
          </div>
          {disableStep === 'step-up' ? (
            <form
              id="hilos-2fa-disable-proof"
              data-id="hilos-2fa-disable-step-up"
              onSubmit={(event) => {
                event.preventDefault()
                void disable.confirmStepUp()
              }}
            >
              <HilosStepUpStep controller={disable.stepUp} />
            </form>
          ) : (
            <>
              {disableStep === 'consequences' ? (
                <>
                  <p>{DISABLE_COPY.intro}</p>
                  <ul data-id="hilos-2fa-disable-consequences">
                    {consequences.map((line) => (
                      <li key={line}>{line}</li>
                    ))}
                  </ul>
                </>
              ) : null}
              <HilosFormError
                message={disableRefusal}
                dataId="hilos-2fa-disable-refusal"
              />
            </>
          )}
        </div>
      </HilosModal>
    </HilosAdminPage>
  )
}
