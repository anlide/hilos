import { Fragment, useCallback, useLayoutEffect, useRef, useState } from 'react'
import { HILOS_VIEW_MODE_COPY, type HilosUploadField } from '@hilos/core/base'
import {
  HilosActionError,
  HilosModal,
  HilosUploadDrop,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
  type HilosUploadPickerProps,
} from '@hilos/react/base'

import { kindText } from '../../catalog/catalog.js'
import {
  CatalogPictureField,
  useCatalogPictureField,
} from '../../catalog/CatalogPictureField.js'
import { moneyFieldLabel } from '../../money/money.js'
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
  type ProductKind,
} from './productAddDraft.js'

const ID_PREFIX = 'admin-products-add'
const FORM_ID = `${ID_PREFIX}-form`
const NAME_ID = `${ID_PREFIX}-name`
const CATEGORY_ID = `${ID_PREFIX}-category`
const KIND_HINT_ID = `${ID_PREFIX}-kind-hint`
const PRICE_ID = `${ID_PREFIX}-price`
const DESCRIPTION_ID = `${ID_PREFIX}-description`
const KIND_HINTS: Record<ProductKind, string> = {
  unique:
    'One of a kind: sold once, no quantity. You choose its warehouse on the product card.',
  stock: 'Has stock in warehouses; you set it on the product card.',
}
const KINDS: readonly ProductKind[] = ['unique', 'stock']

/**
 * Open the catalog chooser from the Add product's Upload button.
 *
 * @param props The same field, id and filter that the default chooser uses.
 */
function ProductPictureChooser({
  field,
  dataId,
  accept,
}: HilosUploadPickerProps) {
  const disabled = useSignal(field.disabled)
  const input = useRef<HTMLInputElement>(null)

  return (
    <>
      <button
        type="button"
        className="btn btn-sm btn-outline-secondary flex-shrink-0"
        disabled={disabled}
        data-id={dataId}
        onClick={() => input.current?.click()}
      >
        <i className="bi bi-upload me-1" aria-hidden="true"></i>
        Upload
      </button>
      <input
        ref={input}
        type="file"
        multiple
        className="d-none"
        accept={accept}
        disabled={disabled}
        data-id={`${dataId}-input`}
        onChange={(event) => {
          const chooser = event.currentTarget
          field.add(chooser.files ?? [])
          chooser.value = ''
        }}
      />
    </>
  )
}

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
          <div className="mb-3">
            <label className="form-label" htmlFor={NAME_ID}>
              Name
            </label>
            <input
              id={NAME_ID}
              type="text"
              className="form-control"
              value={draft.name}
              required
              data-autofocus
              onChange={(event) => {
                const name = event.target.value
                setDraft((current) => ({ ...current, name }))
              }}
            />
          </div>
          <div className="mb-3">
            <label className="form-label" htmlFor={CATEGORY_ID}>
              Category
            </label>
            <select
              id={CATEGORY_ID}
              className="form-select"
              value={categoryAvailable ? (categoryId ?? '') : ''}
              required
              onChange={(event) => {
                const value = event.target.value
                setCategoryRemoved(false)
                setDraft((current) => ({
                  ...current,
                  categoryId: value === '' ? null : Number(value),
                }))
              }}
            >
              <option value="" disabled>
                {categoryRemoved
                  ? 'Choose a category again'
                  : 'Choose a category'}
              </option>
              {categories.map(({ value, label }) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <fieldset className="mb-3" aria-describedby={KIND_HINT_ID}>
            <legend className="form-label fs-6 float-none">Kind</legend>
            <div className="btn-group flex-wrap">
              {KINDS.map((kind) => {
                const id = `${ID_PREFIX}-${kind}`
                return (
                  <Fragment key={kind}>
                    <input
                      id={id}
                      name="kind"
                      type="radio"
                      className="btn-check"
                      value={kind}
                      checked={draft.kind === kind}
                      onChange={() =>
                        setDraft((current) => ({ ...current, kind }))
                      }
                    />
                    <label
                      className="btn btn-outline-secondary btn-sm"
                      htmlFor={id}
                    >
                      {kindText(kind)}
                    </label>
                  </Fragment>
                )
              })}
            </div>
            <div id={KIND_HINT_ID} className="form-text">
              {KIND_HINTS[draft.kind]}
            </div>
          </fieldset>
          <div className="mb-3">
            <label className="form-label" htmlFor={PRICE_ID}>
              {moneyFieldLabel('Price')}
            </label>
            <input
              id={PRICE_ID}
              type="text"
              inputMode="decimal"
              className="form-control"
              value={draft.price}
              required
              onChange={(event) => {
                const price = event.target.value
                setDraft((current) => ({ ...current, price }))
              }}
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
              required
              onChange={(event) => {
                const description = event.target.value
                setDraft((current) => ({ ...current, description }))
              }}
            />
          </div>
          <CatalogPictureField
            field={field}
            publish={publish}
            sendPublish={publishPicture}
            idPrefix={ID_PREFIX}
            chooser={ProductPictureChooser}
            picture={draft.picture}
            onSelection={(clientUploadId) => {
              setSelectedUploadId(clientUploadId)
              setDraft((current) =>
                current.picture === null
                  ? current
                  : { ...current, picture: null },
              )
            }}
            onPicture={(picture) =>
              setDraft((current) => ({ ...current, picture }))
            }
            onPending={setPicturePending}
          />
        </form>
      </HilosUploadDrop>
    </HilosModal>
  )
}
