import { expect, test, type Expect, type Page } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFile, rm, stat, writeFile } from 'node:fs/promises'
import {
  type watchStatusFrames,
  RESTART_AGENT,
  RESTART_MARK_TIMEOUT_MS,
  readRestartWorker,
  withStoppedRestartWorker,
} from '../../../../../framework/frontend/e2e/index.js'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

/** The e2e-only catalog binds this component to the existing isolated worker. */
export const STATUS_FAULT_COMPONENT = 'settings'

const sendCommand = createCommandChannel({
  host: process.env.COMMAND_HOST ?? 'binance-btc-tracker-daemon-test',
  port: Number(process.env.COMMAND_PORT ?? 8094),
  // The existing pause watchdog bounds the loss to ten seconds. The normal
  // restart answer follows SIGCONT, and must have time to travel back too.
  timeoutMs: 15_000,
})

/**
 * A real stop/start through the master's ordinary restart path. The helper
 * never changes a roster, incident, clock or websocket frame. Its existing
 * settings-only pause guard and independent watchdog remain unchanged.
 */
export async function withStatusAgentLoss(
  expect: Expect,
  workers: Page,
  log: ReturnType<typeof watchStatusFrames>,
  run: () => Promise<void>,
): Promise<void> {
  const worker = await readRestartWorker(expect, workers)
  // Operational is meaningful only with known, fresh Status observations. The
  // Workers identity above comes from the same real collector pipeline; neither
  // a mounted page nor an empty incident list alone proves source readiness.
  await expect
    .poll(() => log.latest())
    .toMatchObject({
      healthKnown: true,
      components: { operationalCount: 1, totalCount: 1 },
      maintenance: null,
      incidents: [],
    })
  const evidence = {
    worker,
    ready: log.latest(),
    stoppedAt: null as number | null,
    checkedAt: null as number | null,
    finalState: undefined as string | undefined,
  }
  let restart: Promise<Error | undefined> | undefined
  let failure: unknown
  try {
    await withStoppedRestartWorker(worker, async () => {
      const pausedExpect = expect.configure({
        timeout: Math.min(STATUS_EXPECT_TIMEOUT_MS, RESTART_MARK_TIMEOUT_MS),
      })
      // Do not mistake a delayed observation after watchdog recovery for loss.
      await pausedExpect
        .poll(
          async () =>
            (await readFile(`/proc/${worker.pid}/status`, 'utf8')).match(
              /^State:\s+(\S)/m,
            )?.[1],
        )
        .toBe('T')
      evidence.stoppedAt = Date.now()
      restart = sendCommand('agent:restart', { agentType: RESTART_AGENT }).then(
        () => undefined,
        (error: Error) => error,
      )
      await run()
      evidence.finalState = (
        await readFile(`/proc/${worker.pid}/status`, 'utf8')
      ).match(/^State:\s+(\S)/m)?.[1]
      evidence.checkedAt = Date.now()
      pausedExpect(evidence.finalState).toBe('T')
    })
  } catch (error) {
    failure = error
  }
  // The pause helper's finally has already resumed the exact worker. Always
  // settle the command, including when an assertion failed during the loss.
  const restartError = await restart
  await test.info().attach('status-real-loss', {
    body: JSON.stringify(evidence),
    contentType: 'application/json',
  })
  if (failure !== undefined) throw failure
  if (restartError) throw restartError
}

/**
 * Private names on the real shared Status disk. A genuine cluster marker is
 * preserved; standalone has no marker, so its otherwise absent name gets an
 * isolated sentinel too. Published JSON files are never modified.
 */
export async function withPrivateStatusFiles(
  run: (names: string[], sentinel: string) => Promise<void>,
): Promise<void> {
  const directory = new URL('../../../data/status/', import.meta.url)
  const marker = '.hilos-cluster-directory.json'
  const sentinel = `private-status-e2e-${randomUUID()}`
  const names = [marker, `.${sentinel}.tmp`, `${sentinel}.json`]
  const created: URL[] = []
  try {
    for (const name of names) {
      const path = new URL(name, directory)
      try {
        await writeFile(path, sentinel, { flag: 'wx' })
        created.push(path)
      } catch (error) {
        if (
          name !== marker ||
          (error as NodeJS.ErrnoException).code !== 'EEXIST'
        )
          throw error
        expect((await stat(path)).isFile()).toBe(true)
      }
    }
    await run(names, sentinel)
  } finally {
    for (const path of created) await rm(path)
  }
}
