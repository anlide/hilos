import { afterEach, expect, it, vi } from 'vitest'

import { uniquePhone, waitForSmsCode } from './standSms.mjs'

const MAILBOX = 'http://mailpit.test:8025'

const PHONE = '+15550001'

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('reads the code off the subject of the letter to the number at sms.stand', async () => {
  const fetch = deliver(
    `${PHONE}@sms.stand`,
    'Your code is 482913, valid 5 min',
  )

  await expect(waitForSmsCode(PHONE)).resolves.toBe('482913')
  expect(fetch).toHaveBeenLastCalledWith(`${MAILBOX}/api/v1/message/sms`)
})

it('fails when the text carries no code', async () => {
  deliver(`${PHONE}@sms.stand`, 'Welcome to 42')

  await expect(waitForSmsCode(PHONE)).rejects.toThrow(
    `the SMS to ${PHONE} carried no code`,
  )
})

it('coins a number of a plus, a one and twelve digits', () => {
  expect(uniquePhone()).toMatch(/^\+1\d{12}$/)
})

/**
 * Puts one letter in the stand's mailbox, already there at the first poll.
 *
 * @param recipient Address the gateway wrote the letter to
 * @param subject Subject line, which carries the text of the message
 */
function deliver(recipient: string, subject: string) {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const listing = JSON.stringify({
    messages: [{ ID: 'sms', Subject: subject, To: [{ Address: recipient }] }],
  })

  return vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(new Response(listing))
    .mockResolvedValueOnce(new Response(listing))
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ Subject: subject, Text: 'body' })),
    )
}
