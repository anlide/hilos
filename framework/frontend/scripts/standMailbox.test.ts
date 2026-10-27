import { afterEach, expect, it, vi } from 'vitest'

import {
  clearMail,
  mailsTo,
  waitForAnyMailTo,
  waitForMailTo,
  waitForMailToMatching,
} from './standMailbox.mjs'

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

it('waits for an exact subject among unrelated and older letters', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const subject = 'Confirm your email address'
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(
      listing(['foreign', 'another@example.test'], ['old', ADDRESS]),
    )
    .mockResolvedValueOnce(
      answer({ Subject: subject.toLowerCase(), Text: 'old code' }),
    )
    .mockResolvedValueOnce(
      listing(
        ['foreign', 'another@example.test'],
        ['new', ADDRESS],
        ['old', ADDRESS],
      ),
    )
    .mockResolvedValueOnce(answer({ Subject: subject, Text: 'new code' }))

  const waiting = waitForMailTo(ADDRESS, subject)
  await vi.runAllTimersAsync()

  await expect(waiting).resolves.toEqual({ subject, text: 'new code' })
  expect(fetch.mock.calls.map(([url]) => url)).toEqual([
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}/api/v1/message/old`,
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}/api/v1/message/new`,
  ])
})

it('chooses the newest exact-subject letter with a case-insensitive recipient', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(
      listing(['new', 'Someone@Example.Test'], ['old', 'someone@example.test']),
    )
    .mockResolvedValueOnce(answer({ Subject: 'Hello', Text: 'newest' }))

  await expect(waitForMailTo('SOMEONE@example.test', 'Hello')).resolves.toEqual(
    {
      subject: 'Hello',
      text: 'newest',
    },
  )
  expect(fetch).toHaveBeenCalledTimes(2)
})

it('waits for the requested number of exact-subject letters and reads the newest', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(listing(['unrelated', ADDRESS], ['old', ADDRESS]))
    .mockResolvedValueOnce(
      answer({ Subject: 'Another subject', Text: 'unrelated' }),
    )
    .mockResolvedValueOnce(answer({ Subject: 'Code', Text: 'old' }))
    .mockResolvedValueOnce(listing(['new', ADDRESS], ['old', ADDRESS]))
    .mockResolvedValueOnce(answer({ Subject: 'Code', Text: 'new' }))
    .mockResolvedValueOnce(answer({ Subject: 'Code', Text: 'old' }))

  const waiting = waitForMailTo(ADDRESS, 'Code', 2)
  await vi.runAllTimersAsync()

  await expect(waiting).resolves.toEqual({ subject: 'Code', text: 'new' })
  expect(fetch).toHaveBeenCalledTimes(6)
})

it('keeps polling for a second channel message while the earlier one is held', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  vi.spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(listing(['first', ADDRESS]))
    .mockResolvedValueOnce(answer({ Subject: 'Code 1111', Text: '' }))
    .mockResolvedValueOnce(listing(['second', ADDRESS], ['first', ADDRESS]))
    .mockResolvedValueOnce(answer({ Subject: 'Code 2222', Text: '' }))

  const waiting = waitForMailToMatching(
    ADDRESS,
    (mail) => !mail.subject.includes('1111'),
  )
  await vi.runAllTimersAsync()

  await expect(waiting).resolves.toEqual({ subject: 'Code 2222', text: '' })
})

it('lists held letters newest first across subjects and recipient casing', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(
      listing(
        ['new', 'Someone@Example.Test'],
        ['foreign', ADDRESS],
        ['old', 'someone@example.test'],
      ),
    )
    .mockResolvedValueOnce(answer({ Subject: 'New subject', Text: 'newest' }))
    .mockResolvedValueOnce(answer({ Subject: 'Old subject', Text: 'oldest' }))

  await expect(mailsTo('SOMEONE@example.test')).resolves.toEqual([
    { subject: 'New subject', text: 'newest' },
    { subject: 'Old subject', text: 'oldest' },
  ])
  expect(fetch.mock.calls.map(([url]) => url)).toEqual([
    `${MAILBOX}${LISTING}`,
    `${MAILBOX}/api/v1/message/new`,
    `${MAILBOX}/api/v1/message/old`,
  ])
})

it('returns an empty listing immediately without polling', async () => {
  vi.useFakeTimers()
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue(listing())

  await expect(mailsTo(ADDRESS)).resolves.toEqual([])

  expect(fetch).toHaveBeenCalledOnce()
  expect(vi.getTimerCount()).toBe(0)
})

it('clears once at the current mailbox without decoding an empty response', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))

  await clearMail()
  vi.stubEnv('MAILPIT_URL', 'http://another-mailpit.test:8025')
  await clearMail()

  expect(fetch.mock.calls).toEqual([
    [`${MAILBOX}/api/v1/messages`, { method: 'DELETE' }],
    ['http://another-mailpit.test:8025/api/v1/messages', { method: 'DELETE' }],
  ])
})

it('refuses a failed mailbox clear', async () => {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(null, { status: 503 }),
  )

  await expect(clearMail()).rejects.toThrow(
    'mail interceptor answered 503 for /api/v1/messages',
  )
})

it.each([undefined, ''])(
  'requires MAILPIT_URL for every generic API (%s)',
  async (mailbox) => {
    vi.stubEnv('MAILPIT_URL', mailbox)
    const fetch = vi.spyOn(globalThis, 'fetch')

    await expect(waitForMailTo(ADDRESS, 'Hello')).rejects.toThrow('MAILPIT_URL')
    await expect(mailsTo(ADDRESS)).rejects.toThrow('MAILPIT_URL')
    await expect(clearMail()).rejects.toThrow('MAILPIT_URL')
    expect(fetch).not.toHaveBeenCalled()
  },
)

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
