// The Add category dialog: a name, a description and a picture. The picture is
// uploaded and published inside the dialog; Add sends the texts with the
// published file as one tracked action. The driver toasts the outcome; a
// refusal — of the publication or of the creation, whichever ran last — is also
// drawn above the fields, so the dialog stays open with what was typed and the
// picture. The whole body takes a dropped or pasted file. The parent mounts one
// window per opening, so an earlier refusal never carries into the next one.
// Bootstrap classes only (styling-rules.md).
import { useCallback, useRef, useState } from 'react'
import { type HilosUploadField } from '@hilos/core/base'
import {
  HilosActionError,
  HilosModal,
  HilosUploadDrop,
  LoadingButton,
  useLookOnly,
  useTrackedAction,
} from '@hilos/react/base'

import { sendCategoryCreate } from './adminCategoriesActions.js'
import {
  EMPTY_CATEGORY_DRAFT,
  canAddCategory,
  isCategoryDraftDirty,
} from './categoryAddDraft.js'
import { CategoryFormFields } from './CategoryFormFields.js'
import { useCatalogPictureField } from './CategoryPictureField.js'

const FORM_ID = 'admin-categories-add-form'

/**
 * One opening of the Add category dialog; it closes itself on the backend's
 * success.
 *
 * @param onClose Close the dialog — on Cancel, on dismissal and after success.
 */
export function CategoryAddModal({ onClose }: { onClose: () => void }) {
  const field = useCatalogPictureField()

  // The dialog opens with its field, so its first focus lands on a whole body.
  return field !== null ? (
    <CategoryAddDialog field={field} onClose={onClose} />
  ) : null
}

/**
 * The dialog itself, over the upload field of its opening.
 *
 * @param props The opening's upload field, and how the dialog closes.
 */
function CategoryAddDialog({
  field,
  onClose,
}: {
  field: HilosUploadField
  onClose: () => void
}) {
  const [draft, setDraft] = useState(EMPTY_CATEGORY_DRAFT)
  const [pending, setPending] = useState(false)
  const publish = useTrackedAction()
  const create = useTrackedAction()
  const submitting = useRef(false)
  const { locked } = useLookOnly()
  // The refusal slot draws the action that ran last: a new picture after a
  // refused Add speaks for itself, and so does a new Add.
  const [lastRun, setLastRun] = useState<'publish' | 'create'>('create')
  const onPublish = useCallback(() => setLastRun('publish'), [])

  const picturePending = pending || publish.busy
  const canAdd = canAddCategory(draft, picturePending)

  async function submit(): Promise<void> {
    if (locked || !canAdd || field.busy.get() || submitting.current) return
    submitting.current = true
    setLastRun('create')
    field.setDisabled(true)
    try {
      if (await create.run(sendCategoryCreate(draft))) {
        onClose()
      }
    } finally {
      field.setDisabled(false)
      submitting.current = false
    }
  }

  return (
    <HilosModal
      open
      title="Add category"
      initialFocus="inner"
      onClose={onClose}
      confirmOnClose={isCategoryDraftDirty(draft) || picturePending}
      busy={create.busy}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={create.busy}
            data-id="admin-categories-add-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className="btn-primary"
            type="submit"
            form={FORM_ID}
            loading={create.busy}
            disabled={!canAdd}
            data-id="admin-categories-add-submit"
          >
            Add
          </LoadingButton>
        </>
      )}
    >
      <HilosUploadDrop field={field} dataId="admin-categories-add-drop">
        <div data-id="admin-categories-add">
          <div data-id="admin-categories-add-error">
            <HilosActionError
              action={lastRun === 'publish' ? publish : create}
              detailsTitle="Couldn't add the category"
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
              idPrefix="admin-categories-add"
              draft={draft}
              onChange={(patch) =>
                setDraft((current) => ({ ...current, ...patch }))
              }
              pictureField={{
                field,
                publish,
                onPublish,
                onPicture: (picture) =>
                  setDraft((current) => ({ ...current, picture })),
                onPending: setPending,
              }}
            />
          </form>
        </div>
      </HilosUploadDrop>
    </HilosModal>
  )
}
