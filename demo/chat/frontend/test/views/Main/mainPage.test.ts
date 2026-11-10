import { describe, expect, it } from 'vitest'
import { isUnlinkedRename } from '../../../src/views/Main/mainPage.js'

describe('unlinked rename events', () => {
  it('waits for either rename type to receive a journal row', () => {
    expect(isUnlinkedRename('user_renamed', null)).toBe(true)
    expect(isUnlinkedRename('user_renamed_by_admin', null)).toBe(true)
  })

  it('keeps other events and linked rename events', () => {
    const rename = {
      userId: 7,
      renamedByUserId: null,
      oldName: 'Ada',
      newName: 'Grace',
    }

    expect(isUnlinkedRename('chat_started', null)).toBe(false)
    expect(isUnlinkedRename('user_renamed', rename)).toBe(false)
    expect(isUnlinkedRename('user_renamed_by_admin', rename)).toBe(false)
  })
})
