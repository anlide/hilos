// The Add category dialog: a name, a description and a picture. The picture is
// uploaded and published inside the dialog; Add sends the texts with the
// published file as one tracked action. The driver toasts the outcome; a
// refusal — of the publication or of the creation, whichever ran last — is also
// drawn above the fields, so the dialog stays open with what was typed and the
// picture. The whole body takes a dropped or pasted file. The parent mounts one
// window per opening, so an earlier refusal never carries into the next one.
// Bootstrap classes only (styling-rules.md).
import { useLayoutEffect, useState } from 'react'
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
  type CategoryAddDraft,
} from './categoryAddDraft.js'
import {
  CategoryPictureField,
  useCatalogPictureField,
} from './CategoryPictureField.js'

const FORM_ID = 'admin-categories-add-form'
const NAME_ID = 'admin-categories-add-name'
const DESCRIPTION_ID = 'admin-categories-add-description'
const DESCRIPTION_HINT_ID = 'admin-categories-add-description-hint'

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
  const [draft, setDraft] = useState<CategoryAddDraft>(EMPTY_CATEGORY_DRAFT)
  const [pending, setPending] = useState(false)
  const publish = useTrackedAction()
  const create = useTrackedAction()
  const { locked } = useLookOnly()
  // The refusal slot draws the action that ran last: a new picture after a
  // refused Add speaks for itself, and so does a new Add.
  const [lastRun, setLastRun] = useState<'publish' | 'create'>('create')

  useLayoutEffect(() => {
    if (publish.busy) {
      setLastRun('publish')
    }
  }, [publish.busy])
  useLayoutEffect(() => {
    if (create.busy) {
      setLastRun('create')
    }
  }, [create.busy])

  const canAdd = canAddCategory(draft, pending)

  async function submit(): Promise<void> {
    if (locked || !canAdd) return
    if (await create.run(sendCategoryCreate(draft))) {
      onClose()
    }
  }

  return (
    <HilosModal
      open
      title="Add category"
      onClose={onClose}
      confirmOnClose={isCategoryDraftDirty(draft)}
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
            <div className="mb-3">
              <label className="form-label" htmlFor={NAME_ID}>
                Name
              </label>
              <input
                id={NAME_ID}
                type="text"
                className="form-control"
                value={draft.name}
                data-id="admin-categories-add-name"
                data-autofocus
                onChange={(event) =>
                  setDraft((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
              />
            </div>
            <div className="mb-3">
              <label className="form-label" htmlFor={DESCRIPTION_ID}>
                Description
              </label>
              <textarea
                id={DESCRIPTION_ID}
                rows={2}
                className="form-control"
                value={draft.description}
                aria-describedby={DESCRIPTION_HINT_ID}
                data-id="admin-categories-add-description"
                onChange={(event) =>
                  setDraft((current) => ({
                    ...current,
                    description: event.target.value,
                  }))
                }
              />
              <div id={DESCRIPTION_HINT_ID} className="form-text">
                Buyers see it under the name on the home page.
              </div>
            </div>
            <CategoryPictureField
              field={field}
              publish={publish}
              picture={draft.picture}
              onPicture={(picture) =>
                setDraft((current) => ({ ...current, picture }))
              }
              onPending={setPending}
            />
          </form>
        </div>
      </HilosUploadDrop>
    </HilosModal>
  )
}
