import { test, expect } from '../test.js'

import {
  dictateGatewayBehavior,
  STAND_GATEWAY_URL,
} from '../../../../../framework/frontend/scripts/standGateway.mjs'
import {
  dictateModelAnswer,
  forgetModelKey,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'

// spec-owner: stand — the stand's local model

// The stand's local model, proved on the gateway itself (HIL-925). The gateway has
// no unit suite of its own. Since HIL-927 chat moderation calls the channel too
// (message-moderation.spec.ts), but through the product; this file is where the
// channel itself is held to its contract — a dictated text reaches the answer,
// once per call, queued, scoped to its key, and a call nobody dictated for is
// refused.
//
// No browser: the product is not involved. The provider route is called with the
// body the daemon's local model provider posts, and each test coins its own key,
// so it disturbs no other spec. /test/reset is never called here — it would wipe
// what the other workers dictated.

/** The provider route the daemon's local model provider calls. */
const GENERATE = '/model/api/generate'

/** The test route a dictation is posted to. */
const ANSWER = '/model/test/answer'

/** What one call of the provider route came back with. */
interface ModelAnswer {
  status: number
  body: Record<string, unknown>
}

/**
 * Ask the model the way the daemon's local model provider does, with a prompt that mentions a key.
 *
 * @param key The string the prompt carries, as a spec's message text would be.
 * @returns The status and the decoded body.
 */
async function generate(key: string): Promise<ModelAnswer> {
  const response = await fetch(`${STAND_GATEWAY_URL}${GENERATE}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: 'stand',
      prompt: `System: Moderate the message.\n\nUser: Message: ${key}\n\nAssistant:`,
      stream: false,
    }),
  })

  return {
    status: response.status,
    body: (await response.json()) as Record<string, unknown>,
  }
}

/**
 * Post a dictation without failing on a refusal, so a spec can assert it.
 *
 * @param dictation The dictation as the gateway reads it.
 * @returns The status and the decoded body.
 */
async function dictate(
  dictation: Record<string, unknown>,
): Promise<ModelAnswer> {
  const response = await fetch(`${STAND_GATEWAY_URL}${ANSWER}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(dictation),
  })

  return {
    status: response.status,
    body: (await response.json()) as Record<string, unknown>,
  }
}

/**
 * Await every owned key's teardown and retain the body's thrown value unchanged
 * when cleanup succeeds, following the fixture teardown's aggregation contract.
 *
 * @param keys All keys reserved before their first request.
 * @param bodyErrors The body's thrown value, if it failed, including undefined.
 */
async function cleanupModelKeys(
  keys: readonly string[],
  bodyErrors: readonly unknown[],
): Promise<void> {
  const results = await Promise.allSettled(
    keys.map((key) => forgetModelKey(key)),
  )
  const errors = [...bodyErrors]
  for (const result of results) {
    if (result.status === 'rejected') errors.push(result.reason)
  }
  if (bodyErrors.length === 1 && errors.length === 1) throw errors[0]
  if (errors.length > 0)
    throw new AggregateError(errors, 'Model key cleanup failed', {
      cause: errors[0],
    })
}

test('stand.model-queue preserves exact answers, one-shot use, and FIFO order', async () => {
  const keys: string[] = []
  const bodyErrors: unknown[] = []

  try {
    await test.step('The model returns the exact dictated response', async () => {
      const key = modelKey()
      keys.push(key)
      await dictateModelAnswer(key, '{"allow": false, "reason": "dictated"}')

      const answer = await generate(key)

      expect(answer.status).toBe(200)
      expect(answer.body.response).toBe(
        '{"allow": false, "reason": "dictated"}',
      )
      expect(answer.body.model).toBe('stand')
      expect(answer.body.done).toBe(true)
    })

    await test.step('A consumed dictation is refused on the next call', async () => {
      const key = modelKey()
      keys.push(key)
      await dictateModelAnswer(key, 'once')

      expect((await generate(key)).body.response).toBe('once')
      expect(await generate(key)).toEqual({
        status: 503,
        body: { ok: false, error: 'ANSWER_NOT_DICTATED' },
      })
    })

    await test.step('Queued answers return first, second, and then 503', async () => {
      const key = modelKey()
      keys.push(key)
      await dictateModelAnswer(key, 'first')
      await dictateModelAnswer(key, 'second')

      expect((await generate(key)).body.response).toBe('first')
      expect((await generate(key)).body.response).toBe('second')
      expect((await generate(key)).status).toBe(503)
    })
  } catch (error) {
    bodyErrors.push(error)
  } finally {
    await cleanupModelKeys(keys, bodyErrors)
  }
})

test('stand.model-isolation keeps foreign and undictated calls refused', async () => {
  const keys: string[] = []
  const bodyErrors: unknown[] = []

  try {
    await test.step('A foreign key is refused without spending the owned answer', async () => {
      const dictated = modelKey()
      keys.push(dictated)
      const other = modelKey()
      keys.push(other)
      await dictateModelAnswer(dictated, 'mine')

      expect((await generate(other)).status).toBe(503)
      expect((await generate(dictated)).body.response).toBe('mine')
    })

    await test.step('A call without a dictation returns ANSWER_NOT_DICTATED', async () => {
      const key = modelKey()
      keys.push(key)

      expect(await generate(key)).toEqual({
        status: 503,
        body: { ok: false, error: 'ANSWER_NOT_DICTATED' },
      })
    })
  } catch (error) {
    bodyErrors.push(error)
  } finally {
    await cleanupModelKeys(keys, bodyErrors)
  }
})

test('stand.model-validation refuses invalid declarations and dictates status', async () => {
  const keys: string[] = []
  const bodyErrors: unknown[] = []

  try {
    await test.step('Invalid dictations return exact 400 reasons and leave the key empty', async () => {
      const key = modelKey()
      keys.push(key)
      const refusals: [Record<string, unknown>, string][] = [
        [{ key: '', response: 'text' }, 'KEY_REQUIRED'],
        [{ key, response: 42 }, 'RESPONSE_REQUIRED'],
        [{ key, answer: 'text' }, 'FIELD_UNKNOWN'],
      ]

      for (const [dictation, error] of refusals) {
        expect(await dictate(dictation), JSON.stringify(dictation)).toEqual({
          status: 400,
          body: { ok: false, error },
        })
      }

      // A refused dictation is not kept: the key still has nothing to answer with.
      expect((await generate(key)).status).toBe(503)
    })

    await test.step('The status lever returns 500 STATUS_DICTATED', async () => {
      const key = modelKey()
      keys.push(key)
      await dictateGatewayBehavior(GENERATE, key, { status: 500 })

      expect(await generate(key)).toEqual({
        status: 500,
        body: { ok: false, error: 'STATUS_DICTATED' },
      })
    })
  } catch (error) {
    bodyErrors.push(error)
  } finally {
    await cleanupModelKeys(keys, bodyErrors)
  }
})
