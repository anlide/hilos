import { type CatalogPicture } from '../../catalog/catalogPictureReply.js'

/** The kinds the product creation action accepts. */
export type ProductKind = 'unique' | 'stock'

/** What the dialog holds before the server assigns a product id. */
export interface ProductAddDraft {
  readonly name: string
  readonly categoryId: number | null
  readonly kind: ProductKind
  readonly price: string
  readonly description: string
  readonly picture: CatalogPicture | null
}

/** The clean draft for a new opening. */
export const EMPTY_PRODUCT_DRAFT: ProductAddDraft = {
  name: '',
  categoryId: null,
  kind: 'unique',
  price: '',
  description: '',
  picture: null,
}

/** When the form can dispatch its tracked create. */
export interface ProductAddStatus {
  readonly categoryAvailable: boolean
  readonly picturePending: boolean
  readonly submitting: boolean
  readonly lookOnly: boolean
}

/** The payload of ProductCreateActionDTO. */
export interface ProductCreatePayload {
  readonly name: string
  readonly categoryId: number
  readonly kind: ProductKind
  readonly price: string
  readonly description: string
  readonly pictureFileId: number
}

/**
 * The single verdict for Add and Enter, with the ready-to-send payload.
 * The server parses the raw price and validates the form.
 *
 * @param draft The dialog's raw values.
 * @param status The live choice, action state and permissions.
 */
export function productCreatePayload(
  draft: ProductAddDraft,
  status: ProductAddStatus,
): ProductCreatePayload | null {
  const { name, categoryId, kind, price, description, picture } = draft
  if (
    name === '' ||
    categoryId === null ||
    price === '' ||
    description === '' ||
    picture === null ||
    !status.categoryAvailable ||
    status.picturePending ||
    status.submitting ||
    status.lookOnly
  ) {
    return null
  }

  return {
    name,
    categoryId,
    kind,
    price,
    description,
    pictureFileId: picture.fileId,
  }
}

/**
 * Whether closing the dialog would lose work.
 *
 * @param draft The values the dialog holds.
 */
export function isProductDraftDirty(draft: ProductAddDraft): boolean {
  return (
    draft.name !== '' ||
    draft.categoryId !== null ||
    draft.kind !== EMPTY_PRODUCT_DRAFT.kind ||
    draft.price !== '' ||
    draft.description !== '' ||
    draft.picture !== null
  )
}
