import { EventEmitter } from 'node:events'
import type { Page, Request, Response } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { PUBLIC_STATUS_FILES, watchPublicStatus } from '../publicStatus.js'

/** Keep the browser's asynchronous body capture independent of its headers. */
function observe(text: () => Promise<string>) {
  const page = new EventEmitter()
  const log = watchPublicStatus(page as unknown as Page)
  const request = {
    url: () => `https://example.test${PUBLIC_STATUS_FILES[0]}`,
    method: () => 'GET',
  } as unknown as Request
  const response = {
    request: () => request,
    status: () => 200,
    headers: () => ({ 'cache-control': 'no-store' }),
    text,
    json: async () => JSON.parse(await text()),
  } as unknown as Response
  page.emit('request', request)
  page.emit('response', response)
  return { page, log, read: log.reads[0][0]! }
}

describe('public Status body capture completion', () => {
  it('keeps a header-only read pending until its body is captured and drains on stop', async () => {
    const body = Promise.withResolvers<string>()
    const { page, log, read } = observe(() => body.promise)
    expect(read.status).toBe(200)
    expect(read.headers).toEqual({ 'cache-control': 'no-store' })
    expect(read.completedAt).toBeUndefined()
    expect(read.body).toBeUndefined()
    const stopped = log.stop()
    body.resolve('{"revision":"captured"}')
    await stopped
    expect(read.body).toEqual({ revision: 'captured' })
    expect(read.completedAt).toEqual(expect.any(Number))
    expect(read.failure).toBeUndefined()
    for (const event of ['request', 'response', 'requestfailed', 'websocket'])
      expect(page.listenerCount(event)).toBe(0)
  })

  it('retains a body lost at navigation as a failed observation without claiming completion', async () => {
    const failure = new Error(
      'Protocol error (Network.getResponseBody): No resource with given identifier found',
    )
    const { log, read } = observe(async () => {
      throw failure
    })
    await log.stop()
    expect(log.reads[0]).toEqual([read])
    expect(read.status).toBe(200)
    expect(read.body).toBeUndefined()
    expect(read.failure).toBe(String(failure))
    expect(read.completedAt).toBeUndefined()
  })

  it('marks a captured malformed body complete so strict body assertions still reject it', async () => {
    const { log, read } = observe(async () => 'not JSON')
    await log.stop()
    expect(read.status).toBe(200)
    expect(read.completedAt).toEqual(expect.any(Number))
    expect(read.failure).toContain('SyntaxError')
    expect(read.body).toBeUndefined()
  })
})
