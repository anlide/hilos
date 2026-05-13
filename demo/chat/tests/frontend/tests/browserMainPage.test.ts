import { describe, expect, it } from 'vitest'
import {
  attachmentDraftsFromBrowserRows,
  mainBotsFromBrowserRows,
  mainEventsFromBrowserRows,
  mainUsersFromBrowserRows,
  selfConnectionFromBrowserRows,
} from '@/entities/browserMainPage'

describe('main browser row parsers', () => {
  it('builds chat events from browser source fragments', () => {
    const events = mainEventsFromBrowserRows({
      9: {
        rowKey: 9,
        sources: {
          events: { id: 9, type: 'message_sent', timestamp: '2026-05-13 10:00:00' },
          eventMessages: {
            eventId: 9,
            authorUserId: 7,
            authorBotId: null,
            message: 'with file',
          },
          eventAttachments: [{
            id: 11,
            eventId: 9,
            filename: 'report.pdf',
            mimeType: 'application/pdf',
          }],
        },
      },
      10: {
        rowKey: 10,
        sources: {
          events: { id: 10, type: 'user_registered', timestamp: '2026-05-13 10:01:00' },
          eventUserRegistrations: { eventId: 10, targetUserId: 8 },
        },
      },
      11: {
        rowKey: 11,
        sources: {
          events: { id: 11, type: 'user_renamed', timestamp: '2026-05-13 10:02:00' },
          eventUserRenames: {
            eventId: 11,
            targetUserId: 8,
            actorUserId: 8,
            oldName: 'Ada',
            newName: 'Grace',
          },
        },
      },
    })

    expect(events.map((event) => event.id)).toEqual([9, 10, 11])
    expect(events[0].eventMessage?.message).toBe('with file')
    expect(events[0].attachments).toEqual([{
      id: 11,
      eventId: 9,
      filename: 'report.pdf',
      mimeType: 'application/pdf',
    }])
    expect(events[1].eventUserRegistration?.targetUserId).toBe(8)
    expect(events[2].eventUserRename?.newName).toBe('Grace')
  })

  it('builds online and offline users from mainUsers rows', () => {
    expect(mainUsersFromBrowserRows({
      7: {
        rowKey: 7,
        sources: {
          users: { id: 7, name: 'Ada', lastActivity: null },
          connections: { userId: 7, presence: 'online', onlineSessionCount: 2 },
        },
      },
      8: {
        rowKey: 8,
        sources: {
          users: { id: 8, name: 'Grace', lastActivity: '2026-05-13 10:15:00' },
        },
      },
    })).toEqual([
      {
        id: 7,
        name: 'Ada',
        lastActivity: null,
        presence: 'online',
        onlineSessionCount: 2,
      },
      {
        id: 8,
        name: 'Grace',
        lastActivity: '2026-05-13 10:15:00',
        presence: 'offline',
        onlineSessionCount: 0,
      },
    ])
  })

  it('builds online and offline bots from mainBots rows', () => {
    expect(mainBotsFromBrowserRows({
      3: {
        rowKey: 3,
        sources: {
          bots: {
            id: 3,
            name: 'Helper',
            description: null,
            style: null,
            topics: null,
            personality: null,
            active: true,
          },
          botAgentStatuses: { botId: 3, status: 'joined', updatedAt: 1710000000 },
        },
      },
      4: {
        rowKey: 4,
        sources: {
          bots: {
            id: 4,
            name: 'Archivist',
            description: null,
            style: null,
            topics: null,
            personality: null,
            active: true,
          },
          botAgentStatuses: { botId: 4, status: 'left', updatedAt: 1710000001 },
        },
      },
    }).map((bot) => ({ id: bot.id, name: bot.name, presence: bot.presence }))).toEqual([
      { id: 3, name: 'Helper', presence: 'online' },
      { id: 4, name: 'Archivist', presence: 'offline' },
    ])
  })

  it('builds selfConnection from current connection browser rows', () => {
    expect(selfConnectionFromBrowserRows({
      7: {
        rowKey: 7,
        sources: {
          connections: {
            userId: 7,
            connectedAt: 1710000000,
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
          },
          userStates: {
            lastOutboundSubmittedAt: 1710000000,
            messageRateLimitSecondsRemaining: 5,
          },
        },
      },
    })).toEqual({
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
    })
  })

  it('builds attachment drafts from browser rows', () => {
    expect(attachmentDraftsFromBrowserRows({
      'draft-2': {
        rowKey: 'draft-2',
        sources: {
          attachmentDrafts: {
            draftId: 'draft-2',
            filename: 'b.txt',
            mimeType: 'text/plain',
            size: 2,
            uploadedAt: 1710000002,
          },
        },
      },
      'draft-1': {
        rowKey: 'draft-1',
        sources: {
          attachmentDrafts: {
            draftId: 'draft-1',
            filename: 'a.txt',
            mimeType: 'text/plain',
            size: 1,
            uploadedAt: 1710000001,
          },
        },
      },
    })).toEqual([
      {
        draftId: 'draft-1',
        filename: 'a.txt',
        mimeType: 'text/plain',
        size: 1,
        uploadedAt: 1710000001,
      },
      {
        draftId: 'draft-2',
        filename: 'b.txt',
        mimeType: 'text/plain',
        size: 2,
        uploadedAt: 1710000002,
      },
    ])
  })
})
