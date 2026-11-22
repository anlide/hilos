import { Fragment, useRef } from 'react'
import { useSignal, type HilosUploadPickerProps } from '@hilos/react/base'

import { moneyFieldLabel } from '../money/money.js'
import { kindText } from './catalog.js'
import {
  CatalogPictureField,
  type CatalogPictureFieldProps,
} from './CatalogPictureField.js'

const KINDS = ['unique', 'stock'] as const
type SelectableKind = (typeof KINDS)[number]

const KIND_HINTS: Record<SelectableKind, string> = {
  unique:
    'One of a kind: sold once, no quantity. You choose its warehouse on the product card.',
  stock: 'Has stock in warehouses; you set it on the product card.',
}

interface ProductFormDraft {
  readonly name: string
  readonly categoryId: number | null
  readonly price: string
  readonly description: string
}

type ProductFormFieldsProps = {
  readonly idPrefix: string
  readonly draft: ProductFormDraft
  readonly onDraftChange: (change: Partial<ProductFormDraft>) => void
  readonly categories: readonly { value: number; label: string }[]
  readonly categoryAvailable: boolean
  readonly categoryRemoved: boolean
  readonly categoryOnDeletion?: string
  readonly pictureField: Omit<CatalogPictureFieldProps, 'idPrefix' | 'chooser'>
  readonly readOnly?: boolean
} & (
  | {
      readonly kind: SelectableKind
      readonly onKindChange: (kind: SelectableKind) => void
    }
  | {
      readonly kind: string
      /** Omit to display the kind as read-only text. */
      readonly onKindChange?: undefined
    }
)

/**
 * Open the catalog chooser from the product's Upload button.
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
 * Render product fields inside a dialog's form and focus Name on opening.
 *
 * @param props The dialog's draft, live choices, and picture controls.
 */
export function ProductFormFields({
  idPrefix,
  draft,
  onDraftChange,
  categories,
  categoryAvailable,
  categoryRemoved,
  categoryOnDeletion,
  kind,
  onKindChange,
  pictureField,
  readOnly = false,
}: ProductFormFieldsProps) {
  const nameId = `${idPrefix}-name`
  const categoryInputId = `${idPrefix}-category`
  const kindHintId = `${idPrefix}-kind-hint`
  const priceId = `${idPrefix}-price`
  const descriptionId = `${idPrefix}-description`

  return (
    <>
      <div className="mb-3">
        <label className="form-label" htmlFor={nameId}>
          Name
        </label>
        <input
          id={nameId}
          type="text"
          className="form-control"
          value={draft.name}
          required
          readOnly={readOnly}
          data-autofocus
          onChange={(event) => onDraftChange({ name: event.target.value })}
        />
      </div>
      <div className="mb-3">
        <label className="form-label" htmlFor={categoryInputId}>
          Category
        </label>
        {categoryOnDeletion === undefined ? (
          <select
            id={categoryInputId}
            className="form-select"
            value={categoryAvailable ? (draft.categoryId ?? '') : ''}
            required
            disabled={readOnly}
            onChange={(event) => {
              const value = event.target.value
              onDraftChange({ categoryId: value === '' ? null : Number(value) })
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
        ) : (
          <input
            id={categoryInputId}
            className="form-control"
            value={categoryOnDeletion}
            readOnly
          />
        )}
      </div>
      {onKindChange === undefined ? (
        <dl className="mb-3">
          <dt className="form-label fw-normal">Kind</dt>
          <dd className="mb-0">{kindText(kind)}</dd>
        </dl>
      ) : (
        <fieldset className="mb-3" aria-describedby={kindHintId}>
          <legend className="form-label fs-6 float-none">Kind</legend>
          <div className="btn-group flex-wrap">
            {KINDS.map((choice) => {
              const id = `${idPrefix}-${choice}`
              return (
                <Fragment key={choice}>
                  <input
                    id={id}
                    name="kind"
                    type="radio"
                    className="btn-check"
                    value={choice}
                    checked={kind === choice}
                    onChange={() => onKindChange(choice)}
                  />
                  <label
                    className="btn btn-outline-secondary btn-sm"
                    htmlFor={id}
                  >
                    {kindText(choice)}
                  </label>
                </Fragment>
              )
            })}
          </div>
          <div id={kindHintId} className="form-text">
            {KIND_HINTS[kind]}
          </div>
        </fieldset>
      )}
      <div className="mb-3">
        <label className="form-label" htmlFor={priceId}>
          {moneyFieldLabel('Price')}
        </label>
        <input
          id={priceId}
          type="text"
          inputMode="decimal"
          className="form-control"
          value={draft.price}
          required
          readOnly={readOnly}
          onChange={(event) => onDraftChange({ price: event.target.value })}
        />
      </div>
      <div className="mb-3">
        <label className="form-label" htmlFor={descriptionId}>
          Description
        </label>
        <textarea
          id={descriptionId}
          rows={2}
          className="form-control"
          value={draft.description}
          required
          readOnly={readOnly}
          onChange={(event) =>
            onDraftChange({ description: event.target.value })
          }
        />
      </div>
      <CatalogPictureField
        {...pictureField}
        idPrefix={idPrefix}
        chooser={ProductPictureChooser}
      />
    </>
  )
}
