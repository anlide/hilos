import { describe, expect, it, vi } from 'vitest'
import {
  publicStatusFileSchema,
  publicStatusHeartbeatSchema,
  readPublicStatusFiles,
  type PublicStatusFetch,
} from '../../src/status/index.js'
import {
  component,
  deferred,
  heartbeat,
  legacyStatusFile,
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
      announcementKnown: true,
      title: 'Maintenance',
      componentKeys: ['site'],
      startedAt: NOW,
      lastObservedAt: NOW,
      endedAt: null,
      completionObservedAt: null,
    },
  ],
})

describe('literal public v4', () => {
  it('accepts all declared fields, explicit nulls/empty lists, opaque ids and plain text', () => {
    expect(publicStatusFileSchema.parse(complete)).toEqual(complete)
    expect(publicStatusFileSchema.parse(statusFile())).toEqual(statusFile())
    expect(
      publicStatusHeartbeatSchema.parse(heartbeat({ aliveAt: 0 })),
    ).toEqual(heartbeat({ aliveAt: 0 }))
  })

  it('reads the real v1 archive with two empty lists and rejects reserved nonempty v1 facts', () => {
    const legacy = legacyStatusFile(1, { outages: complete.outages })
    expect(publicStatusFileSchema.parse(legacy)).toEqual(legacy)
    expect(
      publicStatusFileSchema.safeParse({ ...complete, schemaVersion: 1 })
        .success,
    ).toBe(false)
  })

  it.each([
    { announcementKnown: undefined },
    { lastObservedAt: undefined },
    { completionObservedAt: undefined },
    { announcementKnown: 'true' },
    { announcementKnown: false, workId: 'work' },
    { workId: 12 },
    { lastObservedAt: NOW - 1 },
    { lastObservedAt: 1.5 },
    { completionObservedAt: NOW - 1 },
    { endedAt: NOW + 1000, completionObservedAt: null },
    { endedAt: NOW + 1000, completionObservedAt: NOW + 2000 },
    { phase: 'private' },
  ])('rejects unproven maintenance fields %j', (change) => {
    expect(
      publicStatusFileSchema.safeParse({
        ...complete,
        maintenancePeriods: [{ ...complete.maintenancePeriods[0], ...change }],
      }).success,
    ).toBe(false)
  })

  it.each([false, true])(
    'preserves unknown/unannounced classification (%s) and observed-only completion',
    (announcementKnown) => {
      const status = {
        ...complete,
        maintenancePeriods: [
          {
            ...complete.maintenancePeriods[0],
            announcementKnown,
            completionObservedAt: NOW + 2000,
          },
        ],
      }
      expect(publicStatusFileSchema.parse(status)).toEqual(status)
    },
  )

  it.each(Object.keys(complete))('requires status.%s', (key) => {
    const incomplete = { ...complete } as Record<string, unknown>
    delete incomplete[key]
    expect(publicStatusFileSchema.safeParse(incomplete).success).toBe(false)
  })

  it.each([
    { schemaVersion: 5 },
    { revision: 42 },
    { generatedAt: 1.5 },
    { config: { pollIntervalSeconds: 0, staleAfterSeconds: 120 } },
    { config: { pollIntervalSeconds: 30, staleAfterSeconds: '120' } },
    { node: 'private' },
    { components: [{ ...complete.components[0], description: undefined }] },
    { components: [{ ...complete.components[0], state: 'healthy' }] },
    { components: [{ ...complete.components[0], agentType: 100 }] },
    { components: [{ ...complete.components[0], observedSince: undefined }] },
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
    { schemaVersion: 5 },
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
            ? { ...complete, schemaVersion: 5 }
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

  it.each([1, 2, 3, 4] as const)(
    'accepts a coherent supported version %s without losing outage history',
    async (schemaVersion) => {
      const status =
        schemaVersion === 4
          ? statusFile({ outages: complete.outages })
          : legacyStatusFile(schemaVersion, { outages: complete.outages })
      const beat = { ...heartbeat(), schemaVersion }
      expect(
        await readPublicStatusFiles(new AbortController().signal, async (url) =>
          response(url.endsWith('/status.json') ? status : beat),
        ),
      ).toEqual({ kind: 'valid', pair: { status, heartbeat: beat } })
    },
  )

  it.each([
    [1, 2],
    [2, 1],
    [1, 3],
    [2, 3],
    [3, 1],
    [3, 2],
    [1, 4],
    [2, 4],
    [3, 4],
    [4, 1],
    [4, 2],
    [4, 3],
  ] as const)(
    'refuses a mixed supported schema pair %s/%s',
    async (statusVersion, heartbeatVersion) => {
      expect(
        await readPublicStatusFiles(new AbortController().signal, async (url) =>
          response(
            url.endsWith('/status.json')
              ? statusVersion === 4
                ? statusFile()
                : legacyStatusFile(statusVersion)
              : { ...heartbeat(), schemaVersion: heartbeatVersion },
          ),
        ),
      ).toEqual({ kind: 'revision-mismatch' })
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

describe('closed public silence periods', () => {
  const period = {
    id: '0123456789abcdef0123456789abcdef',
    startedAt: -1000,
    endedAt: 0,
  }

  it('accepts signed closed intervals, including an untrimmed start', () => {
    const status = statusFile({ silencePeriods: [period] })
    expect(publicStatusFileSchema.parse(status)).toEqual(status)
  })

  it('accepts canonical signed starts and ordinal ids for equal starts through GET', async () => {
    const status = statusFile({
      silencePeriods: [
        period,
        {
          id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          startedAt: -1000,
          endedAt: 10,
        },
        { id: '00000000000000000000000000000000', startedAt: 0, endedAt: 20 },
      ],
    })
    expect(publicStatusFileSchema.parse(status)).toEqual(status)
    expect(
      await readPublicStatusFiles(new AbortController().signal, async (url) =>
        response(url.endsWith('/status.json') ? status : heartbeat()),
      ),
    ).toEqual({ kind: 'valid', pair: { status, heartbeat: heartbeat() } })
  })

  it('accepts a v3 file with its gaps through GET, as a restored v3 pair still publishes', async () => {
    const status = legacyStatusFile(3, {
      silencePeriods: [
        period,
        { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', startedAt: 0, endedAt: 10 },
      ],
    })
    const beat = heartbeat({ schemaVersion: 3 })
    expect(publicStatusFileSchema.parse(status)).toEqual(status)
    expect(
      await readPublicStatusFiles(new AbortController().signal, async (url) =>
        response(url.endsWith('/status.json') ? status : beat),
      ),
    ).toEqual({ kind: 'valid', pair: { status, heartbeat: beat } })
  })

  it.each([
    'opaque gap',
    '0123456789abcdef0123456789abcdeg',
    '0123456789abcdef0123456789abcde',
    '0123456789abcdef0123456789abcdef0',
    '0123456789ABCDEF0123456789ABCDEF',
    ' 0123456789abcdef0123456789abcdef',
    '0123456789abcdef0123456789abcdef ',
    '0123456789abcdef0123456789abcdef\n',
  ])(
    'rejects malformed silence id %j through schema and coherent GET',
    async (id) => {
      const status = statusFile({ silencePeriods: [{ ...period, id }] })
      expect(publicStatusFileSchema.safeParse(status).success).toBe(false)
      expect(
        await readPublicStatusFiles(new AbortController().signal, async (url) =>
          response(url.endsWith('/status.json') ? status : heartbeat()),
        ),
      ).toEqual({ kind: 'unavailable' })
    },
  )

  it.each([
    {
      order: 'reversed starts',
      periods: [
        { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', startedAt: 20, endedAt: 30 },
        { id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', startedAt: 0, endedAt: 10 },
      ],
    },
    {
      order: 'reversed ids at the same start',
      periods: [
        { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', startedAt: 0, endedAt: 10 },
        { id: '0123456789abcdef0123456789abcdef', startedAt: 0, endedAt: 20 },
      ],
    },
  ])('rejects $order through schema and coherent GET', async ({ periods }) => {
    const status = statusFile({ silencePeriods: periods })
    expect(publicStatusFileSchema.safeParse(status).success).toBe(false)
    expect(
      await readPublicStatusFiles(new AbortController().signal, async (url) =>
        response(url.endsWith('/status.json') ? status : heartbeat()),
      ),
    ).toEqual({ kind: 'unavailable' })
  })

  it.each([
    { id: '' },
    { id: 12 },
    { startedAt: undefined },
    { startedAt: -0.5 },
    { endedAt: undefined },
    { endedAt: null },
    { endedAt: -1000 },
    { endedAt: -1001 },
    { endedAt: Number.MAX_SAFE_INTEGER + 1 },
    { nodeId: 'private' },
    { pid: 42 },
    { reason: 'private' },
    { internalId: 1 },
  ])('rejects invalid bounds and extra/private fields %j', (change) => {
    expect(
      publicStatusFileSchema.safeParse({
        ...statusFile(),
        silencePeriods: [{ ...period, ...change }],
      }).success,
    ).toBe(false)
  })

  it('rejects duplicate ids even when their intervals differ through schema and GET', async () => {
    const status = statusFile({
      silencePeriods: [period, { ...period, startedAt: 1, endedAt: 2 }],
    })
    expect(publicStatusFileSchema.safeParse(status).success).toBe(false)
    expect(
      await readPublicStatusFiles(new AbortController().signal, async (url) =>
        response(url.endsWith('/status.json') ? status : heartbeat()),
      ),
    ).toEqual({ kind: 'unavailable' })
  })

  it.each([1, 2] as const)(
    'preserves the strict legacy %s boundary',
    (schemaVersion) => {
      const legacy = legacyStatusFile(schemaVersion)
      expect(publicStatusFileSchema.parse(legacy)).toEqual(legacy)
      for (const silencePeriods of [[], [period]]) {
        expect(
          publicStatusFileSchema.safeParse({ ...legacy, silencePeriods })
            .success,
        ).toBe(false)
      }
      expect(
        publicStatusFileSchema.safeParse({ ...legacy, generatedAt: -1 })
          .success,
      ).toBe(false)
      expect(
        publicStatusHeartbeatSchema.safeParse(
          heartbeat({ schemaVersion, aliveAt: -1 }),
        ).success,
      ).toBe(false)
    },
  )

  it('keeps real v2 work and maintenance facts without v3 keys', () => {
    const status = legacyStatusFile(2, {
      scheduledWorks: complete.scheduledWorks,
      maintenancePeriods: complete.maintenancePeriods,
    })
    expect(publicStatusFileSchema.parse(status)).toEqual(status)
    expect(
      publicStatusFileSchema.safeParse({ ...status, schemaVersion: 1 }).success,
    ).toBe(false)
    expect(
      publicStatusFileSchema.safeParse({ ...status, schemaVersion: 3 }).success,
    ).toBe(false)
  })
})

describe('component observation moment', () => {
  it('accepts a signed epoch millisecond moment on every v4 component through GET', async () => {
    const status = statusFile({
      components: [
        component({ observedSince: -1000 }),
        component({ componentKey: 'late', name: 'Late', observedSince: NOW }),
      ],
    })
    expect(publicStatusFileSchema.parse(status)).toEqual(status)
    expect(
      await readPublicStatusFiles(new AbortController().signal, async (url) =>
        response(url.endsWith('/status.json') ? status : heartbeat()),
      ),
    ).toEqual({ kind: 'valid', pair: { status, heartbeat: heartbeat() } })
  })

  it.each([
    { observedSince: undefined },
    { observedSince: null },
    { observedSince: 1.5 },
    { observedSince: '2026-01-01' },
    { observedSince: Number.MAX_SAFE_INTEGER + 1 },
  ])(
    'requires an exact moment of every v4 component, refusing %j',
    async (change) => {
      const status = {
        ...statusFile(),
        components: [component(), { ...component(), ...change }],
      }
      expect(publicStatusFileSchema.safeParse(status).success).toBe(false)
      expect(
        await readPublicStatusFiles(new AbortController().signal, async (url) =>
          response(url.endsWith('/status.json') ? status : heartbeat()),
        ),
      ).toEqual({ kind: 'unavailable' })
    },
  )

  it.each([1, 2, 3] as const)(
    'keeps legacy %s components without a moment and refuses one there',
    (schemaVersion) => {
      const legacy = legacyStatusFile(schemaVersion)
      expect(publicStatusFileSchema.parse(legacy)).toEqual(legacy)
      expect(
        publicStatusFileSchema.safeParse({
          ...legacy,
          components: [component()],
        }).success,
      ).toBe(false)
    },
  )
})
