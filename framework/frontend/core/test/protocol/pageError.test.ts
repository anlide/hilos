import { describe, expect, it } from 'vitest'

import { pageSubscriptionErrorSchema } from '../../src/protocol/pageError.js'
import { parseSignal } from '../../src/protocol/parseSignal.js'

const ERROR = {
  page: 'user',
  httpCode: 403,
  errorCode: 'forbidden',
  title: 'Server chose this title',
  message: 'Server chose this description',
}

describe('pageSubscriptionErrorSchema', () => {
  it('preserves server wording even for a known status and code', () => {
    expect(pageSubscriptionErrorSchema.parse(ERROR)).toEqual(ERROR)
    expect(pageSubscriptionErrorSchema.parse(ERROR)).not.toHaveProperty(
      'transitionMessage',
    )
  })

  it('keeps an arbitrary server transition line and rejects a malformed one', () => {
    const withLine = {
      ...ERROR,
      transitionMessage: 'Moved on purpose.',
    }
    expect(pageSubscriptionErrorSchema.parse(withLine)).toEqual(withLine)
    expect(
      parseSignal(
        JSON.stringify({ type: 'subscription_page_error', data: withLine }),
        { subscription_page_error: pageSubscriptionErrorSchema },
      ),
    ).toMatchObject({
      ok: true,
      signal: { kind: 'project', data: withLine },
    })

    const catalog = {
      page: 'hilos_i18n_country',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
      transitionMessage: 'It was deleted just now.',
    }
    expect(pageSubscriptionErrorSchema.parse(catalog)).toEqual(catalog)
  })

  it.each([null, 404, false, [], ''])(
    'rejects invalid transitionMessage through the schema and parseSignal: %j',
    (value) => {
      const payload = { ...ERROR, transitionMessage: value }
      expect(pageSubscriptionErrorSchema.safeParse(payload).success).toBe(false)
      expect(
        parseSignal(
          JSON.stringify({ type: 'subscription_page_error', data: payload }),
          { subscription_page_error: pageSubscriptionErrorSchema },
        ),
      ).toMatchObject({
        ok: false,
        failure: {
          kind: 'invalid-signal-data',
          type: 'subscription_page_error',
        },
      })
    },
  )

  for (const field of ['title', 'message'] as const) {
    it(`rejects missing ${field}`, () => {
      const payload: Record<string, unknown> = { ...ERROR }
      delete payload[field]
      expect(pageSubscriptionErrorSchema.safeParse(payload).success).toBe(false)
      expect(
        parseSignal(
          JSON.stringify({ type: 'subscription_page_error', data: payload }),
          { subscription_page_error: pageSubscriptionErrorSchema },
        ),
      ).toMatchObject({
        ok: false,
        failure: {
          kind: 'invalid-signal-data',
          type: 'subscription_page_error',
        },
      })
    })

    it.each([null, 403, false, [], ''])(
      `rejects invalid ${field}: %j`,
      (value) => {
        expect(
          pageSubscriptionErrorSchema.safeParse({ ...ERROR, [field]: value })
            .success,
        ).toBe(false)
      },
    )
  }
})
