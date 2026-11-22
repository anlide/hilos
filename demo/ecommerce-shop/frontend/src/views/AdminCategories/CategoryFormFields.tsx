import { type CategoryForm } from './categoryAddDraft.js'
import {
  CategoryPictureField,
  type CategoryPictureFieldProps,
} from './CategoryPictureField.js'

interface CategoryFormFieldsProps {
  readonly idPrefix: 'admin-categories-add' | 'admin-categories-edit'
  readonly draft: CategoryForm
  readonly onChange: (patch: Partial<CategoryForm>) => void
  readonly pictureField: Omit<CategoryPictureFieldProps, 'idPrefix' | 'picture'>
}

/**
 * The name, description and picture shown by both category dialogs.
 *
 * @param props The dialog's prefix, draft, and field handlers.
 */
export function CategoryFormFields({
  idPrefix,
  draft,
  onChange,
  pictureField,
}: CategoryFormFieldsProps) {
  const nameId = `${idPrefix}-name`
  const descriptionId = `${idPrefix}-description`
  const descriptionHintId = `${descriptionId}-hint`

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
          data-id={nameId}
          data-autofocus
          onChange={(event) => onChange({ name: event.target.value })}
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
          aria-describedby={descriptionHintId}
          data-id={descriptionId}
          onChange={(event) => onChange({ description: event.target.value })}
        />
        <div id={descriptionHintId} className="form-text">
          Buyers see it under the name on the home page.
        </div>
      </div>
      <CategoryPictureField
        {...pictureField}
        idPrefix={idPrefix}
        picture={draft.picture}
      />
    </>
  )
}
