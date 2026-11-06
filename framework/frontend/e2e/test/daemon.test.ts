import { describe, expect, it } from 'vitest'
import type { Expect, Page } from '@playwright/test'

import {
  DAEMON_NODE_TABS,
  expectDaemonNodeAnswer,
  expectDaemonCronQuery,
  readDaemonCronMoment,
  readDaemonNodeOverview,
  readDaemonSectionOverview,
  readDaemonWebsocketsCount,
  type DaemonPageFrame,
} from '../daemon.js'
import { createSocketFrameReader } from '../socket.js'

const rowKey = 'hilos_sessions_library/hilos_sessions_expire'
const ruleRow = (nextRunAt: number) => ({
  rowKey,
  slots: {
    rule: {
      rowKey,
      lastRunAt: null,
      nextRunAt,
      idleReason: null,
    },
  },
})
const initial = {
  type: 'page_response',
  data: {
    page: 'hilos_daemon_cron',
    payload: {
      windows: {
        hilosDaemonCron: {
          filter: {},
          rows: [ruleRow(1_800_000_000_000)],
        },
      },
    },
  },
}

describe('Cron query evidence and ordering', () => {
  const mark = { documentStarted: 1, socket: 2, cursor: 10, rendered: [] }
  const browser = {
    url: () => 'http://localhost/hilos/daemon/node/standalone/cron',
  } as Page
  const checks = Object.assign(
    (value: unknown, message?: string) => expect(value, message),
    {
      any: expect.any,
      // One observation makes absence fail immediately, without a timer budget.
      poll: (read: () => Promise<unknown>) => ({
        toEqual: async (wanted: unknown) =>
          expect(await read()).toEqual(wanted),
      }),
    },
  ) as unknown as Expect
  const filter = { node: 'standalone' }
  const rules = [
    { rowKey: 'z', name: 'zeta', agentId: null, nextRunAt: 1_800_000_120_000 },
    { rowKey: 'nb', name: 'gamma', agentId: null, nextRunAt: null },
    {
      rowKey: 'b',
      name: 'alpha',
      agentId: 'worker',
      nextRunAt: 1_800_000_060_000,
    },
    { rowKey: 'na', name: 'gamma', agentId: null, nextRunAt: null },
    {
      rowKey: 'a',
      name: 'alpha',
      agentId: 'worker',
      nextRunAt: 1_800_000_060_000,
    },
    {
      rowKey: 'x',
      name: 'beta',
      agentId: 'alpha-worker',
      nextRunAt: 1_800_000_000_000,
    },
  ]
  type Sort = Parameters<typeof expectDaemonCronQuery>[5]
  const rowsFor = (
    keys: readonly string[],
    source: readonly Record<string, unknown>[] = rules,
  ) =>
    keys.map((key) => ({
      rowKey: key,
      slots: { rule: source.find((rule) => rule.rowKey === key)! },
    }))
  function request(sort: Sort = null, query = filter) {
    return {
      direction: 'sent' as const,
      socket: 2,
      index: 10,
      wire: {
        type: 'table_viewport',
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
        requestId: 'current',
        filter: query,
        sort,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: null,
      },
    }
  }
  function response(
    keys: readonly string[] = [],
    source: readonly Record<string, unknown>[] = rules,
  ) {
    return {
      direction: 'received' as const,
      socket: 2,
      index: 11,
      wire: {
        type: 'table_window',
        data: {
          page: 'hilos_daemon_cron',
          tableKey: 'hilosDaemonCron',
          requestId: 'current',
          rows: rowsFor(keys, source),
        },
      },
    }
  }
  const observe = (frames: unknown[]) =>
    ({
      read: async () => ({ frames }),
    }) as Parameters<typeof expectDaemonCronQuery>[2]

  it.each([
    ['before mark', { index: 8 }, {}],
    ['another socket', { socket: 3 }, {}],
    ['another page', {}, { page: 'hilos_daemon_workers' }],
    ['another table', {}, { tableKey: 'hilosDaemonWorkers' }],
    ['another node', {}, { filter: { node: 'elsewhere' } }],
    ['another sort', {}, { sort: [{ field: 'name', direction: 'asc' }] }],
    ['another type', {}, { type: 'page_subscribe' }],
    ['missing request id', {}, { requestId: undefined }],
  ])('does not accept a request %s', async (_label, envelope, wire) => {
    const sent = request()
    const wrong = { ...sent, ...envelope, wire: { ...sent.wire, ...wire } }
    const reply = response()
    const frames = [wrong, reply]
    await expect(
      expectDaemonCronQuery(
        checks,
        browser,
        observe(frames),
        mark,
        filter,
        null,
        [],
      ),
    ).rejects.toThrow()
    frames.splice(1, 0, sent)
    const accepted = await expectDaemonCronQuery(
      checks,
      browser,
      observe(frames),
      mark,
      filter,
      null,
      [],
    )
    expect(accepted.sent).toBe(sent)
    expect(accepted.reply).toBe(reply)
  })

  it.each([
    ['before the request', { index: 9 }, {}],
    ['same physical position', { index: 10 }, {}],
    ['another socket', { socket: 3 }, {}],
    ['another request id', {}, { requestId: 'previous' }],
    ['another page', {}, { page: 'hilos_daemon_workers' }],
    ['another table', {}, { tableKey: 'hilosDaemonWorkers' }],
  ])('ignores an empty window from %s', async (_label, envelope, data) => {
    const sent = request()
    const reply = response()
    const wrong = {
      ...reply,
      ...envelope,
      wire: { ...reply.wire, data: { ...reply.wire.data, ...data } },
    }
    const frames = [sent, wrong]
    await expect(
      expectDaemonCronQuery(
        checks,
        browser,
        observe(frames),
        mark,
        filter,
        null,
        [],
      ),
    ).rejects.toThrow()
    frames.push(reply)
    const accepted = await expectDaemonCronQuery(
      checks,
      browser,
      observe(frames),
      mark,
      filter,
      null,
      [],
    )
    expect(accepted.reply).toBe(reply)
    expect(accepted.rows).toEqual([])
    expect(accepted.keys).toEqual([])
  })

  it('waits for the newest matching request instead of accepting an older own reply', async () => {
    const previous = request()
    const sent = {
      ...previous,
      index: 12,
      wire: { ...previous.wire, requestId: 'latest' },
    }
    const frames = [previous, response(), sent]
    await expect(
      expectDaemonCronQuery(
        checks,
        browser,
        observe(frames),
        mark,
        filter,
        null,
        [],
      ),
    ).rejects.toThrow()
    const reply = response()
    reply.index = 13
    reply.wire.data.requestId = 'latest'
    frames.push(reply)
    const accepted = await expectDaemonCronQuery(
      checks,
      browser,
      observe(frames),
      mark,
      filter,
      null,
      [],
    )
    expect(accepted.sent).toBe(sent)
    expect(accepted.reply).toBe(reply)
  })

  it.each(['alpha', 'ALPHA', ' \tAlPhA\r\n\0\v'])(
    'matches name OR agentId without case and trims search edges: %j',
    async (search) => {
      const query = { ...filter, search }
      const frames = [request(null, query), response(['b', 'a', 'x'])]
      const accepted = await expectDaemonCronQuery(
        checks,
        browser,
        observe(frames),
        mark,
        query,
        null,
        rules,
      )
      expect(accepted.keys).toEqual(['b', 'a', 'x'])
      frames[1] = response(['a', 'b', 'x'])
      await expect(
        expectDaemonCronQuery(
          checks,
          browser,
          observe(frames),
          mark,
          query,
          null,
          rules,
        ),
      ).rejects.toThrow()
      for (const keys of [[], ['b', 'a'], ['x'], ['b', 'a', 'x', 'z']]) {
        frames[1] = response(keys)
        await expect(
          expectDaemonCronQuery(
            checks,
            browser,
            observe(frames),
            mark,
            query,
            null,
            rules,
          ),
        ).rejects.toThrow()
      }
    },
  )

  it.each([
    [' \t\r\n\0\v', ['z', 'nb', 'b', 'na', 'a', 'x']],
    ['alpha worker', []],
    ['\falpha\f', []],
    ['\u00a0alpha\u00a0', []],
  ] as const)(
    'normalizes only PHP search edge characters: %j',
    async (search, keys) => {
      const query = { ...filter, search }
      const accepted = await expectDaemonCronQuery(
        checks,
        browser,
        observe([request(null, query), response(keys)]),
        mark,
        query,
        null,
        rules,
      )
      expect(accepted.keys).toEqual(keys)
    },
  )

  it('accepts a current empty search result but refuses an empty answer for matching rules', async () => {
    const query = { ...filter, search: 'missing' }
    const reply = response()
    const accepted = await expectDaemonCronQuery(
      checks,
      browser,
      observe([request(null, query), reply]),
      mark,
      query,
      null,
      rules,
    )
    expect(accepted.reply).toBe(reply)
    expect(accepted.keys).toEqual([])
    await expect(
      expectDaemonCronQuery(
        checks,
        browser,
        observe([request(), reply]),
        mark,
        filter,
        null,
        rules,
      ),
    ).rejects.toThrow()
  })

  it.each([
    ['numeric suffixes', ['rule-10', 'rule-2']],
    ['case-insensitive ties with binary keys', ['RULE-2', 'rule-2']],
    ['embedded leading zeros', ['rule-02', 'rule-010']],
    ['leading numeric zeros', ['02', '2']],
    ['ASCII punctuation', ['rule_2', 'ruleA2']],
    ['whitespace before digits', ['rule 10', 'rule2']],
    ['non-ASCII bytes', ['é2', 'É2']],
    ['large digit runs', ['rule-9007199254740993', 'rule-9007199254740992']],
  ] as const)(
    'orders %s naturally in both directions',
    async (_label, names) => {
      const initialRules = [
        { ...rules[0]!, rowKey: 'a', name: names[0] },
        { ...rules[0]!, rowKey: 'B', name: names[1] },
      ]
      for (const [direction, keys] of [
        ['asc', ['B', 'a']],
        ['desc', ['a', 'B']],
      ] as const) {
        const sort = [{ field: 'name', direction }] as const
        const frames = [request(sort), response(keys, initialRules)]
        const accepted = await expectDaemonCronQuery(
          checks,
          browser,
          observe(frames),
          mark,
          filter,
          sort,
          initialRules,
        )
        expect(accepted.keys).toEqual(keys)
        frames[1] = response([...keys].reverse(), initialRules)
        await expect(
          expectDaemonCronQuery(
            checks,
            browser,
            observe(frames),
            mark,
            filter,
            sort,
            initialRules,
          ),
        ).rejects.toThrow()
      }
    },
  )

  it.each([
    ['name', 'asc', ['a', 'b', 'x', 'na', 'nb', 'z']],
    ['name', 'desc', ['z', 'nb', 'na', 'x', 'b', 'a']],
    ['nextRunAt', 'asc', ['x', 'a', 'b', 'z', 'na', 'nb']],
    ['nextRunAt', 'desc', ['z', 'b', 'a', 'x', 'nb', 'na']],
  ] as const)(
    'checks %s %s, ties and trailing nulls using the received values',
    async (field, direction, keys) => {
      const sort = [{ field, direction }]
      // The original moments would demand a different order; only the reply is current.
      const initialRules = rules.map((rule, index) => ({
        ...rule,
        nextRunAt: 1_800_000_000_000 + index,
      }))
      const frames = [request(sort), response(keys)]
      const accepted = await expectDaemonCronQuery(
        checks,
        browser,
        observe(frames),
        mark,
        filter,
        sort,
        initialRules,
      )
      expect(accepted.keys).toEqual(keys)
      expect(accepted.rows).toEqual(rowsFor(keys))
      frames[1] = response([...keys].reverse())
      await expect(
        expectDaemonCronQuery(
          checks,
          browser,
          observe(frames),
          mark,
          filter,
          sort,
          initialRules,
        ),
      ).rejects.toThrow()
    },
  )

  it.each([
    ['reversed equal moments', ['x', 'b', 'a', 'z', 'na', 'nb']],
    ['null before a moment', ['na', 'x', 'a', 'b', 'z', 'nb']],
    ['reversed null ties', ['x', 'a', 'b', 'z', 'nb', 'na']],
  ])('refuses %s', async (_label, keys) => {
    const sort = [{ field: 'nextRunAt', direction: 'asc' }] as const
    await expect(
      expectDaemonCronQuery(
        checks,
        browser,
        observe([request(sort), response(keys)]),
        mark,
        filter,
        sort,
        rules,
      ),
    ).rejects.toThrow()
  })
})

describe('real Cron moment evidence', () => {
  it('keeps data-only page updates, then replaces the entire window including an absent row', () => {
    const read = createSocketFrameReader(1)
    const frames = read(JSON.stringify(initial))
    frames.push(
      ...read(
        JSON.stringify({
          type: 'page_response',
          data: {
            page: 'hilos_daemon_cron',
            payload: { data: { cronReported: true } },
          },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)?.frame).toBe(frames[0])
    const replacement = {
      type: 'table_window',
      data: {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
        totalCount: 1,
        totalExact: true,
        limit: 25,
        firstAnchor: null,
        lastAnchor: null,
        rows: [ruleRow(1_800_000_060_000)],
      },
    }
    frames.push(...read(JSON.stringify(replacement)))
    expect(frames.map((frame) => frame.failure)).toEqual([
      undefined,
      undefined,
      undefined,
    ])
    expect(readDaemonCronMoment(frames, rowKey)?.rule.nextRunAt).toBe(
      1_800_000_060_000,
    )
    frames.push(
      ...read(
        JSON.stringify({
          ...replacement,
          data: { ...replacement.data, rows: [] },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)).toBeUndefined()
  })

  it('reads batched live edits in order, ignores other addresses and retains decoder failures', () => {
    const read = createSocketFrameReader(2)
    const update = {
      type: 'table_viewport_delta',
      data: {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
        rowKey,
        kind: 'row_updated',
        row: ruleRow(1_800_000_120_000),
      },
    }
    const raw = JSON.stringify({
      type: 'signal_batch',
      data: {
        frames: [
          initial,
          update,
          {
            ...update,
            data: { ...update.data, page: 'elsewhere', row: ruleRow(42) },
          },
        ],
      },
    })
    const frames = read(raw)
    const current = readDaemonCronMoment(frames, rowKey)!
    expect(frames.every((frame) => frame.failure === undefined)).toBe(true)
    expect(current.rule.nextRunAt).toBe(1_800_000_120_000)
    expect(current.frame).toBe(frames[1])
    expect(current.frame.raw).toBe(raw)
    expect(current.frame.message).toBe(1)
    expect(current.frame.socket).toBe(2)
    frames.push(...read('{'))
    expect(frames.at(-1)?.failure).toBeDefined()
    expect(readDaemonCronMoment(frames, rowKey)).toEqual(current)
    frames.push(
      ...read(
        JSON.stringify({
          ...update,
          data: { ...update.data, kind: 'row_removed' },
        }),
      ),
    )
    expect(readDaemonCronMoment(frames, rowKey)).toBeUndefined()
  })
})

describe('cold Node answer and its delivered live overview', () => {
  const overview = {
    nodeId: 'standalone',
    online: true,
    workers: [],
    workerRestarts24h: 0,
    uptimeMinutes: 0,
    measurements: null,
    standing: { sessions: 0, connections: 0 },
    environment: null,
    environmentComparison: {
      answeringNodes: 1,
      divergedKeys: null,
      sourcesDifferKeys: null,
    },
  }
  const head = {
    pageLabel: 'Node',
    pageHeadOwner: {
      page: 'hilos_daemon_node',
      label: 'Node',
      lead: 'One node: processor, memory, environment summary, and maintenance.',
      icon: 'bi-hdd-network',
    },
    pageTabs: DAEMON_NODE_TABS.map(({ key, label }) => ({ page: key, label })),
    node: {
      clustered: false,
      name: null,
      state: null,
      silentSince: null,
      clock: null,
      role: null,
    },
  }
  function frame(data: Record<string, unknown>): DaemonPageFrame {
    const wire = {
      type: 'page_response',
      data: { page: 'hilos_daemon_node', payload: { data } },
    }
    return { type: 'page_response', raw: JSON.stringify(wire), wire }
  }
  function page(read: (id: string) => string): Page {
    return {
      getByTestId: (id: string) => ({ innerText: async () => read(id) }),
    } as unknown as Page
  }
  const check = (browser: Page, frames: DaemonPageFrame[]) =>
    expectDaemonNodeAnswer(expect as unknown as Expect, browser, frames)

  it('waits past an explicit cold null for a full live report and preserves known zero', async () => {
    const frames = [frame({ ...head, overview: null })]
    let reads = 0
    const browser = page((id) => {
      reads++
      if (reads === 2) frames.push(frame({ overview }))
      return id === 'hilos-daemon-uptime' ? '0 min' : '—'
    })
    await check(browser, frames)
    expect(reads).toBeGreaterThan(2)
    expect(frames).toHaveLength(2)
    expect(readDaemonNodeOverview(frames[0])).toBeNull()
    expect(readDaemonNodeOverview(frames[1])?.uptimeMinutes).toBe(0)
  })

  it.each([
    [0, '0 min'],
    [null, '—'],
    [61, '1 h 1 min'],
    [1501, '1 d 1 h 1 min'],
  ])('accepts a complete first reply with uptime %s', async (minutes, text) => {
    const frames = [
      frame({ ...head, overview: { ...overview, uptimeMinutes: minutes } }),
    ]
    await check(
      page((id) => (id === 'hilos-daemon-uptime' ? text : '—')),
      frames,
    )
    expect(frames).toHaveLength(1)
  })

  it('rechecks the latest report after DOM reads, including a new null before recovery', async () => {
    const frames = [frame({ ...head, overview })]
    let reads = 0
    const browser = page((id) => {
      reads++
      if (reads === 2) frames.push(frame({ overview: null }))
      if (reads === 4)
        frames.push(frame({ overview: { ...overview, uptimeMinutes: 1 } }))
      return id === 'hilos-daemon-uptime'
        ? reads <= 4
          ? '0 min'
          : '1 min'
        : '—'
    })
    await check(browser, frames)
    expect(reads).toBeGreaterThan(4)
    expect(frames).toHaveLength(3)
  })

  it.each([
    {},
    { overview: undefined },
    { overview: {} },
    { overview: { ...overview, nodeId: 'other' } },
    { overview: { ...overview, uptimeMinutes: -1 } },
    { overview: { ...overview, environmentComparison: null } },
    { overview: { ...overview, standing: { connections: 0 } } },
  ])(
    'refuses missing or malformed slots instead of treating them as null %#',
    (data) => {
      expect(() => readDaemonNodeOverview(frame(data))).toThrow()
    },
  )

  it('refuses a wrong page or envelope even when its slot is null', () => {
    const source = frame({ overview: null })
    for (const wire of [
      {
        type: 'table_window',
        data: {
          page: 'hilos_daemon_node',
          payload: { data: { overview: null } },
        },
      },
      {
        type: 'page_response',
        data: {
          page: 'hilos_daemon_websockets',
          payload: { data: { overview: null } },
        },
      },
    ]) {
      expect(() => readDaemonNodeOverview({ ...source, wire })).toThrow()
    }
  })

  it.each(['pageLabel', 'pageHeadOwner', 'pageTabs', 'node'])(
    'requires initial %s even with a legal cold null',
    async (field) => {
      await expect(
        check(
          page(() => '—'),
          [frame({ ...head, [field]: undefined, overview: null })],
        ),
      ).rejects.toThrow()
    },
  )
})

describe('Daemon section browser projection', () => {
  const overview = {
    clustered: true,
    nodes: [],
    entryPoints: { nodeId: 'm1', commandPort: 0, httpPort: null },
    placement: { instances: 0, types: 0, clusterTypes: 0, unplacedTypes: [] },
    consensus: null,
  }
  const reply = (daemon: unknown) => ({
    type: 'page_response',
    data: { page: 'hilos_daemon_placement', payload: { data: { daemon } } },
  })

  it('reads the nested tuple from a logical batched browser reply without confusing zero and null', () => {
    const raw = JSON.stringify({
      type: 'signal_batch',
      data: { frames: [reply(overview), reply(null)] },
    })
    const frames = createSocketFrameReader(1)(raw)
    expect(readDaemonSectionOverview(frames[0]!)).toEqual(overview)
    expect(readDaemonSectionOverview(frames[1]!)).toBeNull()
    expect(frames[0]!.raw).toBe(raw)
  })

  it.each([
    undefined,
    { ...overview, entryPoints: undefined },
    {
      ...overview,
      entryPoints: { nodeId: 'm1', commandPort: '8094', httpPort: 8090 },
    },
    {
      ...overview,
      entryPoints: { nodeId: 'm1', commandPort: 8094, httpPort: 65536 },
    },
  ])('rejects an incomplete or mistyped nested source %#', (daemon) => {
    const frame = createSocketFrameReader(1)(JSON.stringify(reply(daemon)))[0]!
    expect(() => readDaemonSectionOverview(frame)).toThrow()
  })

  it('rejects a node response in place of the section projection', () => {
    const frame = createSocketFrameReader(1)(
      JSON.stringify({
        type: 'page_response',
        data: { page: 'hilos_daemon_node', payload: { data: { overview } } },
      }),
    )[0]!
    expect(() => readDaemonSectionOverview(frame)).toThrow()
  })
})

describe('WebSockets evidence belongs to the last own delivered payload', () => {
  function frame(
    data: Record<string, unknown>,
    page = 'hilos_daemon_websockets',
  ): DaemonPageFrame {
    const wire = { type: 'page_response', data: { page, payload: { data } } }
    return { type: 'page_response', raw: JSON.stringify(wire), wire }
  }
  it('preserves zero and reads live count updates without taking another page standing', () => {
    const frames = [frame({ websockets: { connections: 12, dataNode: false } })]
    expect(readDaemonWebsocketsCount(frames)).toBe(12)
    frames.push(frame({ websockets: { connections: 0, dataNode: false } }))
    frames.push(
      frame(
        { websockets: { connections: 999, dataNode: false } },
        'hilos_daemon_node',
      ),
    )
    frames.push(frame({ node: { state: 'leader' } }))
    expect(readDaemonWebsocketsCount(frames)).toBe(0)
  })
  it.each([
    null,
    { connections: null, dataNode: false },
    { connections: 9, dataNode: true },
    { connections: -1, dataNode: false },
    { connections: 1.5, dataNode: false },
    { connections: 3, dataNode: false, extra: true },
  ])(
    'does not fall back to an earlier count after the last slot became unknown %#',
    (websockets) => {
      const frames = [
        frame({ websockets: { connections: 12, dataNode: false } }),
        frame({ websockets }),
      ]
      expect(readDaemonWebsocketsCount(frames)).toBeNull()
    },
  )
  it('does not accept a page-window count as a report', () => {
    const report = frame({ websockets: { connections: 5, dataNode: false } })
    const window = {
      ...report,
      type: 'table_window' as const,
      wire: {
        type: 'table_window',
        data: {
          page: 'hilos_daemon_websockets',
          payload: {
            data: { websockets: { connections: 99, dataNode: false } },
          },
        },
      },
    }
    expect(readDaemonWebsocketsCount([report, window])).toBe(5)
    expect(readDaemonWebsocketsCount([])).toBeNull()
  })
})
