// The Daemon root reads one whole page answer. Selection is local UI state;
// node states, consensus and measurements remain the backend's verdicts.

import { z } from 'zod'

import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatBytes } from '../../format/bytes.js'
import { type HilosPathResolver } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { LOG_SOURCE_LIVE } from '../logs/hilosLogViewer.js'
import {
  DAEMON_WORKER_KIND_MONOPOLISTIC,
  DAEMON_WORKER_KIND_REGULAR,
} from './hilosDaemonWorkers.js'

/** Page-data key of the value-free cluster overview. */
export const DAEMON_OVERVIEW_DATA = 'daemon'
/** Page-data key of the shared primary database address. */
export const DAEMON_DATABASE_DATA = 'database'

const countSchema = z.number().int().nonnegative()
const measurementSchema = z.number().finite().nonnegative().nullable()

export const daemonOverviewWorkerSchema = z
  .strictObject({
    index: countSchema,
    kind: z.enum([DAEMON_WORKER_KIND_REGULAR, DAEMON_WORKER_KIND_MONOPOLISTIC]),
    agentCount: countSchema,
  })
  .readonly()

export const daemonOverviewNodeSchema = z
  .strictObject({
    nodeId: z.string(),
    online: z.boolean(),
    role: z.enum(['master', 'slave']).nullable(),
    state: z.enum(['leader', 'standby', 'data', 'silent']).nullable(),
    silentSince: z.number().int().nullable(),
    workers: z.array(daemonOverviewWorkerSchema).readonly().nullable(),
    workerRestarts24h: countSchema.nullable(),
    uptimeMinutes: countSchema.nullable(),
    measurements: z
      .strictObject({
        startedAt: z.number().int(),
        cores: measurementSchema,
        coresBound: z.enum(['quota', 'cpus']).nullable(),
        daemonCores: measurementSchema,
        masterCores: measurementSchema,
        machineLoad: countSchema.nullable(),
        masterMemory: countSchema.nullable(),
        workersMemory: countSchema.nullable(),
        workersMeasured: countSchema,
        memoryCeiling: countSchema.nullable(),
        memoryCeilingBound: z.enum(['cgroup', 'machine']).nullable(),
        phpMemoryLimit: countSchema.nullable(),
      })
      .readonly()
      .nullable(),
    standing: z
      .strictObject({
        sessions: countSchema,
        connections: countSchema,
      })
      .readonly()
      .nullable(),
    environment: z
      .strictObject({
        missingRequired: countSchema,
        fromExample: countSchema,
        drifted: countSchema,
        orphans: countSchema,
      })
      .readonly()
      .nullable(),
  })
  .readonly()

export const daemonOverviewSchema = z
  .strictObject({
    clustered: z.boolean(),
    nodes: z.array(daemonOverviewNodeSchema).readonly(),
    consensus: z
      .strictObject({
        nodeId: z.string(),
        term: countSchema,
        leaderId: z.string().nullable(),
        onlineMasters: countSchema,
        masters: countSchema,
        quorumSize: countSchema,
      })
      .readonly()
      .nullable(),
    environmentComparison: z
      .strictObject({
        answeringNodes: countSchema,
        divergedKeys: countSchema.nullable(),
        sourcesDifferKeys: countSchema.nullable(),
      })
      .readonly(),
  })
  .readonly()

export const daemonDatabaseSchema = z
  .strictObject({
    host: z.string(),
    port: countSchema,
    name: z.string(),
    socket: z.string().nullable(),
    migrationIndex: countSchema.nullable(),
  })
  .readonly()

export type HilosDaemonOverviewData = z.infer<typeof daemonOverviewSchema>
export type HilosDaemonOverviewNode = z.infer<typeof daemonOverviewNodeSchema>
export type HilosDaemonOverviewWorker = z.infer<
  typeof daemonOverviewWorkerSchema
>
export type HilosDaemonDatabaseData = z.infer<typeof daemonDatabaseSchema>

/** Binding a project supplies to the Daemon root. No mount request is sent. */
export interface HilosDaemonContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** The whole answer, local node selection and the view's subscription lifetime. */
export interface HilosDaemonOverview {
  readonly overview: ReadonlySignal<HilosDaemonOverviewData | null>
  readonly database: ReadonlySignal<HilosDaemonDatabaseData | null>
  readonly selectedNodeId: ReadonlySignal<string | null>
  readonly selectedNode: ReadonlySignal<HilosDaemonOverviewNode | null>
  selectNode(nodeId: string): void
  start(): void
  dispose(): void
}

/** @param scopes Page scopes filled centrally by bindPageScope. */
export function readHilosDaemonOverview(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonOverviewData | null> {
  const source = scopes.pageDataSignal(DAEMON_OVERVIEW_DATA)
  return computedSignal(() => {
    const parsed = daemonOverviewSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/** @param scopes Page scopes filled centrally by bindPageScope. */
export function readHilosDaemonDatabase(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonDatabaseData | null> {
  const source = scopes.pageDataSignal(DAEMON_DATABASE_DATA)
  return computedSignal(() => {
    const parsed = daemonDatabaseSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/** @param context The project's existing connection and page scopes. */
export function createHilosDaemonOverview(
  context: HilosDaemonContext,
): HilosDaemonOverview {
  const overview = readHilosDaemonOverview(context.scopes)
  const database = readHilosDaemonDatabase(context.scopes)
  const chosen = createSignal<string | null>(null)
  const selectedNode = computedSignal(() => {
    const nodes = overview.get()?.nodes ?? []
    return (
      nodes.find((node) => node.nodeId === chosen.get()) ??
      nodes.find((node) => node.state === 'leader') ??
      [...nodes].sort((a, b) =>
        a.nodeId < b.nodeId ? -1 : a.nodeId > b.nodeId ? 1 : 0,
      )[0] ??
      null
    )
  })
  const selectedNodeId = computedSignal(
    () => selectedNode.get()?.nodeId ?? null,
  )
  let off: (() => void) | undefined

  return {
    overview,
    database,
    selectedNodeId,
    selectedNode,
    selectNode(nodeId) {
      if (overview.get()?.nodes.some((node) => node.nodeId === nodeId)) {
        chosen.set(nodeId)
      }
    },
    start() {
      if (off !== undefined) return
      chosen.set(selectedNodeId.get())
      off = subscribeSignal(overview, () => chosen.set(selectedNodeId.get()))
    },
    dispose() {
      off?.()
      off = undefined
    },
  }
}

/** @param value A known measurement or count. */
export function formatDaemonNumber(value: number | null | undefined): string {
  return value == null || !Number.isFinite(value)
    ? '—'
    : value.toLocaleString(undefined, { maximumFractionDigits: 2 })
}

/** @param cores Occupied or available CPU cores, including a known zero. */
export function formatDaemonCores(cores: number | null | undefined): string {
  return formatDaemonNumber(cores)
}

/** @param bytes Resident bytes or a limit; absence is not zero. */
export function formatDaemonBytes(bytes: number | null | undefined): string {
  return bytes == null || !Number.isFinite(bytes) || bytes < 0
    ? '—'
    : formatBytes(bytes)
}

/**
 * The actual ratio is kept even above 100%; only the visual width is clamped.
 * @param numerator Known occupied amount.
 * @param denominator Known positive capacity.
 */
export function daemonPercentage(
  numerator: number | null | undefined,
  denominator: number | null | undefined,
): number | null {
  if (
    numerator == null ||
    denominator == null ||
    !Number.isFinite(numerator) ||
    !Number.isFinite(denominator) ||
    numerator < 0 ||
    denominator <= 0
  )
    return null
  const percent = (numerator / denominator) * 100
  return Number.isFinite(percent) ? percent : null
}

/** @param percent The real ratio, or unknown. */
export function formatDaemonPercentage(
  percent: number | null | undefined,
): string {
  return percent == null || !Number.isFinite(percent)
    ? '—'
    : `${Math.round(percent)}%`
}

/** @param percent The real ratio; unknown has no filled portion. */
export function daemonProgressWidth(
  percent: number | null | undefined,
): number {
  return percent == null || !Number.isFinite(percent)
    ? 0
    : Math.max(0, Math.min(100, percent))
}

/** @param minutes Complete minutes at the last report, never a browser clock. */
export function formatDaemonUptime(minutes: number | null | undefined): string {
  if (minutes == null || !Number.isFinite(minutes) || minutes < 0) return '—'
  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  const tail = `${hours} h ${Math.floor(minutes % 60)} min`
  return days > 0
    ? `${days} d ${tail}`
    : hours > 0
      ? tail
      : `${Math.floor(minutes)} min`
}

/** @param node The node whose measured process memory is displayed. */
export function daemonTotalMemory(
  node: HilosDaemonOverviewNode,
): number | null {
  const measurements = node.measurements
  return measurements?.masterMemory == null ||
    measurements.workersMemory === null
    ? null
    : measurements.masterMemory + measurements.workersMemory
}

/** @param node The reported roster; no synthetic workers are counted. */
export function daemonMonopolisticCount(
  node: HilosDaemonOverviewNode,
): number | null {
  return node.workers === null
    ? null
    : node.workers.filter(
        (worker) => worker.kind === DAEMON_WORKER_KIND_MONOPOLISTIC,
      ).length
}

/** @param worker One existing process in the reported roster. */
export function daemonWorkerOccupancy(
  worker: HilosDaemonOverviewWorker,
): 'free' | 'occupied' {
  return worker.agentCount === 0 ? 'free' : 'occupied'
}

/** @param node A report carrying environment diagnostic counts. */
export function daemonEnvironmentAttention(
  node: HilosDaemonOverviewNode,
): number | null {
  const env = node.environment
  return env === null
    ? null
    : env.missingRequired + env.fromExample + env.drifted + env.orphans
}

/**
 * This is the sum of two cluster comparison categories, not a per-node key count.
 * @param overview The backend's existing environment comparison.
 * @param node The node selected in the inspector.
 */
export function daemonEnvironmentDifferences(
  overview: HilosDaemonOverviewData,
  node: HilosDaemonOverviewNode,
): number | null {
  const comparison = overview.environmentComparison
  return !overview.clustered ||
    !node.online ||
    node.environment === null ||
    comparison.answeringNodes < 2 ||
    comparison.divergedKeys === null ||
    comparison.sourcesDifferKeys === null
    ? null
    : comparison.divergedKeys + comparison.sourcesDifferKeys
}

/** @param source Limiting source reported by the CPU sampler. */
export function daemonCoresBoundHint(
  source: 'quota' | 'cpus' | null | undefined,
): string {
  return source === 'quota'
    ? 'Container CPU quota'
    : source === 'cpus'
      ? 'CPUs available to the process (nproc)'
      : '—'
}

/** @param source Limiting source reported by the memory sampler. */
export function daemonMemoryBoundHint(
  source: 'cgroup' | 'machine' | null | undefined,
): string {
  return source === 'cgroup'
    ? 'Container memory limit'
    : source === 'machine'
      ? 'Physical machine memory'
      : '—'
}

/**
 * A Unix socket is shown separately, never disguised as a TCP URI.
 * @param database The primary connection's safe address fields.
 */
export function formatDaemonDatabaseAddress(
  database: HilosDaemonDatabaseData | null,
): string {
  if (database === null) return '—'
  if (database.socket !== null) return database.name
  const host =
    database.host.includes(':') && !database.host.startsWith('[')
      ? `[${database.host}]`
      : database.host
  return `mysql://${host}:${database.port}/${encodeURIComponent(database.name)}`
}

/** @param nodeId Locally selected node; no selection resolves no node child. */
export function daemonNodeChildParams(
  nodeId: string | null,
): Record<string, string> {
  return nodeId === null ? {} : { nodeId }
}

/**
 * Leave the stream unselected: the viewer chooses the node's actual log file.
 * @param resolvePath The application's router, including its unbuilt gate.
 * @param nodeId The selected node's actual identity.
 */
export function daemonNodeLogPath(
  resolvePath: HilosPathResolver,
  nodeId: string,
): string | undefined {
  return resolvePath(HilosPages.LOGS_VIEW, { nodeId, source: LOG_SOURCE_LIVE })
}
