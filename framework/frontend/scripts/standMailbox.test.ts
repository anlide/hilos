import { afterEach, expect, it, vi } from 'vitest'

import { waitForAnyMailTo } from './standMailbox.mjs'

const MAILBOX = 'http://mailpit.test:8025'

const ADDRESS = '+15550001@sms.stand'

const LISTING = '/api/v1/messages?limit=200'

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('refuses before any call when the runner names no mailbox', async () => {
  vi.stubEnv('MAILPIT_URL', undefined)
  const fetch = vi.spyOn(globalThis, 'fetch')

  await expect(waitForAnyMailTo(ADDRESS)).rejects.toThrow(
    'MAILPIT_URL is not set: the e2e runner names its stand mailbox in its docker-compose.test.yml',
  )
  expect(fetch).not.toHaveBeenCalled()
})

it('polls until the letter arrives and returns the newest one', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const found = listing(['newest', ADDRESS], ['older', ADDRESS])
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(listing())
    .mockResolvedValueOnce(found)
    .mockResolvedValueOnce(found.clone())
    .mockResolvedValueOnce(answer({ Subject: 'Code 4821', Text: 'body' }))

  const waiting = waitForAnyMailTo(ADDRESS)
  await vi.runAllTimersAsync()

  await expect(waiting).resolves.toEqual({ subject: 'Code 4821', text: 'body' })
  expect(fetch.mock.calls.map(([url]) => url)).toEqual([
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}/api/v1/message/newest`,
  ])
})

it('matches the recipient without regard to case', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const found = listing(['only', 'Someone@Example.Test'])
  vi.spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(found)
    .mockResolvedValueOnce(found.clone())
    .mockResolvedValueOnce(answer({ Subject: 'Hello', Text: '' }))

  await expect(waitForAnyMailTo('someone@example.test')).resolves.toEqual({
    subject: 'Hello',
    text: '',
  })
})

it('fails at once on an interceptor that answers anything but 2xx', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 500 }))

  await expect(waitForAnyMailTo(ADDRESS)).rejects.toThrow(
    `mail interceptor answered 500 for ${LISTING}`,
  )
  expect(fetch).toHaveBeenCalledOnce()
})

it('gives up when no letter arrives within the wait', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => listing())

  const waiting = expect(waitForAnyMailTo(ADDRESS)).rejects.toThrow(
    `no mail to ${ADDRESS} reached the interceptor`,
  )
  await vi.runAllTimersAsync()

  await waiting
})

it('fails when the letter vanishes between the poll and the read', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  vi.spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(listing(['gone', ADDRESS]))
    .mockResolvedValueOnce(listing())

  await expect(waitForAnyMailTo(ADDRESS)).rejects.toThrow(
    `mail to ${ADDRESS} disappeared`,
  )
})

/**
 * A mailbox listing, newest first, as Mailpit answers it.
 *
 * @param entries One `[id, recipient]` pair per message
 */
function listing(...entries: [string, string][]): Response {
  return answer({
    messages: entries.map(([id, recipient]) => ({
      ID: id,
      Subject: '',
      To: [{ Address: recipient }],
    })),
  })
}

/**
 * A 200 carrying a JSON body.
 *
 * @param body What the interceptor answers with
 */
function answer(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200 })
}
