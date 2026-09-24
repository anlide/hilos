import { afterEach, expect, it, vi } from 'vitest'

import { STAND_GATEWAY_URL } from './standGateway.mjs'
import {
  declareOAuthAccount,
  orderExpiredCode,
  uniqueOAuthSubject,
} from './standOAuth.mjs'

afterEach(() => {
  vi.restoreAllMocks()
})

it('declares an account with no fields as its profile and id alone', async () => {
  const fetch = acceptEverything()

  const account = await declareOAuthAccount('github', { subject: '123' })

  expect(sent(fetch)).toEqual({
    url: `${STAND_GATEWAY_URL}/oauth/test/account`,
    body: { profile: 'github', subject: '123' },
  })
  expect(account).toEqual({
    profile: 'github',
    subject: '123',
    login: 'user123',
    name: null,
    email: null,
  })
})

it('leaves out a field the spec left empty and sends the ones it named', async () => {
  const fetch = acceptEverything()

  const account = await declareOAuthAccount('google', {
    subject: '456',
    login: 'octo',
    name: null,
    email: undefined,
  })

  expect(sent(fetch).body).toEqual({
    profile: 'google',
    subject: '456',
    login: 'octo',
  })
  expect(account.login).toBe('octo')
  expect(account.email).toBeNull()
})

it('coins a fresh id of twelve digits when the spec names none', async () => {
  acceptEverything()

  expect(uniqueOAuthSubject()).toMatch(/^\d{12}$/)
  expect((await declareOAuthAccount('github')).subject).toMatch(/^\d{12}$/)
})

it('orders an expired code for the account by its profile and id', async () => {
  const fetch = acceptEverything()

  await orderExpiredCode({
    profile: 'google',
    subject: '789',
    login: 'user789',
    name: null,
    email: null,
  })

  expect(sent(fetch)).toEqual({
    url: `${STAND_GATEWAY_URL}/oauth/test/expired-code`,
    body: { profile: 'google', subject: '789' },
  })
})

/** Makes the gateway accept every call. */
function acceptEverything() {
  return vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))
}

/** Reads where the only call went and what it carried. */
function sent(fetch: ReturnType<typeof acceptEverything>): {
  url: unknown
  body: unknown
} {
  expect(fetch).toHaveBeenCalledOnce()
  const [url, init] = fetch.mock.calls[0]

  return { url, body: JSON.parse(String(init?.body)) }
}
