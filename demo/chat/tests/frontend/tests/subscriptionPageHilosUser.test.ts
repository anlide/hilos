import { describe, expect, it } from 'vitest'
import { subscriptionPageHilosUser } from '@/signals/subscriptionPageHilosUser'

describe('subscriptionPageHilosUser', () => {
  it('uses the plain subscription signal dispatch key', () => {
    expect(subscriptionPageHilosUser.dispatchKey).toBe('subscription_page_hilos_user')
  })

  it('parses the subscribe acknowledgement with an entity snapshot', () => {
    const parsed = subscriptionPageHilosUser.parse({
      userId: 7,
      entities: {
        full: {
          users: [{ id: 7, name: 'Ada' }],
        },
      },
    })

    expect(parsed).toEqual({ userId: 7 })
  })

  it('parses not-found acknowledgements with an empty entity envelope', () => {
    expect(subscriptionPageHilosUser.parse({ userId: 404, entities: [] })).toEqual({
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
