import { describe, expect, it } from 'vitest'
import { botDetailFromBrowserRows } from '@/entities/browserBotDetail'

describe('bot detail browser rows', () => {
  it('builds the selected bot detail from browser rows', () => {
    expect(botDetailFromBrowserRows({
      4: {
        rowKey: 4,
        sources: {
          bots: {
            id: 4,
            name: 'Ignored Bot',
            description: null,
            style: null,
            topics: null,
            personality: null,
            active: false,
          },
        },
      },
      3: {
        rowKey: 3,
        sources: {
          bots: {
            id: 3,
            name: 'Detail Bot',
            description: 'Answers questions',
            style: 'friendly',
            topics: 'support',
            personality: 'direct',
            active: true,
          },
          botAgentStatuses: {
            botId: 3,
            status: 'joined',
            updatedAt: 1710000000,
          },
        },
      },
    }, 3)).toMatchObject({
      id: 3,
      name: 'Detail Bot',
      description: 'Answers questions',
      style: 'friendly',
      topics: 'support',
      personality: 'direct',
      active: true,
      presence: 'online',
    })
  })

  it('returns null when the selected bot row is absent or malformed', () => {
    expect(botDetailFromBrowserRows(undefined, 3)).toBeNull()
    expect(botDetailFromBrowserRows({
      3: {
        rowKey: 3,
        sources: {
          bots: {
            id: '3',
            name: 'Malformed Bot',
          },
        },
      },
    }, 3)).toBeNull()
  })
})
