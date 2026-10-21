import {
  existsSync,
  readFileSync,
  readdirSync,
  renameSync,
  writeFileSync,
} from 'node:fs'
import { basename, join } from 'node:path'

import { expect, test } from '@playwright/test'

import { CLUSTER_MASTERS, CLUSTER_SLAVES, type ClusterView } from './cluster.js'

export interface DeliveryOwner {
  node: string
  container: string
  index: number
  pid: number
  start_ticks: string
}

export interface DeliveryControlResult {
  runId: string
  sequence: number
  ok: true
  action: string
  worker: DeliveryOwner
  state?: { pid: number; startTicks: string; state: string }
  loss?: string[]
  journal?: string
  views: Record<string, ClusterView>
  converged?: Record<string, ClusterView>
}

/** One runner's typed counterpart of the host's allowlisted live-phase controller. */
export function deliveryClusterControl() {
  const runId = process.env.CLUSTER_DELIVERY_RUN_ID
  const directory = process.env.CLUSTER_DELIVERY_CONTROL_DIR
  if (!runId || !directory || basename(directory) !== runId) {
    throw new Error('Delivery control requires the current cluster browser run')
  }
  // Playwright replaces its worker process after a failed case even with zero
  // retries. Continue this UUID's completed sequence across that replacement.
  let sequence = Math.max(
    0,
    ...readdirSync(directory).flatMap((name) => {
      const match = /^result-(\d+)\.json$/.exec(name)
      return match ? [Number(match[1])] : []
    }),
  )
  const evidence: DeliveryControlResult[] = []
  return {
    evidence,
    async request(
      action: 'locate' | 'pause' | 'kill' | 'recover',
      agentId: 'hilos_logs' | 'hilos_index' | 'hilos_settings_library',
      socketNode: string,
    ): Promise<DeliveryControlResult> {
      if (!CLUSTER_MASTERS.includes(socketNode)) {
        throw new Error('Delivery socket must belong to a stand master')
      }
      const current = ++sequence
      const requestPath = join(directory, `request-${current}.json`)
      const resultPath = join(directory, `result-${current}.json`)
      if (existsSync(requestPath) || existsSync(resultPath)) {
        throw new Error('Delivery control sequence was already used')
      }
      const temporary = `${requestPath}.tmp`
      writeFileSync(
        temporary,
        JSON.stringify({
          runId,
          sequence: current,
          action,
          agentId,
          socketNode,
        }),
        { encoding: 'utf8', flag: 'wx' },
      )
      renameSync(temporary, requestPath)
      await expect
        .poll(() => existsSync(resultPath), { timeout: test.info().timeout })
        .toBe(true)
      const result = JSON.parse(readFileSync(resultPath, 'utf8')) as Record<
        string,
        unknown
      >
      if (result.runId !== runId || result.sequence !== current) {
        throw new Error('Delivery host result belongs to another run/sequence')
      }
      if (result.ok !== true) {
        throw new Error(`Delivery host failed: ${String(result.error)}`)
      }
      const worker = result.worker as DeliveryOwner
      if (
        result.action !== action ||
        !worker ||
        ![...CLUSTER_MASTERS, ...CLUSTER_SLAVES].includes(worker.node) ||
        !Number.isInteger(worker.index) ||
        worker.index < 1 ||
        !Number.isInteger(worker.pid) ||
        worker.pid < 2 ||
        !worker.container ||
        typeof worker.start_ticks !== 'string' ||
        !/^\d+$/.test(worker.start_ticks)
      ) {
        throw new Error(
          `Invalid delivery host identity: ${JSON.stringify(result)}`,
        )
      }
      const typed = result as unknown as DeliveryControlResult
      evidence.push(typed)
      return typed
    },
  }
}
