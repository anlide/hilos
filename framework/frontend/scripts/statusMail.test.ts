import { expect, it } from 'vitest'
import { statusMailLinks, statusMailMoment } from './statusMail.mjs'

const STATUS = 'https://example.test/status'
const TOKEN = 'ab'.repeat(32)
const UNSUBSCRIBE = `${STATUS}#status-unsubscribe=${TOKEN}`
const CONFIRM = `${STATUS}#status-confirm=${'cd'.repeat(32)}`

it('reads the explicit offset across the repeated daylight-saving hour', () => {
  expect(
    statusMailMoment(
      { text: 'Updated at: 2026-10-25 02:30:00 +02:00 (Europe/Warsaw)' },
      'Updated at',
    ),
  ).toBe(Date.parse('2026-10-25T00:30:00Z'))
  expect(
    statusMailMoment(
      { text: 'Updated at: 2026-10-25 02:30:00 +01:00 (Europe/Warsaw)' },
      'Updated at',
    ),
  ).toBe(Date.parse('2026-10-25T01:30:00Z'))
  expect(
    statusMailMoment({ text: 'Updated at: broken' }, 'Updated at'),
  ).toBeNull()
  expect(
    statusMailMoment(
      { text: 'Resolved at: 2026-10-25 02:30:00 +01:00 (Europe/Warsaw)' },
      'Updated at',
    ),
  ).toBeNull()
})

it('reads real template instruction lines with distinct fragment capabilities', () => {
  expect(
    statusMailLinks({
      text: `Confirm your subscription: ${CONFIRM}\nThis link expires tomorrow.\n\nStatus page: ${STATUS}\nUnsubscribe: ${UNSUBSCRIBE}\n\nNot you? Ignore this letter.`,
    }),
  ).toEqual({ status: STATUS, unsubscribe: UNSUBSCRIBE, confirm: CONFIRM })
})

it('reads outage links without requiring a confirmation instruction', () => {
  expect(statusMailLinks({ text: body(UNSUBSCRIBE) })).toEqual({
    status: STATUS,
    unsubscribe: UNSUBSCRIBE,
  })
})

it.each([
  `${STATUS}?token=${TOKEN}`,
  `${STATUS}#status-unsubscribe=short`,
  `${STATUS}#status-confirm=${TOKEN}`,
  `${UNSUBSCRIBE}&other=secret`,
  `https://other.test/status#status-unsubscribe=${TOKEN}`,
  `https://user:password@example.test/status#status-unsubscribe=${TOKEN}`,
  `https://example.test/other#status-unsubscribe=${TOKEN}`,
])('refuses an unsafe unsubscribe URL: %s', (link) => {
  expect(() => statusMailLinks({ text: body(link) })).toThrow(
    'invalid status-unsubscribe link',
  )
})

it.each([
  `${STATUS}?email=reader@example.test`,
  `${STATUS}#secret`,
  'https://user:password@example.test/status',
  'file:///status',
])('refuses an unsafe public URL: %s', (status) => {
  expect(() => statusMailLinks({ text: body(UNSUBSCRIBE, status) })).toThrow(
    'invalid public address',
  )
})

it('refuses missing, duplicated and malformed confirmation instructions', () => {
  expect(() =>
    statusMailLinks({ text: `Unsubscribe: ${UNSUBSCRIBE}` }),
  ).toThrow('needs one Status page link')
  expect(() =>
    statusMailLinks({
      text: `${body(UNSUBSCRIBE)}\nUnsubscribe: ${UNSUBSCRIBE}`,
    }),
  ).toThrow('needs one Unsubscribe link')
  expect(() =>
    statusMailLinks({
      text: `Confirm your subscription: ${STATUS}\n${body(UNSUBSCRIBE)}`,
    }),
  ).toThrow('invalid status-confirm link')
})

/** The link lines shared by confirmation and outage letters. */
function body(unsubscribe: string, status = STATUS): string {
  return `Status page: ${status}\nUnsubscribe: ${unsubscribe}`
}
