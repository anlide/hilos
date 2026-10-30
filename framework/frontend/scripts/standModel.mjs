// The stand's local model (HIL-925). The stand gateway answers the completion
// API the daemon's local model provider calls, and what it answers is exactly
// what a spec dictated here beforehand: the model on the stand says nothing a
// spec did not order, and a call nobody ordered an answer for is refused with a
// 503 — for the product, a model that is not answering.
//
// The model is an interlocutor, not a delivery channel. Scoped observations
// record actual provider arrivals before a delayed response, without exposing
// prompts or image bytes. A spec dictates answers and checks the product.

import { URLSearchParams } from 'node:url'

import { postToGateway, STAND_GATEWAY_URL } from './standGateway.mjs'

/**
 * Coin a key no other test's prompt can contain.
 *
 * Every key has the same length, so two different keys are never a substring of
 * one another — which is what the gateway matches a prompt by.
 *
 * @returns {string} A fresh key.
 */
export function modelKey() {
  const spread = Math.floor(Math.random() * 36 ** 8)
    .toString(36)
    .padStart(8, '0')

  return `model-${Date.now()}-${spread}`
}

/**
 * Dictate the text the model answers with on the next call that mentions a key.
 *
 * The key is a unique string the spec itself puts into the conversation — the
 * text of a message, a new name. No field of a model call carries a value the
 * spec knows in advance (the product builds the prompt from its own template),
 * so the gateway looks for the key in the call's prompt as a SUBSTRING; a key
 * that is part of another spec's text would be spent by that spec's call, which
 * is why it has to be unique.
 *
 * One dictation answers exactly one call; dictations for the same key queue up
 * and play out in the order they were made, so "first a refusal, then a
 * permission" is two calls of this helper.
 *
 * Do not combine a dictated answer with a dictated status on the same key
 * (`dictateGatewayBehavior` with `status`): a call refused with a status never
 * reaches the model, so the dictated text would stay behind and answer the next
 * call instead. A delay, a cut or a hold combine freely.
 *
 * @param {string} key The string the call's prompt has to contain.
 * @param {string} response The raw text the model answers with; an empty string is a legitimate answer.
 * @returns {Promise<void>}
 */
export async function dictateModelAnswer(key, response) {
  await postToGateway('/model/test/answer', { key, response })
}

/**
 * @typedef {object} ModelRequest
 * @property {number} ordinal Gateway arrival order within this key.
 * @property {string} key The exact test key under observation.
 * @property {string[]} imageHashes SHA-256 of decoded provider image bytes, empty for text.
 */

/**
 * Read actual arrivals for one owned key. Empty is evidence only after the
 * caller's causal checkpoint; this function introduces no wait or retry.
 * Preserve the gateway's ordinals and reject duplicated or reversed arrivals.
 *
 * @param {string} key Nonsecret key coined by this test.
 * @returns {Promise<ModelRequest[]>} Ordered, validated arrivals.
 * @throws {Error} On transport, HTTP, JSON or observation-schema failure.
 */
export async function readModelRequests(key) {
  requireModelKey(key)
  const path = `/model/test/requests?${new URLSearchParams({ key })}`
  const response = await globalThis.fetch(`${STAND_GATEWAY_URL}${path}`)
  if (response.status !== 200)
    throw new Error(`stand model refused requests: ${response.status}`)
  const arrivals = await response.json()
  if (!Array.isArray(arrivals))
    throw new Error('Invalid model request observations: expected a list')
  let previous = 0
  return arrivals.map((arrival) => {
    if (
      !arrival ||
      typeof arrival !== 'object' ||
      Array.isArray(arrival) ||
      Object.keys(arrival).sort().join() !== 'imageHashes,key,ordinal' ||
      !Number.isSafeInteger(arrival.ordinal) ||
      arrival.ordinal <= previous ||
      arrival.key !== key ||
      !Array.isArray(arrival.imageHashes) ||
      !arrival.imageHashes.every(
        (hash) => typeof hash === 'string' && /^[a-f0-9]{64}$/.test(hash),
      )
    )
      throw new Error('Invalid model request observation')
    previous = arrival.ordinal
    return {
      ordinal: arrival.ordinal,
      key: arrival.key,
      imageHashes: [...arrival.imageHashes],
    }
  })
}

/**
 * Forget only this key's arrivals, unspent answers and model-route behavior.
 * Cleanup is idempotent at the gateway; a failed acknowledgement still fails
 * the test, so an unavailable API cannot pass as successful cleanup.
 *
 * @param {string} key Nonsecret key owned by this test.
 * @returns {Promise<void>}
 * @throws {Error} On transport, HTTP, JSON or acknowledgement-schema failure.
 */
export async function forgetModelKey(key) {
  requireModelKey(key)
  const response = await globalThis.fetch(
    `${STAND_GATEWAY_URL}/model/test/forget`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key }),
    },
  )
  if (response.status !== 200)
    throw new Error(`stand model refused forget: ${response.status}`)
  const reply = await response.json()
  if (
    !reply ||
    typeof reply !== 'object' ||
    Array.isArray(reply) ||
    Object.keys(reply).join() !== 'ok' ||
    reply.ok !== true
  )
    throw new Error('Invalid model forget acknowledgement')
}

/** Refuse an unscoped request before it reaches the gateway. */
function requireModelKey(key) {
  if (typeof key !== 'string' || key === '')
    throw new Error('A model observation needs a nonempty test key')
}
