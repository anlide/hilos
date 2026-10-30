import { afterEach, expect, it, vi } from 'vitest'

import { STAND_GATEWAY_URL } from './standGateway.mjs'
import {
  dictateModelAnswer,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from './standModel.mjs'

afterEach(() => {
  vi.restoreAllMocks()
})

it('coins keys of one length, so no key is a substring of another', () => {
  const first = modelKey()
  const second = modelKey()

  expect(first).toMatch(/^model-/)
  expect(second).toMatch(/^model-/)
  expect(first).not.toBe(second)
  expect(second).toHaveLength(first.length)
})

it('dictates the answer to the call that mentions the key', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))

  await dictateModelAnswer('model-key', '')

  const [url, init] = fetch.mock.calls[0]
  expect(url).toBe(`${STAND_GATEWAY_URL}/model/test/answer`)
  expect(JSON.parse(String(init?.body))).toEqual({
    key: 'model-key',
    response: '',
  })
})

const KEY = 'model-key & /?'
const IMAGE_HASH = 'a'.repeat(64)
const ARRIVAL = { ordinal: 3, key: KEY, imageHashes: [IMAGE_HASH] }

it('reads only the encoded key and preserves provider order and decoded image hashes', async () => {
  const arrivals = [
    { ordinal: 1, key: KEY, imageHashes: [] },
    ARRIVAL,
    { ordinal: 8, key: KEY, imageHashes: [IMAGE_HASH, 'b'.repeat(64)] },
  ]
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(Response.json(arrivals))

  await expect(readModelRequests(KEY)).resolves.toEqual(arrivals)
  expect(fetch).toHaveBeenCalledExactlyOnceWith(
    `${STAND_GATEWAY_URL}/model/test/requests?key=model-key+%26+%2F%3F`,
  )
})

it('accepts an explicitly empty arrival list without polling or inventing a request', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(Response.json([]))
  await expect(readModelRequests(KEY)).resolves.toEqual([])
  expect(fetch).toHaveBeenCalledTimes(1)
})

it.each([
  null,
  {},
  { ok: true },
  [null],
  [{ ...ARRIVAL, ordinal: 0 }],
  [{ ...ARRIVAL, ordinal: 1.5 }],
  [{ ...ARRIVAL, ordinal: '3' }],
  [{ ...ARRIVAL, ordinal: Number.MAX_SAFE_INTEGER + 1 }],
  [ARRIVAL, ARRIVAL],
  [ARRIVAL, { ...ARRIVAL, ordinal: 2 }],
  [{ ...ARRIVAL, key: 'another-test' }],
  [{ ...ARRIVAL, imageHashes: undefined }],
  [{ ...ARRIVAL, imageHashes: IMAGE_HASH }],
  [{ ...ARRIVAL, imageHashes: [null] }],
  [{ ...ARRIVAL, imageHashes: ['not-a-hash'] }],
  [{ ...ARRIVAL, prompt: 'private provider text' }],
])('refuses malformed or cross-key evidence (%j)', async (arrivals) => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(Response.json(arrivals))
  await expect(readModelRequests(KEY)).rejects.toThrow('Invalid model request')
})

it('forgets only its key and accepts a repeated scoped cleanup acknowledgement', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockImplementation(async () => Response.json({ ok: true }))
  await forgetModelKey(KEY)
  await forgetModelKey(KEY)
  expect(fetch).toHaveBeenCalledTimes(2)
  for (const [url, init] of fetch.mock.calls) {
    expect(url).toBe(`${STAND_GATEWAY_URL}/model/test/forget`)
    expect(init).toEqual({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: KEY }),
    })
  }
})

it.each([null, {}, { ok: false }, { ok: 'true' }, { ok: true, reset: true }])(
  'refuses malformed cleanup acknowledgements (%j)',
  async (reply) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(Response.json(reply))
    await expect(forgetModelKey(KEY)).rejects.toThrow(
      'Invalid model forget acknowledgement',
    )
  },
)

for (const operation of [readModelRequests, forgetModelKey]) {
  it(`${operation.name} propagates HTTP, JSON and TLS failures`, async () => {
    const fetch = vi.spyOn(globalThis, 'fetch')
    fetch.mockResolvedValueOnce(new Response(null, { status: 503 }))
    await expect(operation(KEY)).rejects.toThrow('503')
    fetch.mockResolvedValueOnce(new Response('invalid json'))
    await expect(operation(KEY)).rejects.toThrow()
    const failure = new Error('TLS certificate verification failed')
    fetch.mockRejectedValueOnce(failure)
    await expect(operation(KEY)).rejects.toBe(failure)
  })

  it(`${operation.name} refuses an unscoped key before making a request`, async () => {
    const fetch = vi.spyOn(globalThis, 'fetch')
    await expect(operation('')).rejects.toThrow('nonempty test key')
    expect(fetch).not.toHaveBeenCalled()
  })
}
