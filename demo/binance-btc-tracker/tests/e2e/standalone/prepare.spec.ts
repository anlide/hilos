import { expect as baseExpect, test } from '@playwright/test'
import { writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import {
  DAEMON_ROOT_PATH,
  DAEMON_WORKERS_PATH,
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  deliveryObject,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStandaloneOverview,
  expectStandaloneWorkers,
  shownByTestId,
  submitStatusIncident,
  watchDeliveryFrames,
  watchPublicStatus,
  watchStatusFrames,
  watchTableSearch,
  gotoPage,
  gotoPublicStatus,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { standaloneStatusControl } from '../../../../../framework/frontend/scripts/standaloneStatus.mjs'
import { withStatusAgentLoss } from '../helpers/status.js'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

const expect = baseExpect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })
const pausedExpect = expect.configure({
  timeout: Math.min(STATUS_EXPECT_TIMEOUT_MS, RESTART_MARK_TIMEOUT_MS),
})

test.use({ colorScheme: 'light' })

test('prepare an accepted public archive through real loss, publication and resolution', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Real collector loss, publication and resolved file projection.
  const control = standaloneStatusControl()
  await grantAdminToSelf(expect, page)
  const context = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: 'Europe/Warsaw',
  })
  const guest = await context.newPage()
  const workers = await page.context().newPage()
  const admin = watchStatusFrames(page)
  const log = watchPublicStatus(guest)
  const workerLog = watchDeliveryFrames(workers)
  const workerTable = await watchTableSearch(workers, {
    page: 'hilos_daemon_workers',
    tableKey: 'hilosDaemonWorkers',
  })
  const preparation: {
    mirrorReadyAt?: number
    workersOpeningAt?: number
    sourceReadyAt?: number
    backClickedAt?: number
    windowRequestId?: string
    settledAt?: number
    rows?: readonly string[]
    publicationAcceptedAt?: number
    restartSettledAt?: number
    recoveryObservedAt?: number
  } = {}
  try {
    // Overview and Workers read the same Daemon page agent's picture mirror.
    // Wait for its real roster before opening a viewport: the cold first
    // window has no previous address for Back to the rows to return to.
    await gotoPage(expect, workers, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(
      expect,
      workers,
      'binance-btc-tracker-mysql-test',
    )
    preparation.mirrorReadyAt = performance.now()
    preparation.workersOpeningAt = performance.now()
    await gotoPage(expect, workers, DAEMON_WORKERS_PATH, PAGE_READY)
    await expect
      .poll(async () =>
        (await workerTable.read()).frames
          .filter(
            ({ direction, wire }) =>
              direction === 'received' && wire.type === 'page_response',
          )
          .map(
            ({ wire }) =>
              deliveryObject(
                deliveryObject(deliveryObject(wire.data).payload).data,
              ).processesReported,
          )
          .at(-1),
      )
      .toBe(true)
    preparation.sourceReadyAt = performance.now()
    const back = shownByTestId(workers, 'hilos-table-empty-page-back')
    const rows = shownByTestId(
      workers,
      /^hilos-table-(row|card)-(regular|monopolistic):\d+$/,
    )
    await expect(rows.or(back)).not.toHaveCount(0)
    if (await back.isVisible()) {
      await back.scrollIntoViewIfNeeded()
      await expect(back).toBeVisible()
      await expect(back).toBeEnabled()
      await back.focus()
      const mark = await workerTable.mark()
      preparation.backClickedAt = performance.now()
      await back.click()
      // The shared journal exposes the real request and the controller's
      // accepted id after dispatch; Workers has no search box, so its
      // settlement does not use the toolbox's search-specific check.
      await expect
        .poll(async () => {
          const state = await workerTable.read()
          const request = state.frames
            .filter(
              ({ direction, index, socket, wire }) =>
                direction === 'sent' &&
                index >= mark.cursor &&
                socket === mark.socket &&
                wire.type === 'table_viewport',
            )
            .at(-1)
          if (typeof request?.wire.requestId !== 'string') return false
          preparation.windowRequestId = request.wire.requestId
          return state.application.some(
            (table) =>
              table.appliedRequestId === preparation.windowRequestId &&
              table.rows.length > 0 &&
              JSON.stringify(table.rows) === JSON.stringify(table.domRows),
          )
        })
        .toBe(true)
      preparation.rows = await workerTable.rowKeys()
      await expect(workers.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(back).toBeHidden()
    }
    await expectStandaloneWorkers(expect, workers)
    preparation.rows = await workerTable.rowKeys()
    preparation.settledAt = performance.now()
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
    await gotoPublicStatus(expect, guest)
    const initial = await expectPublicStatusPair(expect, guest, log)
    expect(initial.status.config).toEqual({
      pollIntervalSeconds: 2,
      staleAfterSeconds: 6,
    })
    let detectedAt = 0
    // Hold the worker through real loss and the publication's server success.
    // Observe its open HTTP/DOM projection concurrently: reader convergence
    // must not keep the worker stopped until its independent watchdog fires.
    // Settle both tasks before cleanup, including when either assertion fails.
    const publication = await Promise.allSettled([
      withStatusAgentLoss(expect, workers, admin, async () => {
        const expect = pausedExpect
        await expect.poll(() => admin.latest()?.incidents?.length).toBe(1)
        const incident = admin.latest()!.incidents![0]
        detectedAt = incident.detectedAt
        await page
          .getByTestId(`hilos-status-publish-now-${incident.incidentId}`)
          .click()
        await submitStatusIncident(
          expect,
          page,
          admin,
          'hilos_status_publish_now',
          incident.incidentId,
        )
        preparation.publicationAcceptedAt = Date.now()
      }).then(() => {
        preparation.restartSettledAt = performance.now()
      }),
      expectPublicStatusPair(expect, guest, log, ({ status }) =>
        status.outages.some(
          (outage) =>
            outage.detectedAt === detectedAt && outage.resolvedAt === null,
        ),
      ),
    ])
    for (const result of publication) {
      if (result.status === 'rejected') throw result.reason
    }
    // The restart reply precedes the collector's next whole node report.
    // Settle the real recovery before checking its published HTTP/DOM pair;
    // an intervening heartbeat can still carry the open outage revision.
    await expect
      .poll(() => admin.latest())
      .toMatchObject({
        healthKnown: true,
        components: { operationalCount: 1, totalCount: 1 },
        maintenance: null,
        incidents: [],
      })
    preparation.recoveryObservedAt = performance.now()
    const accepted = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.outages.some(
          (outage) =>
            outage.detectedAt === detectedAt && outage.resolvedAt !== null,
        ) &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    await expect(guest.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    const history = await guest.getByTestId('public-status-history').innerText()
    const days = await guest
      .getByTestId('public-status-days-90')
      .getByRole('button')
      .evaluateAll((buttons) =>
        buttons.map((button) => button.getAttribute('aria-label')),
      )
    expect(days.at(-1)).toMatch(/ · Major outage$/)
    expectPublicStatusReadOnly(expect, log)
    // Evidence of accepted real facts, never a publisher input or a JSON replacement.
    await writeFile(
      join(control.directory, 'accepted.json'),
      JSON.stringify({ accepted, history, days }),
    )
  } finally {
    await log.stop()
    admin.stop()
    workerLog.stop()
    const evidence = test.info().outputPath('archive-preparation.json')
    await writeFile(
      evidence,
      JSON.stringify({
        public: log,
        admin: admin.frames,
        workers: workerLog,
        preparation,
        loss: JSON.parse(
          test
            .info()
            .attachments.find(({ name }) => name === 'status-real-loss')
            ?.body?.toString() ?? 'null',
        ),
        workerTable: await workerTable.read(),
        workerPage: await workers.locator('body').innerText(),
      }),
    )
    await test.info().attach('standalone-status-archive-preparation', {
      path: evidence,
      contentType: 'application/json',
    })
    await workers.close()
    await context.close()
  }
})
