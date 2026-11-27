// One opening of the Edit category dialog: picture upload, name, and description.
// Closing without save prompts when there are changes or an upload in progress.
// The session's merge conflict is presented in the header and its choice
// buttons replace Save and Cancel. A server refusal is also drawn above the
// fields, so the dialog stays open with what was typed.
// The parent mounts one window per opening, so an earlier refusal never carries
// into the next one. Bootstrap classes only (styling-rules.md).
import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import {
  focusInitial,
  registerFocusReturnFallback,
  type HilosUploadField,
} from '@hilos/core/base'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  HilosUploadDrop,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { canSubmitCategoryEdit, type CategoryEdit } from './categoryEdit.js'
import { CategoryFormFields } from './CategoryFormFields.js'
import { useCatalogPictureField } from './CategoryPictureField.js'

const TITLE = 'Edit category'
const ID_PREFIX = 'admin-categories-edit'
const FORM_ID = 'admin-categories-edit-form'
const EDIT_CANCEL_ID = 'admin-categories-edit-cancel'
const SAVE_ID = 'admin-categories-edit-submit'

interface DialogButton {
  readonly dialog: HTMLElement
  readonly button: HTMLButtonElement
}

/** Read the focused button across native disable or removal in this dialog. */
function buttonLosingFocus(
  dialog: HTMLElement,
  focused: DialogButton | null,
): HTMLButtonElement | null {
  if (focused?.dialog !== dialog) return null
  const { button } = focused
  const { activeElement, body } = dialog.ownerDocument
  if (
    activeElement === button ||
    (activeElement === body &&
      (!button.isConnected || button.matches(':disabled')))
  ) {
    return button
  }

  return null
}

/** Park focus on the dialog before its Close and Cancel become disabled. */
function parkClosingButton(dialog: HTMLElement | null, cancelId: string): void {
  const active = dialog?.ownerDocument.activeElement
  const id = active?.getAttribute('data-id')
  if (
    dialog &&
    active?.closest('[role="dialog"]') === dialog &&
    (id === 'modal-close' || id === cancelId)
  ) {
    dialog.focus({ preventScroll: true })
  }
}

/**
 * One opening of Edit category, with its own picture upload field.
 *
 * @param props The session over the focused category row.
 */
export function CategoryEditModal({ edit }: { edit: CategoryEdit }) {
  const field = useCatalogPictureField()

  return field !== null ? (
    <CategoryEditDialog edit={edit} field={field} />
  ) : null
}

/**
 * The edit form over the upload field of its opening.
 *
 * @param props The row-edit session and the dialog's upload field.
 */
function CategoryEditDialog({
  edit,
  field,
}: {
  edit: CategoryEdit
  field: HilosUploadField
}) {
  const opened = useSignal(edit.opened)
  const draft = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const canSave = useSignal(edit.canSave)
  const saveLabel = useSignal(edit.saveLabel)
  // Render from the upload signals and read them again in each submit handler.
  useSignal(field.busy)
  useSignal(field.ready)
  const uploadId = useSignal(field.files)[0]?.clientUploadId
  const { locked } = useLookOnly()
  const publish = useTrackedAction()
  const save = useTrackedAction()
  const [pending, setPending] = useState(false)
  const [lastRun, setLastRun] = useState<'publish' | 'save'>('save')
  const selectedUpload = useRef<string | null>(null)
  const editBody = useRef<HTMLDivElement>(null)
  const focusedButton = useRef<DialogButton | null>(null)
  const picturePending = pending || publish.busy
  const onPublish = useCallback(() => setLastRun('publish'), [])
  const disableSave = !saving && !canSubmit(canSave)
  const savingWithConflict = saving && state.conflict
  const [saveDisabled, setSaveDisabled] = useState(disableSave)

  useLayoutEffect(() => {
    const root = editBody.current
    if (!root) return
    const document = root.ownerDocument
    const editDialog = root.closest('[role="dialog"]')
    const stopFallback = registerFocusReturnFallback(root, () =>
      root.querySelector<HTMLElement>('[data-autofocus]'),
    )

    // Keep the button's owner even if React removes the chip. Focus anywhere
    // else, including in Details or Discard, clears the previous button.
    function rememberFocus(event: FocusEvent): void {
      const button = event.target
      if (button === document.body && focusedButton.current) {
        const previous = focusedButton.current.button
        if (!previous.isConnected || previous.matches(':disabled')) return
      }
      if (!(button instanceof HTMLButtonElement)) {
        focusedButton.current = null
        return
      }
      const dialog = button.closest<HTMLElement>('[role="dialog"]')
      focusedButton.current =
        dialog && dialog === editDialog ? { button, dialog } : null
    }
    document.addEventListener('focusin', rememberFocus, true)
    return () => {
      document.removeEventListener('focusin', rememberFocus, true)
      focusedButton.current = null
      stopFallback()
    }
  }, [opened])

  // Reconcile chip removal and LoadingButton's native look-only lock, then
  // apply the local disabled states. A loading Save with live conflict choices
  // also hands off to the dialog so Tab starts from inside the available set.
  useLayoutEffect(() => {
    const dialog = editBody.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      const button = buttonLosingFocus(dialog, focusedButton.current)
      const id = button?.dataset.id
      const remove = id === `${ID_PREFIX}-upload-file-remove`
      const uploadDetails = id === `${ID_PREFIX}-upload-error-details`
      if (
        (id === SAVE_ID && (disableSave || locked || savingWithConflict)) ||
        (locked && !saving && (id === `${ID_PREFIX}-picture` || remove)) ||
        ((remove || uploadDetails) && !button?.isConnected)
      ) {
        focusInitial(dialog, saving ? 'dialog' : 'marked')
      }
    }
    field.setDisabled(locked || saving)
    setSaveDisabled(disableSave)
  }, [disableSave, field, locked, saving, savingWithConflict, uploadId])

  function readWriteState() {
    return {
      locked,
      picturePending,
      uploadBusy: field.busy.get(),
      selectedUploadId: selectedUpload.current,
      readyUploads: field.ready.get(),
    }
  }

  function canSubmit(sessionCanSave = edit.canSave.get()): boolean {
    return canSubmitCategoryEdit({
      ...readWriteState(),
      canSave: sessionCanSave,
    })
  }

  async function submit(): Promise<void> {
    if (!canSubmit()) return
    parkClosingButton(
      editBody.current?.closest<HTMLElement>('[role="dialog"]') ?? null,
      EDIT_CANCEL_ID,
    )
    setLastRun('save')
    await edit.save(save.run)
  }

  function takeTheirs(): void {
    const uploadId = selectedUpload.current
    if (edit.state.get().fields.pictureFileId?.conflict && uploadId !== null) {
      // The publication can still answer after the choice discarded its draft.
      selectedUpload.current = null
      field.discard(uploadId)
    }
    edit.takeTheirs()
  }

  function close(): void {
    if (edit.saving.get()) return
    selectedUpload.current = null
    edit.close()
  }

  return (
    <HilosModal
      open={opened}
      title={TITLE}
      initialFocus="inner"
      onClose={close}
      confirmOnClose={state.dirty || picturePending}
      busy={saving}
      header={<ConflictHeader title={TITLE} conflict={state.conflict} />}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          disableSave={disableSave}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={takeTheirs}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id={EDIT_CANCEL_ID}
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          saveButton={({ disabled }) => (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={saving}
              disabled={saveDisabled}
              aria-disabled={disabled || undefined}
              tabIndex={savingWithConflict ? -1 : undefined}
              data-id={SAVE_ID}
              onPointerDown={(event) => {
                if (savingWithConflict) event.preventDefault()
              }}
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <HilosUploadDrop field={field} dataId="admin-categories-edit-drop">
        <div ref={editBody} data-id="admin-categories-edit">
          <div data-id="admin-categories-edit-error">
            <HilosActionError
              action={lastRun === 'publish' ? publish : save}
              detailsTitle="Couldn't save the category"
            />
          </div>
          <form
            id={FORM_ID}
            onSubmit={(event) => {
              event.preventDefault()
              void submit()
            }}
          >
            <CategoryFormFields
              idPrefix={ID_PREFIX}
              draft={draft}
              onChange={edit.patchForm}
              pictureField={{
                field,
                publish,
                onPublish,
                onSelection: (id) => {
                  selectedUpload.current = id
                },
                onPicture: (picture, clientUploadId) => {
                  if (selectedUpload.current === clientUploadId) {
                    selectedUpload.current = null
                    edit.patchForm({ picture })
                  }
                },
                onPending: setPending,
              }}
            />
            <HilosEditNotice
              kind={state.notice?.kind ?? null}
              text={noticeText}
              dataId="admin-categories-edit-notice"
            />
          </form>
        </div>
      </HilosUploadDrop>
    </HilosModal>
  )
}
