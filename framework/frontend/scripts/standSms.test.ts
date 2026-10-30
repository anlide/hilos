import { parsePhoneNumberFromString } from 'libphonenumber-js/min'
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
  expect(fetch).toHaveBeenLastCalledWith(
    `${MAILBOX}/api/v1/message/sms`,
    undefined,
  )
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

it.each([
  { random: 0, phone: '+1123456789000' },
  { random: 0.678, phone: '+1123456789678' },
  { random: 1 - Number.EPSILON, phone: '+1123456789999' },
])(
  'preserves the default clock and random suffix for $random',
  ({ random, phone }) => {
    vi.spyOn(Date, 'now').mockReturnValue(1_790_123_456_789)
    vi.spyOn(Math, 'random').mockReturnValue(random)

    expect(uniquePhone()).toBe(phone)
  },
)

it.each([
  0, 12_345, 99_999, 100_000, 600_000, 623_456, 699_999, 999_999, 1_000_000,
])(
  'coins a GB mobile with 4–6 international groups at clock suffix %i',
  (clockSuffix) => {
    vi.spyOn(Date, 'now').mockReturnValue(1_790_000_000_000 + clockSuffix)
    const random = vi.spyOn(Math, 'random')

    for (const spread of [0, 0.678, 1 - Number.EPSILON]) {
      random.mockReturnValue(spread)
      const phone = uniquePhone('GB')
      expect(phone).toMatch(/^\+44\d{10}$/)
      const parsed = parsePhoneNumberFromString(phone)
      expect(parsed?.country).toBe('GB')
      expect(parsed?.number).toBe(phone)
      expect(parsed?.nationalNumber).toHaveLength(10)
      expect(parsed?.getType()).toBe('MOBILE')
      const formatted = parsed?.formatInternational()
      expect(formatted).toMatch(/^\+44 \d{4} \d{6}$/)
      expect(formatted?.replaceAll(' ', '')).toBe(phone)
    }
  },
)

it('separates GB callers by both the millisecond clock and random suffix', () => {
  const clock = vi.spyOn(Date, 'now').mockReturnValue(1_790_000_099_999)
  const random = vi.spyOn(Math, 'random').mockReturnValue(0)
  const phones = new Set([uniquePhone('GB')])
  random.mockReturnValue(0.001)
  phones.add(uniquePhone('GB'))
  random.mockReturnValue(1 - Number.EPSILON)
  phones.add(uniquePhone('GB'))
  clock.mockReturnValue(1_790_000_100_000)
  phones.add(uniquePhone('GB'))

  expect(phones.size).toBe(4)
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
