import { afterEach, expect, it, vi } from 'vitest'

import { STAND_GATEWAY_URL } from './standGateway.mjs'
import {
  resetTelegram,
  setTelegramReachable,
  waitForTelegramCode,
} from './standTelegram.mjs'

const MAILBOX = 'http://mailpit.test:8025'

const PHONE = '+15550001'

afterEach(() => {
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

it('reads the code off the subject of the letter to the number at telegram.stand', async () => {
  const fetch = deliver(`${PHONE}@telegram.stand`, '731904')

  await expect(waitForTelegramCode(PHONE)).resolves.toBe('731904')
  expect(fetch).toHaveBeenLastCalledWith(`${MAILBOX}/api/v1/message/telegram`)
})

it('fails when the message carries no code', async () => {
  deliver(`${PHONE}@telegram.stand`, 'code: 12')

  await expect(waitForTelegramCode(PHONE)).rejects.toThrow(
    `the Telegram message to ${PHONE} carried no code`,
  )
})

it('declares whether a number is on Telegram', async () => {
  const fetch = acceptEverything()

  await setTelegramReachable(PHONE, false)

  const [url, init] = fetch.mock.calls[0]
  expect(url).toBe(`${STAND_GATEWAY_URL}/telegram/test/reachable`)
  expect(JSON.parse(String(init?.body))).toEqual({
    phone_number: PHONE,
    reachable: false,
  })
})

it('forgets every declared number with one reset', async () => {
  const fetch = acceptEverything()

  await resetTelegram()

  const [url, init] = fetch.mock.calls[0]
  expect(url).toBe(`${STAND_GATEWAY_URL}/test/reset`)
  expect(JSON.parse(String(init?.body))).toEqual({})
})

/** Makes the gateway accept every call. */
function acceptEverything() {
  return vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))
}

/**
 * Puts one letter in the stand's mailbox, already there at the first poll.
 *
 * @param recipient Address the gateway wrote the letter to
 * @param subject Subject line, which carries the code
 */
function deliver(recipient: string, subject: string) {
  vi.stubEnv('MAILPIT_URL', MAILBOX)
  const listing = JSON.stringify({
    messages: [
      { ID: 'telegram', Subject: subject, To: [{ Address: recipient }] },
    ],
  })

  return vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValueOnce(new Response(listing))
    .mockResolvedValueOnce(new Response(listing))
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ Subject: subject, Text: 'body' })),
    )
}
