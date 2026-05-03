import { describe, expect, it } from 'vitest'
import { fileUploadProgressUpdate, selfConnectionUpdate, subscriptionPageMain } from '@/signals'

const selfConnectionPayload = {
  userId: 7,
  connectedAt: 1710000000,
  messageRateLimitSecondsRemaining: 5,
  outboundModerationState: {
    requestId: 'request-1',
    phase: 'checking',
    text: 'hello',
    attachments: [],
    reason: null,
    updatedAt: 1710000001,
  },
  attachmentDrafts: [{
    draftId: 'draft-1',
    filename: 'report.pdf',
    mimeType: 'application/pdf',
    size: 1234,
    uploadedAt: 1710000002,
  }],
  fileUploadProgress: {
    filename: 'photo.jpg',
    uploadedBytes: 512,
    totalBytes: 1024,
  },
}

describe('chat session signal parsers', () => {
  it('parses main page selfConnection payloads', () => {
    expect(subscriptionPageMain.parse({
      selfConnection: selfConnectionPayload,
    })?.selfConnection).toEqual(selfConnectionPayload)
  })

  it('parses selfConnection update payloads', () => {
    expect(selfConnectionUpdate.parse({
      selfConnection: selfConnectionPayload,
    })).toEqual({ selfConnection: selfConnectionPayload })
  })

  it('parses file upload progress updates as selfConnection payloads', () => {
    expect(fileUploadProgressUpdate.parse({
      selfConnection: {
        ...selfConnectionPayload,
        fileUploadProgress: null,
      },
    })?.selfConnection.fileUploadProgress).toBeNull()
  })

  it('rejects invalid selfConnection payloads', () => {
    expect(selfConnectionUpdate.parse({ selfConnection: { userId: 7 } })).toBeNull()
    expect(selfConnectionUpdate.parse({
      selfConnection: {
        ...selfConnectionPayload,
        outboundModerationState: { phase: 'checking' },
      },
    })).toBeNull()
    expect(fileUploadProgressUpdate.parse({ fileUploadProgress: null })).toBeNull()
  })
})
