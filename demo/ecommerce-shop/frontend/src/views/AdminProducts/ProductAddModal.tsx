import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import { HILOS_VIEW_MODE_COPY, type HilosUploadField } from '@hilos/core/base'
import {
  HilosActionError,
  HilosModal,
  HilosUploadDrop,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { useCatalogPictureField } from '../../catalog/CatalogPictureField.js'
import { ProductFormFields } from '../../catalog/ProductFormFields.js'
import {
  sendProductCreate,
  sendProductPicturePublish,
} from './adminProductsActions.js'
import { productCategoryChoices } from './adminProductsPage.js'
import {
  EMPTY_PRODUCT_DRAFT,
  isProductDraftDirty,
  productCreatePayload,
  type ProductAddDraft,
} from './productAddDraft.js'

const ID_PREFIX = 'admin-products-add'
const FORM_ID = `${ID_PREFIX}-form`

/**
 * One opening of the Add product dialog.
 *
 * @param props How the enclosing page closes the dialog.
 */
export function ProductAddModal({ onClose }: { onClose: () => void }) {
  const field = useCatalogPictureField()

  return field !== null ? (
    <ProductAddDialog field={field} onClose={onClose} />
  ) : null
}

/**
 * The dialog over the upload field owned by this opening.
 *
 * @param props The upload field and the enclosing page's close callback.
 */
function ProductAddDialog({
  field,
  onClose,
}: {
  field: HilosUploadField
  onClose: () => void
}) {
  const [draft, setDraft] = useState<ProductAddDraft>(EMPTY_PRODUCT_DRAFT)
  const [categoryRemoved, setCategoryRemoved] = useState(false)
  const [picturePending, setPicturePending] = useState(false)
  const [selectedUploadId, setSelectedUploadId] = useState<string | null>(null)
  const [publicationUploadId, setPublicationUploadId] = useState<string | null>(
    null,
  )
  const submitting = useRef(false)
  const { locked, describedBy } = useLookOnly()
  const categories = useSignal(productCategoryChoices)
  const publish = useTrackedAction({ toast: false })
  const create = useTrackedAction({ toast: false })
  const { categoryId } = draft
  const categoryAvailable = categories.some(({ value }) => value === categoryId)
  const publishPicture = useCallback((clientUploadId: string) => {
    setPublicationUploadId(clientUploadId)
    return sendProductPicturePublish(clientUploadId)
  }, [])

  useLayoutEffect(() => {
    return () => {
      submitting.current = false
    }
  }, [])

  useLayoutEffect(() => {
    if (categoryId !== null && !categoryAvailable) {
      setCategoryRemoved(true)
      setDraft((current) =>
        current.categoryId === categoryId
          ? { ...current, categoryId: null }
          : current,
      )
    }
  }, [categoryAvailable, categoryId])

  function submission() {
    return productCreatePayload(draft, {
      categoryAvailable: productCategoryChoices
        .get()
        .some(({ value }) => value === categoryId),
      picturePending: picturePending || field.busy.get(),
      submitting: submitting.current,
      lookOnly: locked,
    })
  }

  async function submit(): Promise<void> {
    const payload = submission()
    if (payload === null) return

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
    field.setDisabled(true)
    setPublicationUploadId(null)
    try {
      if (
        (await create.run(sendProductCreate(payload))) &&
        submitting.current
      ) {
        onClose()
      }
    } finally {
      if (submitting.current) {
        submitting.current = false
        field.setDisabled(false)
      }
    }
  }

  const canAdd = submission() !== null
  const showPublish = publicationUploadId !== null
  return (
    <HilosModal
      open
      size="wide"
      title="Add product"
      initialFocus="inner"
      onClose={onClose}
      confirmOnClose={isProductDraftDirty(draft) || picturePending}
      busy={create.busy}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={create.busy}
            data-id="admin-products-add-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          {locked ? (
            <span
              aria-describedby={describedBy}
              className="badge bg-secondary-subtle text-secondary-emphasis"
              data-id="admin-products-add-view-mode"
            >
              {HILOS_VIEW_MODE_COPY.mark}
            </span>
          ) : (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={create.busy}
              disabled={!canAdd && !create.busy}
              data-id="admin-products-add-submit"
            >
              Add
            </LoadingButton>
          )}
        </>
      )}
    >
      <HilosUploadDrop field={field} dataId="admin-products-add-drop">
        <div data-id="admin-products-add-error">
          <HilosActionError
            action={showPublish ? publish : create}
            suppressed={showPublish && publicationUploadId !== selectedUploadId}
            detailsTitle={
              showPublish
                ? "Couldn't publish the picture"
                : "Couldn't add the product"
            }
          />
        </div>
        <form
          id={FORM_ID}
          data-id="admin-products-add"
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <ProductFormFields
            idPrefix={ID_PREFIX}
            draft={draft}
            onDraftChange={(change) => {
              if (change.categoryId !== undefined) {
                setCategoryRemoved(false)
              }
              setDraft((current) => ({ ...current, ...change }))
            }}
            categories={categories}
            categoryAvailable={categoryAvailable}
            categoryRemoved={categoryRemoved}
            kind={draft.kind}
            onKindChange={(kind) =>
              setDraft((current) => ({ ...current, kind }))
            }
            pictureField={{
              field,
              publish,
              sendPublish: publishPicture,
              picture: draft.picture,
              onSelection: (clientUploadId) => {
                setSelectedUploadId(clientUploadId)
                setDraft((current) =>
                  current.picture === null
                    ? current
                    : { ...current, picture: null },
                )
              },
              onPicture: (picture) =>
                setDraft((current) => ({ ...current, picture })),
              onPending: setPicturePending,
            }}
          />
        </form>
      </HilosUploadDrop>
    </HilosModal>
  )
}
