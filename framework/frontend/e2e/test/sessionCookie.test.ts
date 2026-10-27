import type { Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { sessionToken } from '../sessionCookie.js'

function pageWithCookies(cookies: { name: string; value: string }[]): Page {
  return {
    context: () => ({ cookies: async () => cookies }),
  } as unknown as Page
}

describe('sessionToken', () => {
  it('reads the session even when its rotation cookie comes first', async () => {
    const page = pageWithCookies([
      { name: 'hilos_session_token_demo_rotate', value: 'rotation' },
      { name: 'other_cookie', value: 'unrelated' },
      { name: 'hilos_session_token_demo', value: 'session' },
    ])

    expect(await sessionToken(page)).toBe('session')
  })

  it('refuses a context that only has a rotation cookie', async () => {
    const page = pageWithCookies([
      { name: 'hilos_session_token_demo_rotate', value: 'rotation' },
    ])

    await expect(sessionToken(page)).rejects.toThrow(
      'no hilos_session_token_* cookie on this context',
    )
  })
})
