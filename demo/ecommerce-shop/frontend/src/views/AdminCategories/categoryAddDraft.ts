// The shared category form and the add dialog's draft checks. Read the raw
// values: trimming and length limits are the server's.

import { type CatalogPicture } from '../../catalog/catalogPictureReply.js'

/** The form shown by Add category and Edit category. */
export interface CategoryForm {
  /** The category name, as typed. */
  readonly name: string
  /** The category description, as typed. */
  readonly description: string
  /** The saved or newly published picture, or null before there is one. */
  readonly picture: CatalogPicture | null
}

/** The draft every opening starts from: nothing typed, no picture. */
export const EMPTY_CATEGORY_DRAFT: CategoryForm = {
  name: '',
  description: '',
  picture: null,
}

/**
 * Whether both text fields hold a value.
 *
 * @param draft The category form.
 */
export function hasCategoryText(draft: CategoryForm): boolean {
  return draft.name !== '' && draft.description !== ''
}

/**
 * Whether the draft may be sent: both texts hold something, a picture is
 * published, and no picture is on its way. The Add button and Enter share this
 * verdict.
 *
 * @param draft The draft the dialog holds.
 * @param pending Whether a picture is still uploading or being published.
 */
export function canAddCategory(draft: CategoryForm, pending: boolean): boolean {
  return hasCategoryText(draft) && draft.picture !== null && !pending
}

/**
 * Whether closing the dialog would lose work: either text holds something, or a
 * picture is published.
 *
 * @param draft The draft the dialog holds.
 */
export function isCategoryDraftDirty(draft: CategoryForm): boolean {
  return draft.name !== '' || draft.description !== '' || draft.picture !== null
}
