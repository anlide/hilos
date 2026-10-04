import { describe, expect, it } from 'vitest'

import { AUTH_CODE_REASON_RATE_LIMITED } from '../../src/auth/authCodeSignals.js'
import {
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_HELD,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  type CodeSendProgress,
} from '../../src/auth/authSendProgress.js'
import {
  sendAgainIn,
  sendAgainLocked,
  sendProgressLine,
} from '../../src/primitives/sendProgress.js'

const ADDRESS = 'person@example.test'

const base: CodeSendProgress = {
  state: CODE_SEND_STATE_QUEUED,
  channel: 'email',
  purpose: 'change_password',
  detail: null,
  ticket: 'a1b2c3d4e5f60718',
  reason: null,
  resendAt: null,
  expiresAt: null,
}

describe('profile send progress copy', () => {
  it.each([
    [CODE_SEND_STATE_QUEUED, 'Queued for sending…', 'bi-hourglass-split'],
    [CODE_SEND_STATE_SENDING, `Sending to ${ADDRESS}…`, 'bi-arrow-repeat'],
    [CODE_SEND_STATE_SENT, `Sent to ${ADDRESS}`, 'bi-check-circle-fill'],
    [CODE_SEND_STATE_FAILED, 'Could not send', 'bi-exclamation-triangle-fill'],
    [
      CODE_SEND_STATE_NOT_SENT,
      `Not really sent to ${ADDRESS} — letters are written here, not mailed`,
      'bi-flask',
    ],
    [
      CODE_SEND_STATE_HELD,
      `The last code sent to ${ADDRESS} was already used`,
      'bi-clock',
    ],
  ])('resolves %s to the shared line', (state, text, icon) => {
    expect(sendProgressLine({ ...base, state }, ADDRESS)).toMatchObject({
      text,
      icon,
    })
  })

  it('names a live earlier code on a rate-limited send', () => {
    expect(
      sendProgressLine(
        {
          ...base,
          state: CODE_SEND_STATE_SENT,
          reason: AUTH_CODE_REASON_RATE_LIMITED,
        },
        ADDRESS,
      ),
    ).toEqual({
      icon: 'bi-check-circle-fill',
      tone: 'text-success',
      text: `Sent to ${ADDRESS} earlier — that code still works`,
    })
  })

  it('keeps the provider refusal sentence behind the details control', () => {
    expect(
      sendProgressLine(
        {
          ...base,
          state: CODE_SEND_STATE_FAILED,
          detail: 'mailbox unavailable (550)',
        },
        ADDRESS,
      )?.text,
    ).toBe('Could not send: mailbox unavailable (550)')
  })

  it('leaves an empty or unknown line invisible', () => {
    expect(sendProgressLine(null, ADDRESS)).toBeNull()
    expect(
      sendProgressLine({ ...base, state: 'future_state' }, ADDRESS),
    ).toBeNull()
  })
})

describe('profile resend timing', () => {
  it('counts down on the local clock and opens at the server moment', () => {
    const now = 1_900_000_000_000
    expect(sendAgainIn(now + 42_000, now)).toBe('Send again in 0:42')
    expect(sendAgainIn(now + 42_000, now + 42_000)).toBeNull()
    expect(
      sendAgainLocked(
        { ...base, state: CODE_SEND_STATE_SENT },
        false,
        now + 42_000,
        now,
      ),
    ).toBe(true)
    expect(
      sendAgainLocked(
        { ...base, state: CODE_SEND_STATE_SENT },
        false,
        now + 42_000,
        now + 42_000,
      ),
    ).toBe(false)
  })

  it('locks while its own action or a transport step is running', () => {
    expect(sendAgainLocked(null, true, null, 0)).toBe(true)
    expect(sendAgainLocked(base, false, null, 0)).toBe(true)
    expect(
      sendAgainLocked(
        { ...base, state: CODE_SEND_STATE_SENDING },
        false,
        null,
        0,
      ),
    ).toBe(true)
    expect(
      sendAgainLocked(
        { ...base, state: CODE_SEND_STATE_FAILED },
        false,
        null,
        0,
      ),
    ).toBe(false)
  })
})
