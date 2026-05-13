import { describe, expect, it } from 'vitest'
import { extractBrowserPagePayload, hasBrowserPagePayload } from '@/types'

describe('browser page payload', () => {
  it('extracts page-shaped table rows and deletes', () => {
    const payload = {
      tables: {
        userDetail: {
          rows: [
            {
              rowKey: 1,
              sources: {
                users: { id: 1, name: 'Ada' },
                connections: { presence: 'online' },
              },
            },
          ],
          deleted: [2, '3'],
        },
      },
    }

    expect(hasBrowserPagePayload(payload)).toBe(true)
    expect(extractBrowserPagePayload(payload)).toEqual(payload)
  })

  it('rejects legacy table snapshots', () => {
    const payload = {
      tables: {
        users: {
          rows: [],
          totalCount: 1,
          offset: 0,
          limit: 0,
        },
      },
    }

    expect(extractBrowserPagePayload(payload)).toBeNull()
    expect(hasBrowserPagePayload(payload)).toBe(false)
  })

  it('rejects malformed browser rows', () => {
    expect(extractBrowserPagePayload(null)).toBeNull()
    expect(extractBrowserPagePayload({})).toBeNull()
    expect(extractBrowserPagePayload({ tables: [] })).toBeNull()
    expect(extractBrowserPagePayload({ tables: { users: { rows: [{ rowKey: 1 }] } } })).toBeNull()
    expect(extractBrowserPagePayload({ tables: { users: { deleted: [{}] } } })).toBeNull()
  })
})
