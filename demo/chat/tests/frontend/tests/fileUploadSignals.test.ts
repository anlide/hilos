import { describe, expect, it } from 'vitest'
import { fileUploadComplete, fileUploadReady, fileUploadRejected } from '@/signals'

describe('file upload signal parsers', () => {
  it('parses upload ready protocol payloads', () => {
    expect(fileUploadReady.parse({
      filename: 'photo.jpg',
      size: 2048,
    })).toEqual({
      filename: 'photo.jpg',
      size: 2048,
    })
  })

  it('parses upload rejected protocol payloads', () => {
    expect(fileUploadRejected.parse({
      code: 'size_limit',
      message: 'File exceeds maximum allowed size',
    })).toEqual({
      code: 'size_limit',
      message: 'File exceeds maximum allowed size',
    })
  })

  it('parses upload complete as a payloadless marker', () => {
    expect(fileUploadComplete.parse({})).toBeUndefined()
    expect(fileUploadComplete.parse({
      attachmentDraft: {
        draftId: 'draft-1',
        filename: 'photo.jpg',
      },
    })).toBeUndefined()
  })
})
