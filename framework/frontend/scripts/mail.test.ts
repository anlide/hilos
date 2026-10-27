import { afterEach, expect, it, vi } from 'vitest'

import {
  MAGIC_LINK_SUBJECT,
  readMagicLinkCode,
  readMagicLinkUrl,
  readPasswordResetCode,
  readRegisterCode,
  waitForMailCode,
} from './mail.mjs'

const ADDRESS = 'reader@example.test'
const MAGIC_URL = 'https://example.test/sign-in?token=abcdef123456abcdef'

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('reads the companion code instead of digits in the magic URL above it', async () => {
  deliver(
    MAGIC_LINK_SUBJECT,
    `Use this link to sign in:\n${MAGIC_URL}\n\nOr enter this code on the page that asked for it:\n987654`,
  )

  await expect(readMagicLinkCode(ADDRESS)).resolves.toBe('987654')
})

it('refuses a magic URL with digits when no companion code is offered', async () => {
  deliver(MAGIC_LINK_SUBJECT, `Use this link to sign in:\n${MAGIC_URL}`)

  await expect(readMagicLinkCode(ADDRESS)).rejects.toThrow(
    `sign-in letter to ${ADDRESS} offered no code to type`,
  )
})

it('reads the whole magic URL beside the companion code', async () => {
  deliver(
    MAGIC_LINK_SUBJECT,
    `Use this link to sign in:\n${MAGIC_URL}\n\nOr enter this code on the page that asked for it: 987654`,
  )

  await expect(readMagicLinkUrl(ADDRESS)).resolves.toBe(MAGIC_URL)
})

it('refuses a companion code when the letter offers no link', async () => {
  deliver(
    MAGIC_LINK_SUBJECT,
    'Or enter this code on the page that asked for it: 987654',
  )

  await expect(readMagicLinkUrl(ADDRESS)).rejects.toThrow(
    `sign-in letter to ${ADDRESS} carried no link to click`,
  )
})

it('reads registration codes under the registration subject', async () => {
  deliver('Confirm your email address', 'Please enter this code: 4321')

  await expect(readRegisterCode(ADDRESS)).resolves.toBe('4321')
})

it('reads recovery codes under the password reset subject', async () => {
  deliver('Reset your password', 'Please enter this code: 123456')

  await expect(readPasswordResetCode(ADDRESS)).resolves.toBe('123456')
})

it('reads a code under the caller’s exact subject', async () => {
  deliver('Confirm changing your password', 'Please enter this code: 654321')

  await expect(
    waitForMailCode(ADDRESS, 'Confirm changing your password'),
  ).resolves.toBe('654321')
})

it('keeps the earlier code out of a resend waiting for its second letter', async () => {
  vi.useFakeTimers()
  const subject = 'Confirm changing your password'
  deliver(subject, 'Old code: 111111')
    .mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          messages: [
            { ID: 'new', To: [{ Address: ADDRESS }] },
            { ID: 'old', To: [{ Address: ADDRESS }] },
          ],
        }),
      ),
    )
    .mockResolvedValueOnce(
      new Response(
        JSON.stringify({ Subject: subject, Text: 'New code: 222222' }),
      ),
    )
    .mockResolvedValueOnce(
      new Response(
        JSON.stringify({ Subject: subject, Text: 'Old code: 111111' }),
      ),
    )

  const waiting = waitForMailCode(ADDRESS, subject, 2)
  await vi.runAllTimersAsync()

  await expect(waiting).resolves.toBe('222222')
})

it('refuses a generic letter with fewer than four digits', async () => {
  deliver('Confirm your email address', 'There are 123 reasons to confirm')

  await expect(readRegisterCode(ADDRESS)).rejects.toThrow(
    `mail "Confirm your email address" to ${ADDRESS} carried no code`,
  )
})

/**
 * Answers the existing mailbox reader with one delivered product letter.
 *
 * @param subject The letter’s subject line.
 * @param text The plain-text body offered to a person.
 */
function deliver(subject: string, text: string) {
  vi.stubEnv('MAILPIT_URL', 'http://mailpit.test:8025')
  return vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          messages: [{ ID: 'letter', To: [{ Address: ADDRESS }] }],
        }),
      ),
    )
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ Subject: subject, Text: text })),
    )
}
