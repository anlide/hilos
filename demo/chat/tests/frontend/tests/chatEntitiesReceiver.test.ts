import { describe, expect, it } from 'vitest'
import { ChatEntitiesReceiver } from '@/entities/ChatEntitiesReceiver'

describe('ChatEntitiesReceiver', () => {
  it('ignores users from the generic entity channel', () => {
    const receiver = new ChatEntitiesReceiver()
    const store = {
      usersCalled: false,
      upsertUsers() {
        this.usersCalled = true
      },
    }

    receiver.apply({
      entities: {
        full: {
          users: [{ id: 7, name: 'Ada', sessionToken: 'secret' }],
        },
        updates: {
          users: [{ id: 7, name: 'Grace' }],
        },
        deleted: {
          users: [7],
        },
      },
    }, store)

    expect(store.usersCalled).toBe(false)
  })
})
