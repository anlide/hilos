import { describe, expect, it } from 'vitest'
import { eventFromFields } from '../../src/types/Event.js'

describe('typed event moment reader', () => {
  it.each([1767323045456, 0, -1, -86399999, 2208988800456])(
    'preserves the exact epoch millisecond moment %s',
    (timestamp) => {
      const fields = { id: 1, type: 'chat_started', timestamp }

      expect(eventFromFields(fields)).toEqual(fields)
    },
  )
})
