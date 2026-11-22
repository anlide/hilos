import { describe, expect, it } from 'vitest'

import { catalogPictureReplySchema } from '../../src/catalog/catalogPictureReply.js'

describe('catalog picture reply', () => {
  it('reads the published file id and its preview address', () => {
    const reply = { fileId: 42, picture: '/files/42/catalog_sm' }

    expect(catalogPictureReplySchema.parse(reply)).toEqual(reply)
  })

  it('rejects missing or wrongly typed fields', () => {
    const picture = '/files/42/catalog_sm'
    expect(catalogPictureReplySchema.safeParse({ fileId: 42 }).success).toBe(
      false,
    )
    expect(
      catalogPictureReplySchema.safeParse({ fileId: '42', picture }).success,
    ).toBe(false)
    expect(
      catalogPictureReplySchema.safeParse({ fileId: 4.2, picture }).success,
    ).toBe(false)
    expect(
      catalogPictureReplySchema.safeParse({ fileId: 42, picture: '' }).success,
    ).toBe(false)
  })
})
