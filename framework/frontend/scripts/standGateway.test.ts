import { afterEach, expect, it, vi } from 'vitest'

import {
  dictateGatewayBehavior,
  postToGateway,
  releaseGatewayAnswer,
  STAND_GATEWAY_URL,
} from './standGateway.mjs'

afterEach(() => {
  vi.restoreAllMocks()
})

it('posts the payload as JSON to the route on the gateway', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))

  await expect(postToGateway('/x', { answer: 42 })).resolves.toBeUndefined()

  expect(fetch).toHaveBeenCalledExactlyOnceWith(`${STAND_GATEWAY_URL}/x`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: '{"answer":42}',
  })
})

it('fails with the route and the status when the gateway refuses', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(null, { status: 503 }),
  )

  await expect(postToGateway('/x', {})).rejects.toThrow(
    'stand gateway refused /x: 503',
  )
})

it('dictates a behavior as the route, the key and the levers together', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))

  await dictateGatewayBehavior('/telegram/sendVerificationMessage', '+1555', {
    status: 502,
    delayMs: 300,
  })

  const [url, init] = fetch.mock.calls[0]
  expect(url).toBe(`${STAND_GATEWAY_URL}/test/behavior`)
  expect(JSON.parse(String(init?.body))).toEqual({
    path: '/telegram/sendVerificationMessage',
    key: '+1555',
    status: 502,
    delayMs: 300,
  })
})

it('releases a held answer by the route and the key alone', async () => {
  const fetch = vi
    .spyOn(globalThis, 'fetch')
    .mockResolvedValue(new Response(null, { status: 204 }))

  await releaseGatewayAnswer('/model/api/generate', 'bot-reply-1')

  const [url, init] = fetch.mock.calls[0]
  expect(url).toBe(`${STAND_GATEWAY_URL}/test/release`)
  expect(init?.method).toBe('POST')
  expect(JSON.parse(String(init?.body))).toEqual({
    path: '/model/api/generate',
    key: 'bot-reply-1',
  })
})

it('fails with the release route when the gateway refuses a release', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(null, { status: 400 }),
  )

  await expect(
    releaseGatewayAnswer('/model/api/generate', 'bot-reply-1'),
  ).rejects.toThrow('stand gateway refused /test/release: 400')
})
