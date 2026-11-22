// The Add category draft: what the modal holds between opening and the backend's
// answer, and the two verdicts read from it. Pure — no connection, so the unit
// test reads it in node. Both verdicts go by the raw value: trimming and the
// length limits are the server's (rules-and-violations.md, section E).

/** A picture already published for the draft: its file and its preview copy. */
export interface CategoryAddPicture {
  /** The published file the category is created with. */
  readonly fileId: number
  /** Address of the file's catalog_sm copy, drawn as the preview. */
  readonly url: string
}

/** The fields an Add category dialog collects. */
export interface CategoryAddDraft {
  /** The category name, as typed. */
  readonly name: string
  /** The category description, as typed. */
  readonly description: string
  /** The picture published in this opening, or null before the first one. */
  readonly picture: CategoryAddPicture | null
}

/** The draft every opening starts from: nothing typed, no picture. */
export const EMPTY_CATEGORY_DRAFT: CategoryAddDraft = {
  name: '',
  description: '',
  picture: null,
}

/**
 * Whether the draft may be sent: both texts hold something, a picture is
 * published, and no picture is on its way. The Add button and Enter share this
 * verdict.
 *
 * @param draft The draft the dialog holds.
 * @param pending Whether a picture is still uploading or being published.
 */
export function canAddCategory(
  draft: CategoryAddDraft,
  pending: boolean,
): boolean {
  return (
    draft.name !== '' &&
    draft.description !== '' &&
    draft.picture !== null &&
    !pending
  )
}

/**
 * Whether closing the dialog would lose work: either text holds something, or a
 * picture is published.
 *
 * @param draft The draft the dialog holds.
 */
export function isCategoryDraftDirty(draft: CategoryAddDraft): boolean {
  return draft.name !== '' || draft.description !== '' || draft.picture !== null
}
