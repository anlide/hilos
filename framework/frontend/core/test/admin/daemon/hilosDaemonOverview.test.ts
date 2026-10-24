import { describe, expect, it, vi } from 'vitest'

import {
  createHilosDaemonOverview,
  daemonCoresBoundHint,
  daemonEnvironmentAttention,
  daemonEnvironmentDifferences,
  daemonMemoryBoundHint,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonNodeLogPath,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  daemonWorkerOccupancy,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonDatabaseAddress,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonUptime,
  readHilosDaemonDatabase,
  readHilosDaemonOverview,
  type HilosDaemonDatabaseData,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
} from '../../../src/admin/daemon/hilosDaemonOverview.js'
import { type HilosDaemonNodeOverviewData } from '../../../src/admin/daemon/hilosDaemonNodeOverview.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

function node(
  nodeId: string,
  overrides: Partial<HilosDaemonOverviewNode> = {},
): HilosDaemonOverviewNode {
  return {
    nodeId,
    online: true,
    role: 'master',
    state: 'standby',
    silentSince: null,
    workers: [
      { index: 0, kind: 'regular', agentCount: 3 },
      { index: 2, kind: 'monopolistic', agentCount: 1 },
      { index: 5, kind: 'monopolistic', agentCount: 0 },
    ],
    measurements: {
      cores: 2,
      daemonCores: 0.5,
      masterMemory: 1024,
      workersMemory: 3072,
      workersMeasured: 2,
      memoryCeiling: 8192,
    },
    standing: { connections: 3 },
    ...overrides,
  }
}

function picture(
  nodes: readonly HilosDaemonOverviewNode[] = [],
): HilosDaemonOverviewData {
  return {
    clustered: true,
    nodes,
    placement: {
      instances: null,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: null,
    },
    consensus: {
      nodeId: 'n1',
      term: 7,
      leaderId: 'n1',
      onlineMasters: 2,
      masters: 3,
      quorumSize: 2,
    },
  }
}

const database: HilosDaemonDatabaseData = {
  host: 'db',
  port: 3306,
  name: 'hilos',
  socket: null,
  migrationIndex: 214,
}

function harness() {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.DAEMON)
  const sendAction = vi.fn()
  const on = vi.fn()
  const overview = createHilosDaemonOverview({
    scopes,
    connection: { sendAction, on } as unknown as HilosConnection,
  })
  return { scopes, page, overview, sendAction, on }
}

describe('Daemon overview readers', () => {
  it('keeps missing, null, malformed and a reported empty picture distinct', () => {
    const { scopes, page } = harness()
    const read = readHilosDaemonOverview(scopes)
    expect(read.get()).toBeNull()
    page.data.set('daemon', null)
    expect(read.get()).toBeNull()
    page.data.set('daemon', { nodes: [] })
    expect(read.get()).toBeNull()
    page.data.set('daemon', picture())
    expect(read.get()?.nodes).toEqual([])
    page.data.set('daemon', { ...picture(), extra: 1 })
    expect(read.get()).toBeNull()
    page.data.set(
      'daemon',
      picture([
        node('solo', {
          role: null,
          state: null,
          workers: null,
          measurements: null,
          standing: null,
        }),
      ]),
    )
    expect(read.get()?.nodes[0]?.workers).toBeNull()
    expect(read.get()?.nodes[0]?.standing).toBeNull()
    scopes.openPage('elsewhere')
    expect(read.get()).toBeNull()
  })

  it('preserves unknown placement, reported zeros and the unplaced type list', () => {
    const { scopes, page } = harness()
    const read = readHilosDaemonOverview(scopes)
    for (const placement of [
      { instances: null, types: 3, clusterTypes: 2, unplacedTypes: null },
      { instances: 0, types: 0, clusterTypes: 0, unplacedTypes: [] },
      {
        instances: 5,
        types: 3,
        clusterTypes: 2,
        unplacedTypes: ['leader', 'policy'],
      },
    ]) {
      page.data.set('daemon', { ...picture(), placement })
      expect(read.get()?.placement).toEqual(placement)
    }
  })

  it.each([
    undefined,
    null,
    {},
    { types: 3, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, types: 3, unplacedTypes: [] },
    { instances: 0, types: 3, clusterTypes: 2 },
    { instances: -1, types: 3, clusterTypes: 2, unplacedTypes: [] },
    { instances: 1.5, types: 3, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, types: null, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, types: -1, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, types: 1.5, clusterTypes: 2, unplacedTypes: [] },
    { instances: 0, types: 3, clusterTypes: null, unplacedTypes: [] },
    { instances: 0, types: 3, clusterTypes: -1, unplacedTypes: [] },
    { instances: 0, types: 3, clusterTypes: 1.5, unplacedTypes: [] },
    { instances: 0, types: 3, clusterTypes: 2, unplacedTypes: [1] },
    { instances: 0, types: 3, clusterTypes: 2, unplacedTypes: 'leader' },
    {
      instances: 0,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: [],
      unplacedCount: 0,
    },
  ])('rejects missing, malformed or enlarged placement: %j', (placement) => {
    const { scopes, page } = harness()
    const read = readHilosDaemonOverview(scopes)
    page.data.set('daemon', { ...picture(), placement })
    expect(read.get()).toBeNull()
  })

  it.each([
    { state: 'candidate' },
    { role: 'leader' },
    {
      workers: [
        { index: 1, kind: 'regular', agentCount: 1, agents: ['secret'] },
      ],
    },
    { workers: [{ index: 1.5, kind: 'regular', agentCount: 1 }] },
    { workers: [{ index: 1, kind: 'other', agentCount: 1 }] },
    { measurements: { ...node('n').measurements, workersMeasured: null } },
    { measurements: { ...node('n').measurements, daemonCores: NaN } },
    { environment: { APP_ENV: 'prod' } },
    { standing: { connections: 1, sessions: 1 } },
    { uptimeMinutes: 1 },
    { workerRestarts24h: 1 },
  ])('rejects a malformed or enlarged node contract: %j', (overrides) => {
    const { scopes, page } = harness()
    const read = readHilosDaemonOverview(scopes)
    page.data.set('daemon', {
      ...picture(),
      nodes: [{ ...node('n'), ...overrides }],
    })
    expect(read.get()).toBeNull()
  })

  it.each([
    'startedAt',
    'coresBound',
    'masterCores',
    'machineLoad',
    'memoryCeilingBound',
    'phpMemoryLimit',
  ])('refuses removed root measurement field %s', (field) => {
    const h = harness()
    const lean = node('n')
    h.page.data.set(
      'daemon',
      picture([
        { ...lean, measurements: { ...lean.measurements!, [field]: null } },
      ]),
    )
    expect(h.overview.overview.get()).toBeNull()
  })

  it('refuses root environmentComparison even when it contains no values', () => {
    const h = harness()
    h.page.data.set('daemon', {
      ...picture(),
      environmentComparison: {
        answeringNodes: 0,
        divergedKeys: null,
        sourcesDifferKeys: null,
      },
    })
    expect(h.overview.overview.get()).toBeNull()
  })

  it('reads the database independently and refuses credentials or partial data', () => {
    const { scopes, page } = harness()
    const read = readHilosDaemonDatabase(scopes)
    expect(read.get()).toBeNull()
    page.data.set('database', database)
    expect(read.get()).toEqual(database)
    for (const malformed of [
      null,
      { host: 'db' },
      { ...database, user: 'root' },
      { ...database, password: 'secret' },
      { ...database, migrationIndex: 2.5 },
    ]) {
      page.data.set('database', malformed)
      expect(read.get()).toBeNull()
    }
    page.data.set('database', { ...database, migrationIndex: 0 })
    expect(read.get()?.migrationIndex).toBe(0)
    page.data.set('database', { ...database, migrationIndex: null })
    expect(read.get()?.migrationIndex).toBeNull()
  })
})

describe('Daemon root projections', () => {
  it('exposes only readonly overview and database signals without a lifecycle or selection', () => {
    const h = harness()
    expect(Object.keys(h.overview)).toEqual(['overview', 'database'])
    expect(h.overview.overview.get()).toBeNull()
    h.page.data.set('daemon', picture([node('z'), node('a')]))
    h.page.data.set('database', database)
    expect(h.overview.overview.get()?.nodes.map((item) => item.nodeId)).toEqual(
      ['z', 'a'],
    )
    expect(h.overview.database.get()).toEqual(database)
    h.page.data.set(
      'daemon',
      picture([node('a', { online: false, state: 'silent' })]),
    )
    expect(h.overview.overview.get()?.nodes[0]?.state).toBe('silent')
    h.scopes.openPage('elsewhere')
    expect(h.overview.overview.get()).toBeNull()
    expect(h.overview.database.get()).toBeNull()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.on).not.toHaveBeenCalled()
  })
})

describe('Daemon diagnostic calculations and formatting', () => {
  it('prints unknown and zero differently, and keeps ratios above 100%', () => {
    expect(formatDaemonCores(null)).toBe('—')
    expect(formatDaemonCores(0)).toBe('0')
    expect(formatDaemonCores(1.25)).toContain('1')
    expect(formatDaemonNumber(undefined)).toBe('—')
    expect(formatDaemonBytes(null)).toBe('—')
    expect(formatDaemonBytes(0)).toBe('0 B')
    expect(formatDaemonBytes(1024)).toBe('1.0 KB')
    expect(formatDaemonBytes(-1)).toBe('—')
    expect(daemonPercentage(3, 2)).toBe(150)
    expect(formatDaemonPercentage(150)).toBe('150%')
    expect(daemonProgressWidth(150)).toBe(100)
    expect(daemonProgressWidth(-10)).toBe(0)
    expect(daemonProgressWidth(null)).toBe(0)
    expect(daemonPercentage(0, 2)).toBe(0)
    expect(formatDaemonPercentage(0)).toBe('0%')
    for (const denominator of [null, undefined, 0, -1, Infinity, NaN]) {
      expect(daemonPercentage(1, denominator)).toBeNull()
    }
    for (const numerator of [null, undefined, -1, Infinity, NaN]) {
      expect(daemonPercentage(numerator, 2)).toBeNull()
    }
    expect(formatDaemonPercentage(null)).toBe('—')
    expect(formatDaemonUptime(null)).toBe('—')
    expect(formatDaemonUptime(0)).toBe('0 min')
    expect(formatDaemonUptime(59)).toBe('59 min')
    expect(formatDaemonUptime(125)).toBe('2 h 5 min')
    expect(formatDaemonUptime(1501)).toBe('1 d 1 h 1 min')
  })

  it('sums only known process memory and counts only existing monopolistic workers', () => {
    const n = node('n')
    expect(daemonTotalMemory(n)).toBe(4096)
    expect(n.measurements?.workersMeasured).toBe(2)
    expect(n.workers).toHaveLength(3)
    expect(daemonMonopolisticCount(n)).toBe(2)
    expect(daemonMonopolisticCount({ ...n, workers: null })).toBeNull()
    expect(daemonMonopolisticCount({ ...n, workers: [] })).toBe(0)
    expect(daemonTotalMemory({ ...n, measurements: null })).toBeNull()
    const m = n.measurements!
    expect(
      daemonTotalMemory({ ...n, measurements: { ...m, masterMemory: null } }),
    ).toBeNull()
    expect(
      daemonTotalMemory({ ...n, measurements: { ...m, workersMemory: null } }),
    ).toBeNull()
    expect(
      daemonTotalMemory({
        ...n,
        measurements: { ...m, workersMemory: 0, masterMemory: 0 },
      }),
    ).toBe(0)
    expect(
      daemonWorkerOccupancy({ index: 5, kind: 'monopolistic', agentCount: 0 }),
    ).toBe('free')
    expect(
      daemonWorkerOccupancy({ index: 5, kind: 'monopolistic', agentCount: 1 }),
    ).toBe('occupied')
  })

  it('keeps comparison unknown until comparable, including silent and unreported nodes', () => {
    const n: HilosDaemonNodeOverviewData = {
      nodeId: 'n',
      online: true,
      workers: [],
      workerRestarts24h: 0,
      uptimeMinutes: 0,
      measurements: null,
      standing: null,
      environment: {
        missingRequired: 1,
        fromExample: 2,
        drifted: 3,
        orphans: 4,
      },
      environmentComparison: {
        answeringNodes: 2,
        divergedKeys: 2,
        sourcesDifferKeys: 1,
      },
    }
    const p = {
      ...n,
      environmentComparison: n.environmentComparison,
    }
    const heading = {
      clustered: true,
      role: null,
      state: null,
      silentSince: null,
    }
    expect(daemonEnvironmentAttention(n)).toBe(10)
    expect(daemonEnvironmentAttention({ ...n, environment: null })).toBeNull()
    expect(daemonEnvironmentDifferences(p, heading)).toBe(3)
    expect(
      daemonEnvironmentDifferences({ ...p, online: false }, heading),
    ).toBeNull()
    expect(
      daemonEnvironmentDifferences({ ...p, environment: null }, heading),
    ).toBeNull()
    expect(
      daemonEnvironmentDifferences(p, { ...heading, clustered: false }),
    ).toBeNull()
    expect(
      daemonEnvironmentDifferences(
        {
          ...p,
          environmentComparison: {
            answeringNodes: 1,
            divergedKeys: null,
            sourcesDifferKeys: null,
          },
        },
        heading,
      ),
    ).toBeNull()
    expect(
      daemonEnvironmentDifferences(
        {
          ...p,
          environmentComparison: {
            answeringNodes: 2,
            divergedKeys: 0,
            sourcesDifferKeys: 0,
          },
        },
        heading,
      ),
    ).toBe(0)
  })

  it('names each reported limiting source', () => {
    expect(daemonCoresBoundHint('quota')).toBe('Container CPU quota')
    expect(daemonCoresBoundHint('cpus')).toContain('nproc')
    expect(daemonCoresBoundHint(null)).toBe('—')
    expect(daemonMemoryBoundHint('cgroup')).toBe('Container memory limit')
    expect(daemonMemoryBoundHint('machine')).toBe('Physical machine memory')
    expect(daemonMemoryBoundHint(null)).toBe('—')
  })
})

describe('Daemon addresses', () => {
  it('formats IPv6 and treats a Unix socket as a separate address', () => {
    expect(formatDaemonDatabaseAddress(database)).toBe('mysql://db:3306/hilos')
    expect(formatDaemonDatabaseAddress({ ...database, host: '::1' })).toBe(
      'mysql://[::1]:3306/hilos',
    )
    expect(formatDaemonDatabaseAddress({ ...database, host: '[::1]' })).toBe(
      'mysql://[::1]:3306/hilos',
    )
    expect(
      formatDaemonDatabaseAddress({ ...database, socket: '/run/mysql.sock' }),
    ).toBe('hilos')
    expect(formatDaemonDatabaseAddress(null)).toBe('—')
  })

  it('delegates raw node params to the router without choosing a log stream', () => {
    const id = 'node /?#'
    expect(daemonNodeChildParams(id)).toEqual({ nodeId: id })
    expect(daemonNodeChildParams(null)).toEqual({})
    const resolve = vi.fn(() => '/resolved-node-log')
    expect(daemonNodeLogPath(resolve, id)).toBe('/resolved-node-log')
    expect(resolve).toHaveBeenCalledWith(HilosPages.LOGS_VIEW, {
      nodeId: id,
      source: 'live',
    })
    expect(daemonNodeLogPath(() => undefined, id)).toBeUndefined()
  })
})
