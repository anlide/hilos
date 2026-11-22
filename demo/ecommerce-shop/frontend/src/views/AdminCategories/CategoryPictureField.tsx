import { useCallback } from 'react'

import {
  CatalogPictureField,
  type CatalogPictureFieldProps,
} from '../../catalog/CatalogPictureField.js'
import { sendCategoryPicturePublish } from './adminCategoriesActions.js'

export { useCatalogPictureField } from '../../catalog/CatalogPictureField.js'

/** The shared field's category binding, including its add and edit id prefixes. */
export type CategoryPictureFieldProps = Omit<
  CatalogPictureFieldProps,
  'chooser' | 'sendPublish'
> & {
  /** Switch the dialog's refusal slot to publication at dispatch. */
  readonly onPublish: () => void
}

/**
 * Draw the category picture field with the category publication action.
 *
 * @param props The shared field's props and the dialog's publication callback.
 */
export function CategoryPictureField({
  onPublish,
  ...props
}: CategoryPictureFieldProps) {
  const sendPublish = useCallback(
    (clientUploadId: string) => {
      onPublish()
      return sendCategoryPicturePublish(clientUploadId)
    },
    [onPublish],
  )

  return <CatalogPictureField {...props} sendPublish={sendPublish} />
}
