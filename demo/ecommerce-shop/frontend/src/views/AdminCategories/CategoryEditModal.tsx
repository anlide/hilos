import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import { focusInitial, type HilosUploadField } from '@hilos/core/base'
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
const FORM_ID = 'admin-categories-edit-form'

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
  const { locked } = useLookOnly()
  const publish = useTrackedAction()
  const save = useTrackedAction()
  const [pending, setPending] = useState(false)
  const [lastRun, setLastRun] = useState<'publish' | 'save'>('save')
  const selectedUpload = useRef<string | null>(null)
  const focusedSave = useRef<HTMLButtonElement>(null)
  const onPublish = useCallback(() => setLastRun('publish'), [])
  const picturePending = pending || publish.busy
  const disableSave = !saving && !canSubmit(canSave)
  const [saveDisabled, setSaveDisabled] = useState(disableSave)

  // A live change can disable Save while focus is on it.
  useLayoutEffect(() => {
    if (disableSave === saveDisabled) return
    const button = focusedSave.current
    if (
      disableSave &&
      button &&
      button.ownerDocument.activeElement === button
    ) {
      const dialog = button.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    }
    setSaveDisabled(disableSave)
  }, [disableSave, saveDisabled])

  function canSubmit(sessionCanSave = edit.canSave.get()): boolean {
    return canSubmitCategoryEdit({
      canSave: sessionCanSave,
      locked,
      picturePending,
      uploadBusy: field.busy.get(),
      selectedUploadId: selectedUpload.current,
      readyUploads: field.ready.get(),
    })
  }

  async function submit(): Promise<void> {
    if (!canSubmit()) return
    setLastRun('save')
    field.setDisabled(true)
    try {
      await edit.save(save.run)
    } finally {
      field.setDisabled(false)
    }
  }

  function takeTheirs(): void {
    const uploadId = selectedUpload.current
    if (edit.state.get().fields.pictureFileId?.conflict && uploadId !== null) {
      // The publication can still answer after the choice discarded its draft.
      selectedUpload.current = null
      field.remove(uploadId)
    }
    edit.takeTheirs()
  }

  function close(): void {
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
              data-id="admin-categories-edit-cancel"
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
              data-id="admin-categories-edit-submit"
              onFocus={(event) => {
                focusedSave.current = event.currentTarget
              }}
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <HilosUploadDrop field={field} dataId="admin-categories-edit-drop">
        <div data-id="admin-categories-edit">
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
              idPrefix="admin-categories-edit"
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
