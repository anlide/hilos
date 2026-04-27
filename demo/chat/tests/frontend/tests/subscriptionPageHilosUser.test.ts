import { describe, expect, it } from 'vitest'
import { subscriptionPageHilosUser } from '@/signals/subscriptionPageHilosUser'

describe('subscriptionPageHilosUser', () => {
  it('uses the plain subscription signal dispatch key', () => {
    expect(subscriptionPageHilosUser.dispatchKey).toBe('subscription_page_hilos_user')
  })

  it('parses the subscribe acknowledgement with a frontend state snapshot', () => {
    const parsed = subscriptionPageHilosUser.parse({
      userId: 7,
      frontend: {
        full: {
          users: [{ id: 7, name: 'Ada' }],
          userPresence: [{ userId: 7, presence: 'online' }],
        },
      },
    })

    expect(parsed).toEqual({ userId: 7 })
  })

  it('parses not-found acknowledgements with an empty frontend envelope', () => {
    expect(subscriptionPageHilosUser.parse({ userId: 404, frontend: [] })).toEqual({
      userId: 404,
    })
  })

  it('rejects malformed payloads', () => {
    expect(subscriptionPageHilosUser.parse(null)).toBeNull()
    expect(subscriptionPageHilosUser.parse([])).toBeNull()
    expect(subscriptionPageHilosUser.parse({})).toBeNull()
    expect(subscriptionPageHilosUser.parse({ userId: '7' })).toBeNull()
  })
})
