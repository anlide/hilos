// Full value-free diagnostics of the node addressed by the current page.

import { z } from 'zod'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { daemonOverviewWorkerSchema } from './hilosDaemonOverview.js'

/** Page-data key of one addressed node's overview. */
export const DAEMON_NODE_OVERVIEW_DATA = 'overview'

const countSchema = z.number().int().nonnegative()
const measurementSchema = z.number().finite().nonnegative().nullable()

export const daemonNodeOverviewSchema = z
  .strictObject({
    nodeId: z.string(),
    online: z.boolean(),
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
      .strictObject({ sessions: countSchema, connections: countSchema })
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
    environmentComparison: z
      .strictObject({
        answeringNodes: countSchema,
        divergedKeys: countSchema.nullable(),
        sourcesDifferKeys: countSchema.nullable(),
      })
      .readonly(),
  })
  .readonly()

export type HilosDaemonNodeOverviewData = z.infer<
  typeof daemonNodeOverviewSchema
>

/** @param scopes Page scopes filled centrally by bindPageScope. */
export function readHilosDaemonNodeOverview(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonNodeOverviewData | null> {
  const source = scopes.pageDataSignal(DAEMON_NODE_OVERVIEW_DATA)
  return computedSignal(() => {
    const parsed = daemonNodeOverviewSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
