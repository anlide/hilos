import { describe, expect, it } from 'vitest'
import {
  DAEMON_NODE_OVERVIEW_DATA,
  daemonNodeOverviewSchema,
  readHilosDaemonNodeOverview,
  type HilosDaemonNodeOverviewData,
} from '../../../src/admin/daemon/hilosDaemonNodeOverview.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import {
  daemonEnvironmentAttention,
  daemonEnvironmentDifferences,
  daemonMonopolisticCount,
  daemonTotalMemory,
} from '../../../src/admin/daemon/hilosDaemonOverview.js'

function overview(nodeId = 'n1'): HilosDaemonNodeOverviewData {
  return {
    nodeId,
    online: true,
    workers: [{ index: 0, kind: 'regular', agentCount: 0 }],
    workerRestarts24h: 0,
    uptimeMinutes: 0,
    measurements: {
      startedAt: 1_700_000_000,
      cores: 0,
      coresBound: 'quota',
      daemonCores: null,
      masterCores: 0,
      machineLoad: 0,
      masterMemory: 0,
      workersMemory: null,
      workersMeasured: 0,
      memoryCeiling: 0,
      memoryCeilingBound: 'cgroup',
      phpMemoryLimit: null,
    },
    standing: { sessions: 0, connections: 0 },
    environment: { missingRequired: 0, fromExample: 0, drifted: 0, orphans: 0 },
    environmentComparison: {
      answeringNodes: 0,
      divergedKeys: null,
      sourcesDifferKeys: null,
    },
  }
}

describe('daemon node overview boundary', () => {
  it('formats the node projection and accepts only the structural fields memory and workers need', () => {
    expect(
      daemonTotalMemory({
        measurements: { masterMemory: 0, workersMemory: 0 },
      }),
    ).toBe(0)
    expect(
      daemonTotalMemory({
        measurements: { masterMemory: 0, workersMemory: null },
      }),
    ).toBeNull()
    expect(daemonTotalMemory({ measurements: null })).toBeNull()
    expect(
      daemonMonopolisticCount({
        workers: [{ kind: 'regular' }, { kind: 'monopolistic' }],
      }),
    ).toBe(1)
    expect(daemonMonopolisticCount({ workers: null })).toBeNull()
    expect(daemonMonopolisticCount({ workers: [] })).toBe(0)
    const node = overview()
    expect(daemonEnvironmentAttention(node)).toBe(0)
    const heading = {
      clustered: true,
      state: null,
      silentSince: null,
      role: null,
    }
    expect(daemonEnvironmentDifferences(node, heading)).toBeNull()
    const comparable = {
      ...node,
      environmentComparison: {
        answeringNodes: 2,
        divergedKeys: 0,
        sourcesDifferKeys: 0,
      },
    }
    expect(daemonEnvironmentDifferences(comparable, heading)).toBe(0)
  })

  it('preserves every nullable field and known zero of the exact PHP projection', () => {
    const full = overview()
    expect(daemonNodeOverviewSchema.parse(full)).toEqual(full)
    const unknown = {
      ...full,
      online: false,
      workers: null,
      workerRestarts24h: null,
      uptimeMinutes: null,
      measurements: null,
      standing: null,
      environment: null,
    }
    expect(daemonNodeOverviewSchema.parse(unknown)).toEqual(unknown)
    for (const field of Object.keys(full)) {
      const missing: Record<string, unknown> = { ...full }
      delete missing[field]
      expect(daemonNodeOverviewSchema.safeParse(missing).success, field).toBe(
        false,
      )
    }
    for (const section of [
      'measurements',
      'standing',
      'environment',
      'environmentComparison',
    ] as const) {
      const fields = full[section]!
      for (const field of Object.keys(fields)) {
        const missing: Record<string, unknown> = { ...fields }
        delete missing[field]
        expect(
          daemonNodeOverviewSchema.safeParse({ ...full, [section]: missing })
            .success,
          `${section}.${field}`,
        ).toBe(false)
      }
      expect(
        daemonNodeOverviewSchema.safeParse({
          ...full,
          [section]: { ...fields, extra: true },
        }).success,
      ).toBe(false)
    }
  })

  it('rejects invalid counters, enums, nonfinite measurements and foreign root data', () => {
    const full = overview()
    for (const invalid of [
      { ...full, role: 'master' },
      { ...full, state: 'leader' },
      { ...full, clustered: true },
      { ...full, workerRestarts24h: -1 },
      { ...full, uptimeMinutes: 0.5 },
      { ...full, online: 1 },
      { ...full, workers: [{ index: 0, kind: 'other', agentCount: 0 }] },
      { ...full, workers: [{ index: 0, kind: 'regular', agentCount: -1 }] },
      { ...full, measurements: { ...full.measurements, cores: Infinity } },
      {
        ...full,
        measurements: { ...full.measurements, coresBound: 'machine' },
      },
      {
        ...full,
        measurements: { ...full.measurements, memoryCeilingBound: 'cpus' },
      },
      { ...full, environment: { ...full.environment, orphans: -1 } },
      {
        ...full,
        environmentComparison: {
          answeringNodes: 2,
          divergedKeys: -1,
          sourcesDifferKeys: 0,
        },
      },
    ]) {
      expect(daemonNodeOverviewSchema.safeParse(invalid).success).toBe(false)
    }
  })

  it('reads only its own page data, follows live changes and clears without retaining another node', () => {
    const scopes = new ScopeManager()
    const reader = readHilosDaemonNodeOverview(scopes)
    const changes: (HilosDaemonNodeOverviewData | null)[] = []
    const off = subscribeSignal(reader, (value) => changes.push(value))
    expect(reader.get()).toBeNull()
    expect('set' in reader).toBe(false)
    const page = scopes.openPage(HilosPages.DAEMON_NODE)
    page.data.set('daemon', overview('foreign-root'))
    scopes.session.data.set('overview', overview('foreign-session'))
    expect(reader.get()).toBeNull()
    page.data.set(DAEMON_NODE_OVERVIEW_DATA, overview())
    expect(reader.get()).toEqual(overview())
    const live = { ...overview(), workerRestarts24h: 2, online: false }
    page.data.set('overview', live)
    expect(reader.get()).toEqual(live)
    page.data.set('overview', { ...live, extra: true })
    expect(reader.get()).toBeNull()
    page.data.set('overview', live)
    page.data.set('overview', null)
    expect(reader.get()).toBeNull()
    page.data.set('overview', live)
    const next = scopes.openPage(HilosPages.DAEMON_NODE)
    expect(reader.get()).toBeNull()
    page.data.set('overview', overview('old-node'))
    expect(reader.get()).toBeNull()
    next.data.set('overview', overview('n2'))
    expect(reader.get()?.nodeId).toBe('n2')
    scopes.dropPage()
    expect(reader.get()).toBeNull()
    expect(changes).toContainEqual(live)
    expect(changes.at(-1)).toBeNull()
    off()
  })
})
