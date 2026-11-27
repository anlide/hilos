// The Edit warehouse dialog: the Add fields filled with the row the pencil
// opened, over the core row-edit session — the conflict with Keep mine / Take
// theirs, "Updated just now" and "Deleted" are the session's, and the window
// keeps no edit store of its own. The driver toasts the outcome; a refusal is
// also drawn above the fields, so the dialog stays open with what was typed.
// The parent mounts one window per opening, so an earlier refusal never carries
// into the next one. Bootstrap classes only (styling-rules.md).
import { useLayoutEffect, useRef, useState } from 'react'
import { focusInitial, registerFocusReturnFallback } from '@hilos/core/base'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { type WarehouseEdit } from './warehouseEdit.js'
import { WarehouseFormFields } from './WarehouseFormFields.js'

const FORM_ID = 'admin-warehouses-edit-form'

/**
 * Park focus before this layer's Cancel or Close becomes natively disabled.
 *
 * @param cancel The active layer's Cancel button.
 */
function parkClosingControl(cancel: HTMLButtonElement | null): void {
  if (!cancel) return
  const dialog = cancel.closest<HTMLElement>('[role="dialog"]')
  const focused = cancel.ownerDocument.activeElement
  if (
    dialog &&
    focused?.closest('[role="dialog"]') === dialog &&
    (focused === cancel || focused.matches('[data-id="modal-close"]'))
  ) {
    dialog.focus({ preventScroll: true })
  }
}

/**
 * One opening of Edit warehouse, sharing the session's live row and draft until
 * the backend answers.
 *
 * @param edit The session over the warehouses table.
 */
export function WarehouseEditModal({ edit }: { edit: WarehouseEdit }) {
  const opened = useSignal(edit.opened)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const editBody = useRef<HTMLDivElement>(null)
  const editSave = useRef<HTMLButtonElement>(null)
  const editCancel = useRef<HTMLButtonElement>(null)
  const [prevSaveAvailable, setPrevSaveAvailable] = useState(saveAvailable)

  useLayoutEffect(() => {
    const root = editBody.current
    if (!root) return
    return registerFocusReturnFallback(root, () =>
      root.querySelector<HTMLElement>('[data-autofocus]'),
    )
  }, [opened])

  // Apply native disabling before paint, after a focused action has had a
  // chance to hand off focus. ARIA marks the live verdict for the closing trap.
  useLayoutEffect(() => {
    if (prevSaveAvailable === saveAvailable) return
    const root = editBody.current
    const focused = root?.ownerDocument.activeElement
    const dialog = root?.closest<HTMLElement>('[role="dialog"]')
    if (dialog && focused && !saveAvailable && focused === editSave.current) {
      focusInitial(dialog)
    }
    setPrevSaveAvailable(saveAvailable)
  }, [prevSaveAvailable, saveAvailable])

  // Save and Enter go through the session's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with what was typed.
  async function submit(): Promise<void> {
    if (locked || !edit.canSave.get()) return
    parkClosingControl(editCancel.current)
    await edit.save(action.run)
  }

  function closeEdit(): void {
    if (!edit.saving.get()) edit.close()
  }

  return (
    <HilosModal
      open={opened}
      initialFocus="inner"
      onClose={closeEdit}
      confirmOnClose={state.dirty}
      busy={saving}
      header={
        <ConflictHeader title="Edit warehouse" conflict={state.conflict} />
      }
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          cancelButton={
            <button
              ref={editCancel}
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="admin-warehouses-edit-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          // A submit button of the form: with two fields, Enter submits only
          // through one. The session's verdict includes conflict; keep the
          // native attribute staged even while the live choices are shown.
          saveButton={() => (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={action.busy}
              disabled={!prevSaveAvailable}
              aria-disabled={!saveAvailable || undefined}
              data-id="admin-warehouses-edit-submit"
              onFocus={(event) => {
                editSave.current = event.currentTarget
              }}
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <div ref={editBody} data-id="admin-warehouses-edit">
        <div data-id="admin-warehouses-edit-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't save the warehouse"
          />
        </div>
        <form
          id={FORM_ID}
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <WarehouseFormFields
            idPrefix="admin-warehouses-edit"
            draft={form}
            onChange={edit.patchForm}
          />
          <HilosEditNotice
            kind={state.notice?.kind ?? null}
            text={noticeText}
            dataId="admin-warehouses-edit-notice"
          />
        </form>
      </div>
    </HilosModal>
  )
}
