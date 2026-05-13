import { describe, expect, it } from 'vitest'
import { selfConnectionUpdate, subscriptionPageMain } from '@/signals'

const selfConnectionPayload = {
  userId: 7,
  connectedAt: 1710000000,
  messageRateLimitSecondsRemaining: 5,
  outboundModerationState: {
    phase: 'checking',
    text: 'hello',
    reason: null,
    updatedAt: 1710000001,
  },
  fileUploadState: {
    phase: 'ready',
    clientUploadId: 'client-upload-1',
    errorCode: null,
    errorMessage: null,
  },
  fileUploadProgress: {
    filename: 'photo.jpg',
    uploadedBytes: 512,
    totalBytes: 1024,
  },
}

describe('chat session signal parsers', () => {
  it('parses main page browser payloads', () => {
    expect(subscriptionPageMain.parse({
      tables: {
        mainEvents: {
          rows: [{
            rowKey: 9,
            sources: {
              events: { id: 9, type: 'chat_started', timestamp: '2026-05-13 10:00:00' },
            },
          }],
          deleted: [3],
        },
      },
    })).toEqual({
      tables: {
        mainEvents: {
          rows: [{
            rowKey: 9,
            sources: {
              events: { id: 9, type: 'chat_started', timestamp: '2026-05-13 10:00:00' },
            },
          }],
          deleted: [3],
        },
      },
    })
    expect(subscriptionPageMain.parse({ selfConnection: selfConnectionPayload })).toBeNull()
  })

  it('parses selfConnection update payloads', () => {
    expect(selfConnectionUpdate.parse({
      selfConnection: selfConnectionPayload,
    })).toEqual({ selfConnection: selfConnectionPayload })
  })

  it('parses frontend-only selfConnection update payloads', () => {
    expect(selfConnectionUpdate.parse({
      frontend: {
        full: {
          attachmentDrafts: [{
            draftId: 'draft-1',
            filename: 'report.pdf',
            mimeType: 'application/pdf',
            size: 1234,
            uploadedAt: 1710000002,
          }],
        },
        replaceFull: ['attachmentDrafts'],
      },
    })).toEqual({})
  })

  it('rejects invalid selfConnection payloads', () => {
    expect(selfConnectionUpdate.parse({ selfConnection: { userId: 7 } })).toBeNull()
    expect(selfConnectionUpdate.parse({
      selfConnection: {
        ...selfConnectionPayload,
        outboundModerationState: { phase: 'checking' },
      },
    })).toBeNull()
    expect(selfConnectionUpdate.parse({
      selfConnection: {
        ...selfConnectionPayload,
        fileUploadState: { phase: 'waiting' },
      },
    })).toBeNull()
  })
})
