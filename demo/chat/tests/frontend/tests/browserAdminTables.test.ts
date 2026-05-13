import { describe, expect, it } from 'vitest'
import {
  adminBotsFromBrowserRows,
  moderatorPromptPiecesFromBrowserRows,
} from '@/entities/browserAdminTables'

describe('admin browser row parsers', () => {
  it('builds moderator prompt pieces from browser rows', () => {
    expect(moderatorPromptPiecesFromBrowserRows({
      2: {
        rowKey: 2,
        sources: {
          moderatorPromptPieces: {
            id: 2,
            section: 'message_rule',
            promptPiece: 'Keep replies concise.',
          },
        },
      },
      1: {
        rowKey: 1,
        sources: {
          moderatorPromptPieces: {
            id: 1,
            section: 'name_rule',
            promptPiece: 'Reject impersonation.',
          },
        },
      },
    })).toEqual([
      {
        id: 1,
        section: 'name_rule',
        promptPiece: 'Reject impersonation.',
      },
      {
        id: 2,
        section: 'message_rule',
        promptPiece: 'Keep replies concise.',
      },
    ])
  })

  it('builds admin bots with online and offline runtime status', () => {
    expect(adminBotsFromBrowserRows({
      4: {
        rowKey: 4,
        sources: {
          bots: {
            id: 4,
            name: 'Offline Bot',
            description: null,
            style: null,
            topics: null,
            personality: null,
            active: false,
          },
          botAgentStatuses: {
            botId: 4,
            status: 'left',
            updatedAt: 1710000001,
          },
        },
      },
      3: {
        rowKey: 3,
        sources: {
          bots: {
            id: 3,
            name: 'Online Bot',
            description: 'Answers questions',
            style: 'friendly',
            topics: null,
            personality: null,
            active: true,
          },
          botAgentStatuses: {
            botId: 3,
            status: 'joined',
            updatedAt: 1710000000,
          },
        },
      },
      5: {
        rowKey: 5,
        sources: {
          bots: {
            id: 5,
            name: 'No Status Bot',
            description: null,
            style: null,
            topics: null,
            personality: null,
            active: true,
          },
        },
      },
    }).map((bot) => ({
      id: bot.id,
      name: bot.name,
      active: bot.active,
      presence: bot.presence,
    }))).toEqual([
      {
        id: 3,
        name: 'Online Bot',
        active: true,
        presence: 'online',
      },
      {
        id: 4,
        name: 'Offline Bot',
        active: false,
        presence: 'offline',
      },
      {
        id: 5,
        name: 'No Status Bot',
        active: true,
        presence: 'offline',
      },
    ])
  })
})
