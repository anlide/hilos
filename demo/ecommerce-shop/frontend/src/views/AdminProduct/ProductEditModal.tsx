import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import {
  HILOS_VIEW_MODE_COPY,
  subscribeSignal,
  type HilosUploadField,
  type ReadonlySignal,
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

import { currentUserId } from '../../bootstrap/session.js'
import { useCatalogPictureField } from '../../catalog/CatalogPictureField.js'
import { type CatalogPicture } from '../../catalog/catalogPictureReply.js'
import { ProductFormFields } from '../../catalog/ProductFormFields.js'
import { sendProductPicturePublish } from '../AdminProducts/adminProductsActions.js'
import { type AdminProductEdit } from './adminProductEdit.js'

const ID_PREFIX = 'admin-product-edit'
const FORM_ID = `${ID_PREFIX}-form`
const TITLE = 'Edit product'
const UNKNOWN_PUBLICATION =
  'Picture publication outcome is unknown. Choose the file again to retry.'

interface ProductEditModalProps {
  readonly edit: AdminProductEdit
  readonly categories: ReadonlySignal<
    readonly { value: number; label: string }[]
  >
}

interface PicturePublication {
  readonly clientUploadId: string
  readonly picture?: CatalogPicture
}

/**
 * One opening of Edit product, with its own upload field and action trackers.
 *
 * @param props The open edit and the card's live category list.
 */
export function ProductEditModal(props: ProductEditModalProps) {
  const field = useCatalogPictureField()

  return field !== null ? <ProductEditDialog {...props} field={field} /> : null
}

/**
 * Draw the open edit and run its tracked actions.
 *
 * @param props The open edit, live category list, and this opening's upload.
 */
function ProductEditDialog({
  edit,
  categories,
  field,
}: ProductEditModalProps & { readonly field: HilosUploadField }) {
  const opening = useSignal(edit.row)
  const draft = useSignal(edit.form)
  const state = useSignal(edit.state)
  const notice = useSignal(edit.noticeText)
  const mergeable = useSignal(edit.mergeable)
  const saving = useSignal(edit.saving)
  const canSave = useSignal(edit.canSave)
  const saveLabel = useSignal(edit.saveLabel)
  const picturePending = useSignal(edit.picturePending)
  const choices = useSignal(categories)
  const lastChoices = useRef(categories.get())
  const { locked, describedBy } = useLookOnly()
  const save = useTrackedAction({ toast: false })
  const publish = useTrackedAction({ toast: false })
  const [selectedUpload, setSelectedUpload] = useState<string | null>(null)
  const [publishingUpload, setPublishingUpload] = useState<string | null>(null)
  const [unknownUpload, setUnknownUpload] = useState<string | null>(null)
  const publication = useRef<PicturePublication | null>(null)
  const fieldPending = useRef(false)
  const categoryAvailable = choices.some(
    ({ value }) => value === draft.categoryId,
  )
  const categoryOnDeletion =
    state.gone && draft.categoryId !== null
      ? (lastChoices.current.find(({ value }) => value === draft.categoryId)
          ?.label ?? String(draft.categoryId))
      : undefined

  useLayoutEffect(() => {
    // The refusal drops the page scope and the list it held.
    return subscribeSignal(categories, (current) => {
      if (current.length > 0) lastChoices.current = current
    })
  }, [categories])

  // The reply can outlive the temporary upload record and the socket it used.
  // This opening keeps it while the subscription owes a fresh card.
  const syncPublication = useCallback(() => {
    const access = locked ? 'discard' : edit.picturePublicationAccess.get()
    field.setDisabled(access !== 'apply')
    const picture = publication.current?.picture
    if (access === 'discard') {
      publication.current = null
    } else if (access === 'apply' && picture !== undefined) {
      publication.current = null
      edit.replacePicture(picture)
    }
    edit.setPicturePending(
      fieldPending.current || publication.current?.picture !== undefined,
    )
  }, [edit, field, locked])

  useLayoutEffect(() => {
    syncPublication()
    const stopAccess = subscribeSignal(
      edit.picturePublicationAccess,
      syncPublication,
    )
    const stopUser = subscribeSignal(currentUserId, () => {
      publication.current = null
      syncPublication()
    })
    return () => {
      stopAccess()
      stopUser()
      publication.current = null
    }
  }, [edit, syncPublication])

  const publishPicture = useCallback((clientUploadId: string) => {
    setPublishingUpload(clientUploadId)
    publication.current = { clientUploadId }
    return sendProductPicturePublish(clientUploadId)
  }, [])

  async function submit(): Promise<void> {
    if (locked || !edit.canSave.get()) return
    setPublishingUpload(null)
    await edit.save(save.run)
  }

  const showPublishError = publishingUpload !== null
  const publicationUnknown =
    showPublishError && unknownUpload === publishingUpload
  const publishError = publicationUnknown
    ? { ...publish, error: UNKNOWN_PUBLICATION }
    : publish
  return (
    <HilosModal
      open
      size="wide"
      title={TITLE}
      initialFocus="inner"
      onClose={edit.close}
      confirmOnClose={state.dirty || picturePending}
      busy={saving}
      header={<ConflictHeader title={TITLE} conflict={state.conflict} />}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          mergeable={mergeable}
          disableSave={!canSave && !saving}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          onMerge={edit.merge}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="admin-product-edit-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          saveButton={({ disabled }) =>
            locked && !state.gone ? (
              <span
                aria-describedby={describedBy}
                className="badge bg-secondary-subtle text-secondary-emphasis"
                data-id="admin-product-edit-view-mode"
              >
                {HILOS_VIEW_MODE_COPY.mark}
              </span>
            ) : (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form={FORM_ID}
                loading={saving}
                disabled={disabled}
                data-id="admin-product-edit-submit"
              >
                {saveLabel}
              </LoadingButton>
            )
          }
        />
      )}
    >
      <HilosUploadDrop field={field} dataId="admin-product-edit-drop">
        <div data-id="admin-product-edit-error">
          <HilosActionError
            action={showPublishError ? publishError : save}
            suppressed={
              showPublishError &&
              !publicationUnknown &&
              publishingUpload !== selectedUpload
            }
            detailsTitle={
              showPublishError
                ? "Couldn't publish the picture"
                : "Couldn't save the product"
            }
          />
        </div>
        <form
          id={FORM_ID}
          data-id="admin-product-edit"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <ProductFormFields
            idPrefix={ID_PREFIX}
            draft={draft}
            onDraftChange={edit.patchForm}
            readOnly={locked || state.gone}
            categories={choices}
            categoryAvailable={categoryAvailable}
            categoryRemoved={draft.categoryId !== null && !categoryAvailable}
            categoryOnDeletion={categoryOnDeletion}
            kind={opening?.kind ?? ''}
            pictureField={{
              field,
              publish,
              sendPublish: publishPicture,
              picture: { fileId: draft.pictureFileId, url: draft.pictureUrl },
              onSelection: (clientUploadId) => {
                publication.current = null
                if (clientUploadId !== null) fieldPending.current = true
                syncPublication()
                setSelectedUpload(clientUploadId)
                setUnknownUpload(null)
              },
              onPicture: (picture, clientUploadId) => {
                if (publication.current?.clientUploadId === clientUploadId) {
                  publication.current = { clientUploadId, picture }
                  syncPublication()
                }
              },
              onPublicationUnknown: (clientUploadId) => {
                if (publication.current?.clientUploadId !== clientUploadId)
                  return
                publication.current = null
                setSelectedUpload(null)
                setUnknownUpload(clientUploadId)
              },
              onPending: (pending) => {
                fieldPending.current = pending
                syncPublication()
              },
            }}
          />
          <HilosEditNotice
            kind={state.notice?.kind ?? null}
            text={notice}
            dataId="admin-product-edit-notice"
          />
        </form>
      </HilosUploadDrop>
    </HilosModal>
  )
}
