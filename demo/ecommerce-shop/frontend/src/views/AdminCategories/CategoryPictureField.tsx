import {
  CatalogPictureField,
  type CatalogPictureFieldProps,
} from '../../catalog/CatalogPictureField.js'
import { sendCategoryPicturePublish } from './adminCategoriesActions.js'

export { useCatalogPictureField } from '../../catalog/CatalogPictureField.js'

/** The shared field's category binding, including its add and edit id prefixes. */
export type CategoryPictureFieldProps = Omit<
  CatalogPictureFieldProps,
  'chooser' | 'idPrefix' | 'onSelection' | 'sendPublish'
> & { readonly idPrefix?: string }

/**
 * Draw the category picture field with the category publication action.
 *
 * @param props The shared field's props and the category dialog's id prefix.
 */
export function CategoryPictureField({
  idPrefix = 'admin-categories-add',
  ...props
}: CategoryPictureFieldProps) {
  return (
    <CatalogPictureField
      {...props}
      idPrefix={idPrefix}
      sendPublish={sendCategoryPicturePublish}
    />
  )
}
