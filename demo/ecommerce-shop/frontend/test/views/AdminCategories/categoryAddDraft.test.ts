import { describe, expect, it } from 'vitest'

import {
  EMPTY_CATEGORY_DRAFT,
  canAddCategory,
  isCategoryDraftDirty,
} from '../../../src/views/AdminCategories/categoryAddDraft.js'

const PICTURE = { fileId: 7, url: '/files/7/catalog_sm' }

describe('category add draft', () => {
  it('adds only when the name, the description and a picture are there', () => {
    expect(canAddCategory(EMPTY_CATEGORY_DRAFT, false)).toBe(false)
    expect(
      canAddCategory(
        {
          name: 'Dried flowers',
          description: 'Keep for months.',
          picture: null,
        },
        false,
      ),
    ).toBe(false)
    expect(
      canAddCategory(
        { name: '', description: 'Keep for months.', picture: PICTURE },
        false,
      ),
    ).toBe(false)
    expect(
      canAddCategory(
        { name: 'Dried flowers', description: '', picture: PICTURE },
        false,
      ),
    ).toBe(false)
    expect(
      canAddCategory(
        {
          name: 'Dried flowers',
          description: 'Keep for months.',
          picture: PICTURE,
        },
        false,
      ),
    ).toBe(true)
  })

  it('holds Add while a picture is still uploading or being published', () => {
    expect(
      canAddCategory(
        {
          name: 'Dried flowers',
          description: 'Keep for months.',
          picture: PICTURE,
        },
        true,
      ),
    ).toBe(false)
  })

  it('reads the raw value, leaving blank text for the server to refuse', () => {
    expect(
      canAddCategory({ name: ' ', description: ' ', picture: PICTURE }, false),
    ).toBe(true)
  })

  it('is dirty as soon as a text holds something or a picture is there', () => {
    expect(isCategoryDraftDirty(EMPTY_CATEGORY_DRAFT)).toBe(false)
    expect(isCategoryDraftDirty({ ...EMPTY_CATEGORY_DRAFT, name: 'D' })).toBe(
      true,
    )
    expect(
      isCategoryDraftDirty({ ...EMPTY_CATEGORY_DRAFT, description: 'K' }),
    ).toBe(true)
    expect(
      isCategoryDraftDirty({ ...EMPTY_CATEGORY_DRAFT, picture: PICTURE }),
    ).toBe(true)
  })
})
