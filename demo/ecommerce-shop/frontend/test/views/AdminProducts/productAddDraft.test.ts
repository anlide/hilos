import { describe, expect, it } from 'vitest'

import {
  EMPTY_PRODUCT_DRAFT,
  isProductDraftDirty,
  productCreatePayload,
  type ProductAddDraft,
  type ProductAddStatus,
} from '../../../src/views/AdminProducts/productAddDraft.js'

const DRAFT: ProductAddDraft = {
  ...EMPTY_PRODUCT_DRAFT,
  name: 'Dried flowers',
  categoryId: 2,
  price: '2 100.50',
  description: 'Bouquets and wreaths.',
  picture: { fileId: 7, url: '/files/7/catalog_sm' },
}

const READY: ProductAddStatus = {
  categoryAvailable: true,
  picturePending: false,
  submitting: false,
  lookOnly: false,
}

describe('product add draft', () => {
  it('starts clean and produces the create payload with the raw price', () => {
    expect(EMPTY_PRODUCT_DRAFT.categoryId).toBeNull()
    expect(EMPTY_PRODUCT_DRAFT.kind).toBe('unique')
    expect(isProductDraftDirty(EMPTY_PRODUCT_DRAFT)).toBe(false)

    const stockDraft = { ...DRAFT, kind: 'stock' } as const
    expect(isProductDraftDirty(stockDraft)).toBe(true)
    expect(productCreatePayload(stockDraft, READY)).toEqual({
      name: DRAFT.name,
      categoryId: DRAFT.categoryId,
      kind: 'stock',
      price: DRAFT.price,
      description: DRAFT.description,
      pictureFileId: DRAFT.picture?.fileId,
    })
  })

  it('withholds the payload while any required field is empty or the category is gone', () => {
    expect(productCreatePayload({ ...DRAFT, name: '' }, READY)).toBeNull()
    expect(
      productCreatePayload({ ...DRAFT, categoryId: null }, READY),
    ).toBeNull()
    expect(productCreatePayload({ ...DRAFT, price: '' }, READY)).toBeNull()
    expect(
      productCreatePayload({ ...DRAFT, description: '' }, READY),
    ).toBeNull()
    expect(productCreatePayload({ ...DRAFT, picture: null }, READY)).toBeNull()
    expect(
      productCreatePayload(DRAFT, { ...READY, categoryAvailable: false }),
    ).toBeNull()
  })

  it('withholds the payload while the picture or create request is in flight', () => {
    expect(
      productCreatePayload(DRAFT, { ...READY, picturePending: true }),
    ).toBeNull()
    expect(
      productCreatePayload(DRAFT, { ...READY, submitting: true }),
    ).toBeNull()
  })

  it('withholds the payload in view mode even with a complete draft', () => {
    expect(productCreatePayload(DRAFT, { ...READY, lookOnly: true })).toBeNull()
  })
})
