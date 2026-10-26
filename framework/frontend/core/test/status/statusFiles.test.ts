import { describe, expect, it, vi } from 'vitest'
import {
  publicStatusFileSchema,
  publicStatusHeartbeatSchema,
  readPublicStatusFiles,
  type PublicStatusFetch,
} from '../../src/status/index.js'
import {
  deferred,
  heartbeat,
  NOW,
  outage,
  response,
  statusFile,
} from './fixtures.js'

const complete = statusFile({
  candidates: [{ componentKey: 'site', detectedAt: NOW }],
  outages: [
    outage({
      updates: [{ at: NOW, source: 'team', text: '<b>Investigating</b>' }],
    }),
  ],
  scheduledWorks: [
    {
      id: 'opaque work',
      title: 'Upgrade',
      description: null,
      componentKeys: ['site'],
      startsAt: NOW,
      endsAt: NOW + 1000,
    },
  ],
  maintenancePeriods: [
    {
      id: 'opaque period',
      workId: null,
      title: 'Maintenance',
      componentKeys: ['site'],
      startedAt: NOW,
      endedAt: null,
    },
  ],
})

describe('literal public v1', () => {
  it('accepts all declared fields, explicit nulls/empty lists, opaque ids and plain text', () => {
    expect(publicStatusFileSchema.parse(complete)).toEqual(complete)
    expect(publicStatusFileSchema.parse(statusFile())).toEqual(statusFile())
    expect(
      publicStatusHeartbeatSchema.parse(heartbeat({ aliveAt: 0 })),
    ).toEqual(heartbeat({ aliveAt: 0 }))
  })

  it.each(Object.keys(complete))('requires status.%s', (key) => {
    const incomplete = { ...complete } as Record<string, unknown>
    delete incomplete[key]
    expect(publicStatusFileSchema.safeParse(incomplete).success).toBe(false)
  })

  it.each([
    { schemaVersion: 2 },
    { revision: 42 },
    { generatedAt: 1.5 },
    { config: { pollIntervalSeconds: 0, staleAfterSeconds: 120 } },
    { config: { pollIntervalSeconds: 30, staleAfterSeconds: '120' } },
    { node: 'private' },
    { components: [{ ...complete.components[0], description: undefined }] },
    { components: [{ ...complete.components[0], state: 'healthy' }] },
    { components: [{ ...complete.components[0], agentType: 100 }] },
    { candidates: [{ ...complete.candidates[0], deadline: NOW }] },
    { outages: [{ ...complete.outages[0], suppressed: false }] },
    {
      outages: [
        {
          ...complete.outages[0],
          updates: [{ at: NOW, source: 'internal', text: 'x' }],
        },
      ],
    },
    {
      scheduledWorks: [
        { ...complete.scheduledWorks[0], startsAt: '2026-01-01' },
      ],
    },
    {
      maintenancePeriods: [
        { ...complete.maintenancePeriods[0], phase: 'freeze' },
      ],
    },
    {
      maintenancePeriods: [
        { ...complete.maintenancePeriods[0], workId: undefined },
      ],
    },
  ])('rejects malformed/extra status fields %j', (change) => {
    expect(
      publicStatusFileSchema.safeParse({ ...complete, ...change }).success,
    ).toBe(false)
  })

  it.each([
    { schemaVersion: 2 },
    { aliveAt: 0.5 },
    { aliveAt: 'today' },
    { aliveAt: Number.MAX_SAFE_INTEGER + 1 },
    { revision: null },
    { pid: 1 },
  ])('rejects malformed/extra heartbeat fields %j', (change) => {
    expect(
      publicStatusHeartbeatSchema.safeParse({ ...heartbeat(), ...change })
        .success,
    ).toBe(false)
  })

  it.each(['schemaVersion', 'revision', 'aliveAt'])(
    'requires heartbeat.%s',
    (key) => {
      const incomplete = { ...heartbeat() } as Record<string, unknown>
      delete incomplete[key]
      expect(publicStatusHeartbeatSchema.safeParse(incomplete).success).toBe(
        false,
      )
    },
  )
})

describe('public GET boundary', () => {
  it('reads only two same-origin exact URLs, no-store, with the caller abort signal', async () => {
    const fetchFile = vi.fn<PublicStatusFetch>(async (url) =>
      response(url.endsWith('/status.json') ? complete : heartbeat()),
    )
    const abort = new AbortController()
    const result = await readPublicStatusFiles(abort.signal, fetchFile)
    expect(result).toEqual({
      kind: 'valid',
      pair: { status: complete, heartbeat: heartbeat() },
    })
    expect(fetchFile.mock.calls.map(([url]) => url)).toEqual([
      '/status-data/status.json',
      '/status-data/heartbeat.json',
    ])
    for (const [, init] of fetchFile.mock.calls) {
      expect(init).toMatchObject({
        cache: 'no-store',
        signal: abort.signal,
        mode: 'same-origin',
        redirect: 'error',
      })
    }
  })

  it.each(['http', 'json', 'network', 'version', 'private'])(
    'keeps %s failure neutral',
    async (failure) => {
      const fetchFile: PublicStatusFetch = async (url) => {
        if (url.endsWith('/heartbeat.json')) return response(heartbeat())
        if (failure === 'network') throw new Error('private driver details')
        if (failure === 'json')
          return {
            ok: true,
            json: async () => {
              throw new SyntaxError('bad JSON')
            },
          }
        return response(
          failure === 'version'
            ? { ...complete, schemaVersion: 2 }
            : failure === 'private'
              ? { ...complete, token: 'secret' }
              : complete,
          failure !== 'http',
        )
      }
      expect(
        await readPublicStatusFiles(new AbortController().signal, fetchFile),
      ).toEqual({ kind: 'unavailable' })
    },
  )

  it('distinguishes a publication race without accepting either half', async () => {
    const fetchFile: PublicStatusFetch = async (url) =>
      response(
        url.endsWith('/status.json')
          ? complete
          : heartbeat({ revision: 'next' }),
      )
    expect(
      await readPublicStatusFiles(new AbortController().signal, fetchFile),
    ).toEqual({ kind: 'revision-mismatch' })
  })

  it('settles the remaining endpoint on one failure, and refuses aborted late success', async () => {
    const pending = deferred<Pick<Response, 'ok' | 'json'>>()
    const abort = new AbortController()
    const settled = vi.fn()
    const promise = readPublicStatusFiles(abort.signal, async (url) =>
      url.endsWith('/status.json')
        ? response(complete, false)
        : pending.promise,
    ).then(settled)
    await Promise.resolve()
    expect(settled).not.toHaveBeenCalled()
    abort.abort()
    pending.resolve(response(heartbeat()))
    await promise
    expect(settled).toHaveBeenCalledWith({ kind: 'unavailable' })
  })
})
