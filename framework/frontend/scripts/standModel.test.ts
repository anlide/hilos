import { afterEach, expect, it, vi } from 'vitest'

import { STAND_GATEWAY_URL } from './standGateway.mjs'
import { dictateModelAnswer, modelKey } from './standModel.mjs'

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
