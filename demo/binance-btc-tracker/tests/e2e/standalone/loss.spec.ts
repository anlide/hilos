import { expect as baseExpect, test } from '@playwright/test'
import { readFile, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import {
  publicStatusFileSchema,
  publicStatusHeartbeatSchema,
  type PublicStatusFile,
} from '../../../../../framework/frontend/core/src/status/index.js'
import {
  PAGE_READY,
  PUBLIC_STATUS_FILES,
  STATUS_PATH,
  controlSocketReconnect,
  expectPublicStatusArchive,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectPublicStatusStale,
  gotoPage,
  grantAdminToSelf,
  watchPublicStatus,
  watchStatusFrames,
  type DisplayedStatusPair,
  gotoPublicStatus,
} from '../../../../../framework/frontend/e2e/index.js'
import { standaloneStatusControl } from '../../../../../framework/frontend/scripts/standaloneStatus.mjs'
import {
  beatStatusHeartbeat,
  staleStatusHeartbeat,
} from '../../../../../framework/frontend/scripts/statusMoments.mjs'
import { STATUS_DISK } from '../helpers/status.js'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

const expect = baseExpect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })
const STATUS_DISK_HEARTBEAT = new URL('heartbeat.json', STATUS_DISK)

test.use({ colorScheme: 'light', timezoneId: 'Europe/Warsaw' })

test('full daemon stop keeps nginx Status readable, stale and cold, then recovers from real facts', async ({
  page,
  browser,
  baseURL,
}) => {
  const control = standaloneStatusControl()
  const archive: {
    accepted: DisplayedStatusPair
    history: string
    days: string[]
  } = JSON.parse(
    await readFile(join(control.directory, 'accepted.json'), 'utf8'),
  )
  // Same reader zone as the independently accepted archive.
  const context = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: 'Europe/Warsaw',
  })
  const guest = await context.newPage()
  // The owner's own view of source health, apart from both public readers.
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: 'Australia/Broken_Hill',
  })
  const adminPage = await adminContext.newPage()
  const socket = await controlSocketReconnect(guest)
  const coldSocket = await controlSocketReconnect(page)
  const log = watchPublicStatus(guest)
  const coldLog = watchPublicStatus(page)
  const admin = watchStatusFrames(adminPage)
  const events: Record<string, unknown> = { timeOrigin: performance.timeOrigin }
  let aged = false
  let stopRequested = false
  try {
    await grantAdminToSelf(expect, adminPage)
    await gotoPublicStatus(expect, guest)
    const before = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    expect(before.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, guest, archive)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    // The owner publishes its last pair's heartbeat aged past the reader's
    // stale limit and holds every later publication, a stopping daemon's
    // included: this is the pair nginx serves once the daemon is gone.
    aged = true
    const { aliveAt } = await staleStatusHeartbeat()
    const frozen = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ heartbeat }) => heartbeat.aliveAt === aliveAt,
    )
    events.frozenAt = Date.now()
    events.frozen = frozen
    expect(frozen.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusStale(expect, guest, frozen)
    events.staleAt = Date.now()
    await expectPublicStatusArchive(expect, guest, archive)
    // Every read from here on is served the held pair.
    const reads = log.reads[0].length
    // A stopped daemon answers no socket; the readers' reconnects wait until
    // the restarted owner publishes, where their release makes the reread
    // immediate.
    await socket.hold()
    stopRequested = true
    events.stopRequestedAt = Date.now()
    const stopped = await control.request('stop')
    events.knownDead = stopped
    expect(stopped.daemon.state).toMatchObject({
      Running: false,
      Pid: 0,
      Status: 'exited',
    })
    expect(stopped.nginx.state.Running).toBe(true)
    const packetsAtStop = log.wire.packets.length
    await expectPublicStatusStale(expect, guest, frozen)
    await expectPublicStatusArchive(expect, guest, archive)
    const stillDead = await control.request('inspect')
    expect(stillDead.daemon.state).toEqual(stopped.daemon.state)
    expect(stillDead.nginx.id).toBe(stopped.nginx.id)
    expect(stillDead.nginx.state.Running).toBe(true)
    events.stillDead = stillDead

    // Own cold document navigation is part of this stopped-daemon scenario.
    const beforeReload = log.reads[0].length
    await guest.reload()
    await expect.poll(() => log.reads[0].length).toBeGreaterThan(beforeReload)
    const reloaded = await expectPublicStatusPair(expect, guest, log)
    expect(reloaded.status).toEqual(frozen.status)
    expect(reloaded.heartbeat).toEqual(frozen.heartbeat)
    await expectPublicStatusStale(expect, guest, frozen)
    await expectPublicStatusArchive(expect, guest, archive)
    await coldSocket.hold()
    await gotoPublicStatus(expect, page)
    const cold = await expectPublicStatusPair(expect, page, coldLog)
    expect(cold.status).toEqual(frozen.status)
    expect(cold.heartbeat).toEqual(frozen.heartbeat)
    await expectPublicStatusStale(expect, page, cold)
    expect(cold.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, page, archive)
    expect(coldLog.wire.packets).toEqual([])
    expect(log.wire.packets.length).toBe(packetsAtStop)
    events.coldAt = Date.now()
    const afterCold = await control.request('inspect')
    expect(afterCold.daemon.state).toEqual(stopped.daemon.state)
    events.afterCold = afterCold
    for (const reader of [log, coldLog]) {
      expectPublicStatusReadOnly(expect, reader)
      for (const [kind, file] of reader.reads.entries()) {
        const stoppedReads = reader === log ? file.slice(reads) : file
        for (const read of stoppedReads.filter(
          (read) => read.completedAt !== undefined,
        )) {
          expect(read.status).toBe(200)
          expect(read.body).toEqual(
            kind === 0 ? frozen.status : frozen.heartbeat,
          )
        }
      }
      for (const file of reader.reads) {
        expect(
          file.filter((read) => read.status === 200).length,
        ).toBeGreaterThan(0)
        for (const read of file.filter((read) => read.status === 200))
          expect(read.headers?.['cache-control']).toContain('no-store')
      }
    }
    events.restartRequestedAt = Date.now()
    const started = await control.request('start')
    expect(started.daemon.id).toBe(stopped.daemon.id)
    expect(started.daemon.state.Running).toBe(true)
    events.started = started
    // The restarted owner no longer holds the aged heartbeat, but a starting
    // daemon answers no socket yet either: a reconnect released at the
    // container's start fails and waits out the reader's backoff. Keep both
    // parked until the owner's first publication is on its disk; released
    // then, they reach a listening daemon and reread at once. Bootstrap has
    // no five-second recovery SLA; the Status convergence budget bounds the
    // wait for that publication.
    await expect
      .poll(async () => {
        const heartbeat = publicStatusHeartbeatSchema.safeParse(
          JSON.parse(await readFile(STATUS_DISK_HEARTBEAT, 'utf8')),
        )
        return heartbeat.success ? heartbeat.data.aliveAt : 0
      })
      .toBeGreaterThan(frozen.heartbeat.aliveAt)
    events.ownerPublishedAt = Date.now()
    await socket.release()
    await coldSocket.release()
    if (
      !coldLog
        .pairs()
        .some(({ heartbeat }) => heartbeat.aliveAt > frozen.heartbeat.aliveAt)
    ) {
      await page.waitForResponse(
        async (response) => {
          if (
            new URL(response.url()).pathname !== PUBLIC_STATUS_FILES[1] ||
            response.status() !== 200
          )
            return false
          const heartbeat = publicStatusHeartbeatSchema.safeParse(
            await response.json(),
          )
          return (
            heartbeat.success &&
            heartbeat.data.aliveAt > frozen.heartbeat.aliveAt
          )
        },
        { timeout: STATUS_EXPECT_TIMEOUT_MS },
      )
    }
    events.writerObservedAt = Date.now()
    // Use the cold reader to witness the writer's genuinely newer heartbeat,
    // even if its sources are Unknown. The warm reader may accept it later;
    // its health deadline starts only after independent source readiness.
    events.firstFreshReader = 'cold'
    events.firstFresh = await expectPublicStatusPair(
      expect,
      page,
      coldLog,
      ({ heartbeat }) => heartbeat.aliveAt > frozen.heartbeat.aliveAt,
    )
    events.firstFreshAt = Date.now()
    // The restarted node agent reports at once without its process roster;
    // its next whole report follows the change interval, and the owner
    // rebuilds the pair only after it. The admin opens Status on the
    // listening daemon and settles that health first, so the reader's wait
    // below covers only the rebuild.
    await gotoPage(expect, adminPage, STATUS_PATH, PAGE_READY)
    events.adminOpenedAt = Date.now()
    await expect
      .poll(() => admin.latest())
      .toMatchObject({
        healthKnown: true,
        components: { operationalCount: 1, totalCount: 1 },
        maintenance: null,
        incidents: [],
      })
    events.recoveryObservedAt = Date.now()
    // Restore can refresh the archive's heartbeat before rebuilding current
    // observations. Source readiness needs a new healthy projection as well.
    const healthySource = (status: PublicStatusFile): boolean =>
      status.generatedAt > frozen.status.generatedAt &&
      status.components.every((component) => component.state === 'operational')
    if (!coldLog.pairs().some(({ status }) => healthySource(status))) {
      await page.waitForResponse(
        async (response) => {
          if (
            new URL(response.url()).pathname !== PUBLIC_STATUS_FILES[0] ||
            response.status() !== 200
          )
            return false
          const status = publicStatusFileSchema.safeParse(await response.json())
          return status.success && healthySource(status.data)
        },
        { timeout: STATUS_EXPECT_TIMEOUT_MS },
      )
    }
    events.sourceKnownAt = Date.now()
    const sourceReady = await expectPublicStatusPair(
      expect,
      page,
      coldLog,
      ({ status, heartbeat }) =>
        healthySource(status) &&
        heartbeat.aliveAt > frozen.heartbeat.aliveAt &&
        Date.now() - heartbeat.aliveAt < status.config.staleAfterSeconds * 1000,
    )
    events.sourceReadyAt = Date.now()
    events.sourceReady = sourceReady
    events.coldConnectionAtSourceReady = {
      state: await page.getByTestId('conn-state').innerText(),
      message: await page
        .getByTestId('public-status-connection')
        .allTextContents(),
    }
    await expect(page.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(page.getByTestId('public-status-component-state')).toHaveText(
      'Operational',
    )
    expect(sourceReady.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, page, archive)
    events.coldHealthyAt = Date.now()
    // Only now measure the original warm reader's automatic acceptance. Its
    // reconnect was released at the owner's first publication on disk; the
    // reconnect drives its first reread, the owner's hints the later ones.
    events.warmRecoveryCheckAt = Date.now()
    const fresh = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status, heartbeat }) =>
        status.generatedAt >= sourceReady.status.generatedAt &&
        heartbeat.aliveAt > frozen.heartbeat.aliveAt &&
        Date.now() - heartbeat.aliveAt <
          status.config.staleAfterSeconds * 1000 &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    events.fresh = fresh
    await expect(guest.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(guest.getByTestId('public-status-component-state')).toHaveText(
      'Operational',
    )
    expect(fresh.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, guest, archive)
    events.freshAt = Date.now()
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await expect(guest.getByTestId('public-status-connection')).toHaveCount(0)
    events.connectedAt = Date.now()
    expectPublicStatusReadOnly(expect, log)
    expectPublicStatusReadOnly(expect, coldLog)
  } finally {
    try {
      events.cleanup = await control.request('start')
      // A restart drops the aged heartbeat's hold by itself; without a stop
      // the hold is still this test's to release.
      if (aged && !stopRequested) await beatStatusHeartbeat()
    } finally {
      await socket.dispose()
      await coldSocket.dispose()
      await log.stop()
      await coldLog.stop()
      admin.stop()
      const evidence = test.info().outputPath('stop-cold-recovery.json')
      await writeFile(
        evidence,
        JSON.stringify({
          events,
          archive,
          public: log,
          cold: coldLog,
          admin: admin.overviews,
        }),
      )
      await test.info().attach('standalone-status-stop-cold-recovery', {
        path: evidence,
        contentType: 'application/json',
      })
      await adminContext.close()
      await context.close()
    }
  }
})
