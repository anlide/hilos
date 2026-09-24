import { test, expect } from '@playwright/test'

import {
  dictateGatewayBehavior,
  STAND_GATEWAY_URL,
} from '../../../../../framework/frontend/scripts/standGateway.mjs'
import {
  dictateModelAnswer,
  modelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'

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

test('answers with the dictated text', async () => {
  const key = modelKey()
  await dictateModelAnswer(key, '{"allow": false, "reason": "dictated"}')

  const answer = await generate(key)

  expect(answer.status).toBe(200)
  expect(answer.body.response).toBe('{"allow": false, "reason": "dictated"}')
  expect(answer.body.model).toBe('stand')
  expect(answer.body.done).toBe(true)
})

test('spends a dictation on exactly one call', async () => {
  const key = modelKey()
  await dictateModelAnswer(key, 'once')

  expect((await generate(key)).body.response).toBe('once')
  expect(await generate(key)).toEqual({
    status: 503,
    body: { ok: false, error: 'ANSWER_NOT_DICTATED' },
  })
})

test('plays dictations for one key out in the order they were made', async () => {
  const key = modelKey()
  await dictateModelAnswer(key, 'first')
  await dictateModelAnswer(key, 'second')

  expect((await generate(key)).body.response).toBe('first')
  expect((await generate(key)).body.response).toBe('second')
  expect((await generate(key)).status).toBe(503)
})

test('leaves a call with another key alone', async () => {
  const dictated = modelKey()
  const other = modelKey()
  await dictateModelAnswer(dictated, 'mine')

  expect((await generate(other)).status).toBe(503)
  expect((await generate(dictated)).body.response).toBe('mine')
})

test('refuses a call nobody dictated an answer for', async () => {
  expect(await generate(modelKey())).toEqual({
    status: 503,
    body: { ok: false, error: 'ANSWER_NOT_DICTATED' },
  })
})

test('refuses a dictation it cannot honor', async () => {
  const key = modelKey()
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

test("plays the house's levers on the model's route", async () => {
  const key = modelKey()
  await dictateGatewayBehavior(GENERATE, key, { status: 500 })

  expect(await generate(key)).toEqual({
    status: 500,
    body: { ok: false, error: 'STATUS_DICTATED' },
  })
})
