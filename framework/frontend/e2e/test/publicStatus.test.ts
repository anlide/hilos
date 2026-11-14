import { EventEmitter } from 'node:events'
import type { Page, Request, Response } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import {
  PUBLIC_STATUS_FILES,
  expectedPublicStatusArchiveDays,
  watchPublicStatus,
} from '../publicStatus.js'

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

describe('public archive silence oracle', () => {
  const status = {
    schemaVersion: 4 as const,
    revision: 'accepted',
    generatedAt: 100,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [
      {
        componentKey: 'api',
        name: 'API',
        description: null,
        state: 'operational' as const,
        observedSince: 0,
      },
    ],
    candidates: [],
    scheduledWorks: [],
    maintenancePeriods: [],
    silencePeriods: [],
    outages: [
      {
        id: 'outage',
        componentKey: 'api',
        severity: 'major_outage' as const,
        detectedAt: 10,
        publishedAt: 11,
        resolvedAt: 30,
        updates: [],
      },
    ],
  }
  const days = [
    { start: 0, end: 100, label: 'First' },
    { start: 100, end: 200, label: 'Second' },
  ]
  const saved = ['First · Major outage', 'Second · Operational']

  it('keeps every unaffected label exact and retains an event portion before the gap', () => {
    expect(
      expectedPublicStatusArchiveDays(
        saved,
        status,
        days,
        [{ startedAt: 20, endedAt: 50 }],
        200,
      ),
    ).toEqual([
      'First · No data · Major outage recorded',
      'Second · Operational',
    ])
  })
  it('does not claim an event wholly inside silence and respects half-open day boundaries', () => {
    expect(
      expectedPublicStatusArchiveDays(
        saved,
        status,
        days,
        [{ startedAt: 10, endedAt: 100 }],
        200,
      ),
    ).toEqual(['First · No data', 'Second · Operational'])
  })
  it('retains a known event after the gap and treats an open gap through the explicit clock', () => {
    expect(
      expectedPublicStatusArchiveDays(
        saved,
        status,
        days,
        [
          { startedAt: 0, endedAt: 20 },
          { startedAt: 100, endedAt: null },
        ],
        150,
      ),
    ).toEqual(['First · No data · Major outage recorded', 'Second · No data'])
  })
})
