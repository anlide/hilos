import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  dismissToasts,
  setCustomSetting,
  shownByTestId,
  deliveryObject,
  expectLogBatchViewer,
  expectLogEpochMoment,
  expectLogUtcMoment,
  logBatchDirectory,
  logMomentLabel,
  watchLogFrames,
  type LogFrameLog,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import { rotateLogs, walkLogs } from '../helpers/logs.js'

const READER_ZONE = 'America/Los_Angeles'
test.use({ timezoneId: READER_ZONE })

// Rotation, carrying a batch off and cleaning it up, end to end (HIL-763): the
// second and last through-scenario of the logs epic. It makes rotations, sees
// the batches land on the rotations screen, reads the takeout instruction,
// confirms it, and watches the cleaner remove exactly that batch while the table
// updates itself.
//
// The moments come from the log-store owner's own test-only commands (HIL-1858):
// test:logs:rotate makes the very attempt a fired axis makes — the batch, the
// cleanup of confirmed batches, a full walk and the index at once — and
// test:logs:walk makes the full walk and sends the index at once. Nothing waits
// for an axis, the five-second live walk, the minute-long full walk or the
// keepalive (the rule of 2026-10-31, docs/agents/frontend/testing-strategy.md,
// "Time — a command brings the moment to now"); it replaced the owner's decision
// of 2026-09-02 that wanted no command here. What is left to wait is the carrier
// putting a batch from staging into the archive (a second at most) and the
// aggregator's half-second delivery window.
//
// What the commands do not stand in for is the path the scenario exists for: a
// setting written through the screen reaches the agent, the policy is re-read,
// and the verdict it yields travels to a screen nobody reloaded. The retention
// rule and the undo window still take that whole path, and the rotation axis
// takes it once, to the rule line — the commands only choose the moment the node
// acts on what it was told.
//
// Two changes ride inside the same walk (HIL-1233): a batch returns under
// protection while the takeout dialog stands open, and the cleaner removes an
// acknowledged batch while its withdrawal dialog stands open. Each dialog keeps
// its batch in focus, names the change and locks its own action.
//
// What is NOT checked here, because it belongs to the leaves that built it: the
// marker's shape and the other takeout refusals (HIL-483), the order files are
// removed in (HIL-382), a withdrawal that is accepted (HIL-759), the screen's
// filters and empty states (HIL-387), the retention predicate itself (HIL-381).
// Nor the cluster half of any of it: this installation is single-node, and its
// rows are keyed on a dash in place of a node name.
//
// One filter case is checked here after all, by a test of its own beside the
// scenario: the reset of the address filter (HIL-1521). A screen opened at
// `/rotations/due`, directly or through a reload, used to reset back to the
// filter its address put there; the test drives the reset cross from the
// general address, from the direct one and from a reload of it. It reads only
// the filter and the address, so it rotates nothing and writes no setting, and
// the warning below does not concern it.
//
// The scenario shares the stand with every other binance-btc-tracker spec, and
// rotation moves live log files out from under all of them — which is safe only
// because the suite is serial here (CI=1 on binance-btc-tracker-e2e-runner,
// workers=1 in the config). It is
// also why the four keys go back to their defaults in an afterEach: a retention
// left at one batch and an undo window left at zero would let any later attempt
// clean up what the rest of the run, or the next one, still reads.

/**
 * The age axis of rotation, written once so the rule line can be seen to say it; 0 is its
 * default. The stand's other two axes default to the night and the 512 MiB of the Normal mode
 * (HIL-906), so rotation is on and the rotation command is never refused for being off.
 */
const ROTATION_MAX_AGE = 'logs.rotation.max_age_seconds'

/** Count criterion of the retention rule: the newest N batches are protected whatever their age. */
const RETENTION_KEEP_BATCHES = 'logs.archive_retention.keep_batches'

/** Age criterion of the retention rule, in seconds. Both criteria hold at once, so both are moved. */
const RETENTION_MAX_AGE = 'logs.archive_retention.max_age_seconds'

/** How long this node protects a confirmed batch from its own cleaner, in seconds. */
const TAKEOUT_UNDO_WINDOW = 'logs.takeout.undo_window_seconds'

/** Every key a test of this file writes, in the order the cleanup returns them by. */
const MOVED_KEYS = [
  ROTATION_MAX_AGE,
  RETENTION_KEEP_BATCHES,
  RETENTION_MAX_AGE,
  TAKEOUT_UNDO_WINDOW,
]

/** Prefix the framework table gives every row's `data-id`; the key follows it. */
const ROW_ID_PREFIX = 'hilos-table-row-'

/** The control tab, so the cleanup can reach the settings screen after a failure. */
let control: Page | null = null

let rotationFrames: LogFrameLog
test.beforeEach(async ({ page }) => {
  rotationFrames = watchLogFrames(page)
})

/**
 * Narrow the server window to one settings key so assertions ignore pagination.
 *
 * @param tab The settings tab.
 * @param key Catalog key to isolate.
 */
async function isolate(tab: Page, key: string): Promise<void> {
  const search = tab.getByTestId('hilos-table-search')
  await typeInto(search, key)
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toBeVisible()
}

/**
 * Write a custom value for one catalog key, the way a person would.
 *
 * The value is typed rather than filled: a Vue input bound with `v-model` can miss
 * a value that arrives in one assignment, and the project's cure is `fill('')`
 * plus `typeInto`.
 *
 * @param tab The settings tab.
 * @param key Catalog key to write.
 * @param value Value to write, as the field takes it.
 */
async function setSetting(
  tab: Page,
  key: string,
  value: string,
): Promise<void> {
  await isolate(tab, key)
  await setCustomSetting(tab, key, value)

  // The dialog closes on the server's word, so its going is what says the value
  // was accepted and written; the row then reads as a custom value rather than
  // as the catalog default it was.
  await expect(tab.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toContainText(
    'custom',
  )
}

/**
 * Return one key to the catalog default by taking its custom value away.
 *
 * A key that carries no custom value is left alone rather than opened: the Save
 * button is armed by the draft being dirty, and unchecking a switch that is
 * already off would leave the dialog open on a disabled button.
 *
 * @param tab The settings tab.
 * @param key Catalog key to reset.
 */
async function resetSetting(tab: Page, key: string): Promise<void> {
  await isolate(tab, key)
  const row = tab.getByTestId(`${ROW_ID_PREFIX}${key}`)
  await clearCustomSetting(tab, key)
  await expect(row).toContainText('default')
}

/**
 * The row keys currently in a window, in the order the table hands them over.
 *
 * @param page The tab holding the table.
 */
async function rowKeys(page: Page): Promise<string[]> {
  return page
    .locator(
      `tbody [data-id^="${ROW_ID_PREFIX}"]:not([data-id^="${ROW_ID_PREFIX}detail-"])`,
    )
    .evaluateAll(
      (rows, prefix) =>
        rows.map((row) =>
          (row.getAttribute('data-id') ?? '').slice(prefix.length),
        ),
      ROW_ID_PREFIX,
    )
}

/**
 * Read the moment from the observed row; its identity stays opaque.
 *
 * @param key A rotations-table row key.
 */
function batchInstant(key: string): number {
  return expectLogEpochMoment(
    expect,
    rotationFrames.rotations().get(key)?.batchAt,
  )
}

/**
 * The batches this scenario's own rotations added, oldest first.
 *
 * The archive of a stand is neither empty nor this spec's to reason about — the
 * daemon rotates on its way up, and the directory outlives the run — so what
 * counts as this scenario's batch is defined twice over: the key was not in the
 * window before the threshold went up, AND it is newer than every key that was.
 *
 * The second half is not belt and braces. Only the first half was there at
 * first, and the spec passed while proving nothing: the window had not arrived
 * when its keys were read, so an empty "before" made all twenty-five rows look
 * new, and the batch the scenario went on to confirm and watch disappear was
 * three weeks old. Absence from a snapshot says where the reader was looking;
 * only the instant says when the batch was made.
 *
 * @param page The tab holding the rotations table.
 * @param before Row keys the window held before the trigger was switched on.
 */
async function newBatchKeys(
  page: Page,
  before: readonly string[],
): Promise<string[]> {
  const newest = before.reduce(
    (latest, key) => Math.max(latest, batchInstant(key)),
    0,
  )
  const keys = (await rowKeys(page)).filter(
    (key) => !before.includes(key) && batchInstant(key) > newest,
  )

  return keys.sort((a, b) => batchInstant(a) - batchInstant(b))
}

/**
 * Wait until the wall clock has left the second a batch is named after.
 *
 * A batch directory is named by the UTC second of its rotation, so two rotations
 * in one second share a name: the later one either joins the batch while it is
 * still in staging or meets the carried one in the archive, which the carrier
 * refuses for good. An axis cannot fire twice in a second — every attempt
 * restarts its count — but two commands in a row can. The wait is a second at
 * most and usually a fraction of one: the earlier batch's row has already
 * crossed the aggregator's window to reach the screen.
 *
 * @param key A rotations-table row key of the batch made last.
 */
async function leaveBatchSecond(key: string): Promise<void> {
  const nextSecond = batchInstant(key) + 1_000
  while (Date.now() < nextSecond) {
    await new Promise((resolve) => setTimeout(resolve, nextSecond - Date.now()))
  }
}

/**
 * Rotate now and return the batch the rotation made, once its row has arrived by
 * push on a page that did not navigate.
 *
 * The rotation has to move something: a batch is what every caller goes on to
 * read, and the live logs of a running stand are never all empty.
 *
 * @param page The tab holding the rotations table.
 * @param before Row keys the window held before the scenario rotated.
 * @param previous Row key of the batch the scenario made last, if it made one.
 */
async function rotateNewBatch(
  page: Page,
  before: readonly string[],
  previous?: string,
): Promise<string> {
  const after = previous === undefined ? 0 : batchInstant(previous)
  if (previous !== undefined) {
    await leaveBatchSecond(previous)
  }
  expect(await rotateLogs()).toBeGreaterThan(0)
  await expect
    .poll(
      async () =>
        (await newBatchKeys(page, before)).filter(
          (key) => batchInstant(key) > after,
        ).length,
    )
    .toBeGreaterThanOrEqual(1)

  return (await newBatchKeys(page, before)).at(-1)!
}

/**
 * Press the reset cross of a rotations screen that stands on the address filter,
 * and see the whole filter go with it: the switch is off, the address is the
 * general one, and the cross is gone from both branches of the table.
 *
 * @param page The tab showing the rotations screen at `/rotations/due`.
 */
async function resetRotationFilters(page: Page): Promise<void> {
  const state = shownByTestId(page, 'hilos-table-filter-state')
  await expect(state).toBeChecked()
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)

  await shownByTestId(page, 'hilos-table-filter-reset').click()

  await expect(state).not.toBeChecked()
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)
}

test.afterEach(async () => {
  // Runs on the passing and the failing path alike, and it is the failing one it
  // exists for: the retention and the undo window this spec narrows would
  // otherwise outlive it, and nothing else would ever widen them again.
  const tab = control
  control = null
  try {
    if (tab !== null) {
      for (const key of MOVED_KEYS) {
        await resetSetting(tab, key)
      }
    }
  } finally {
    rotationFrames.stop()
    await test.info().attach('rotation-time-frames', {
      body: JSON.stringify({
        packets: rotationFrames.packets,
        sent: rotationFrames.sent,
      }),
      contentType: 'application/json',
    })
    await tab?.close()
  }
})

test('opens a new kept batch without a stream and reads only after a catalog stream is chosen', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/logs/rotations', PAGE_READY)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)

  // Keep the default retention protection. Only a batch newer than this real
  // window can prove the transition; an archive left by another run cannot.
  const before = await rowKeys(page)
  const rowKey = await rotateNewBatch(page, before)
  // The batch is announced while it is still in staging. The carrier puts it
  // into the archive on its own tick, a second at most, and only a walk after
  // that reads it there — so each probe walks first.
  await expect
    .poll(async () => {
      await walkLogs()
      return rotationFrames.rotations().get(rowKey)?.retentionState
    })
    .toBe('kept')

  const batch = rotationFrames.rotations().get(rowKey)!
  const batchAt = expectLogEpochMoment(expect, batch.batchAt)
  const path = `/hilos/logs/view/${batch.node}/${batchAt}`
  const view = shownByTestId(page, `${ROW_ID_PREFIX}${rowKey}`).getByTestId(
    'hilos-rotation-view',
  )
  await expect(view).toHaveAttribute('href', path)
  await view.click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(path)
  await expect(page.getByTestId('hilos-log-source')).toHaveValue(
    String(batchAt),
  )
  await expect(page.getByTestId('hilos-log-stream')).toHaveValue('')
  await expect(page.getByTestId('hilos-log-empty-unchosen')).toBeVisible()
  expect(rotationFrames.request('logs_read_lines')).toBeUndefined()

  // The catalog travels separately from page readiness. Its actual batch
  // membership supplies the choice, and must not silently make that choice.
  const offeredStreams = () => {
    const catalog = rotationFrames
      .data('subscription_page_hilos_logs_view')
      .at(-1)
    const node = (Array.isArray(catalog?.nodes) ? catalog.nodes : [])
      .map(deliveryObject)
      .find((node) => node.nodeId === batch.node)
    if (!Array.isArray(node?.batches) || !node.batches.includes(batchAt)) {
      return []
    }
    return (Array.isArray(node.streams) ? node.streams : [])
      .map(deliveryObject)
      .filter(
        (stream) =>
          Array.isArray(stream.batchTimestamps) &&
          stream.batchTimestamps.includes(batchAt),
      )
      .map((stream) => String(stream.key))
  }
  await expect.poll(() => offeredStreams().length).toBeGreaterThan(0)
  await expect(page.getByTestId('hilos-log-stream')).toHaveValue('')
  await expect(page.getByTestId('hilos-log-empty-unchosen')).toBeVisible()
  await expect(page).toHaveURL(path)
  expect(rotationFrames.request('logs_read_lines')).toBeUndefined()

  const stream = offeredStreams()[0]!
  await page.getByTestId('hilos-log-stream').selectOption(stream)
  await expect(page).toHaveURL(`${path}/${stream}`)
  await expect.poll(() => rotationFrames.ack('logs_read_lines')).toBeDefined()
  await expectLogBatchViewer(expect, page, rotationFrames, batch, READER_ZONE)
})

// FIXME(F-018, 2026-10-22): the restart button detaches while the test scrolls to it · чужой · попытка 1 · HIL-1782
test(
  'keeps a quiet agent log on missing live after rotation until its latest batch link is opened',
  { tag: '@quarantine:F-018' },
  async ({ page }) => {
    const agent = 'hilos_settings_library'
    const stream = `agent-${agent}.log`
    const livePath = `/hilos/logs/view/standalone/live/${stream}`

    await grantAdminToSelf(expect, page)

    // A previous attempt may already have archived this quiet writer's startup.
    // Restart through the real Daemon dialog to get a live file on every attempt;
    // successful settings writes themselves never print into the library's log.
    await gotoPage(expect, page, '/hilos/daemon/standalone/agents', PAGE_READY)
    const search = page.getByTestId('hilos-table-search')
    await typeInto(search, agent)
    const restart = shownByTestId(page, `hilos-daemon-agent-restart-${agent}`)
    await restart.scrollIntoViewIfNeeded()
    await expect(restart).toBeVisible()
    await expect(restart).toBeEnabled()
    await restart.focus()
    await restart.click()
    const confirm = page.getByTestId('hilos-daemon-restart-confirm')
    await confirm.scrollIntoViewIfNeeded()
    await expect(confirm).toBeVisible()
    await expect(confirm).toBeEnabled()
    await confirm.focus()
    await confirm.click()
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(
      page.getByTestId('hilos-toast-success').filter({
        hasText: `Agent ${agent} restarted on node standalone.`,
      }),
    ).toBeVisible()
    await dismissToasts(page)

    await gotoPage(expect, page, livePath, PAGE_READY)
    await expect(page.getByTestId('hilos-log-stream')).toHaveValue(stream)
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    const entry = page.getByTestId('hilos-log-entry').first()
    await expect(entry).toBeVisible()
    const liveEntry = (await entry.textContent()) ?? ''
    expect(liveEntry).not.toBe('')

    await gotoPage(expect, page, '/hilos/logs/rotations', PAGE_READY)
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    const before = await rowKeys(page)
    const batch = batchInstant(await rotateNewBatch(page, before))
    expect(batch).toBeGreaterThan(0)

    await gotoPage(expect, page, '/hilos/daemon/standalone/agents', PAGE_READY)
    await typeInto(search, agent)
    const log = shownByTestId(page, `hilos-daemon-agent-log-${agent}`)
    await expect(log).toHaveAttribute('href', livePath)
    await log.click()
    await expectPageReady(expect, page)

    // A new viewer has no retained lines from before rotation. Its completed read
    // must prove this exact live file is missing: a new batch alone does not prove
    // the writer is quiet, and a displayed stream option does not prove a file.
    await expect(page.getByTestId('hilos-log-empty-missing')).toBeVisible()
    await expect(page.getByTestId('hilos-log-stream')).toHaveValue(stream)
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    await expect(page).toHaveURL(livePath)
    await expect(page.getByTestId('hilos-log-entry')).toHaveCount(0)

    const archivePath = `/hilos/logs/view/standalone/${batch}/${stream}`
    const latestBatch = page.getByTestId('hilos-log-latest-batch')
    await expect(latestBatch).toHaveAttribute('href', archivePath)
    await latestBatch.click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(archivePath)
    await expect(page.getByTestId('hilos-log-source')).toHaveValue(
      String(batch),
    )
    await expect(page.getByTestId('hilos-log-stream')).toHaveValue(stream)
    await expect(page.getByTestId('hilos-log-pane')).toContainText(liveEntry)
    await expect(page.getByTestId('hilos-log-empty-missing')).toHaveCount(0)
    await expect(page.getByTestId('hilos-log-follow')).toBeDisabled()
    await expect(page.getByTestId('hilos-log-follow-off')).toBeVisible()
    await expect(page.getByTestId('hilos-log-tail-badge')).toHaveCount(0)

    await page.getByTestId('hilos-log-source').selectOption('live')
    await expect(page.getByTestId('hilos-log-empty-missing')).toBeVisible()
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    await expect(page.getByTestId('hilos-log-stream')).toHaveValue(stream)
    await expect(page).toHaveURL(livePath)
    await expect(latestBatch).toHaveAttribute('href', archivePath)
  },
)

test('shows the configured rotation threshold, carries a batch off on the operator confirmation, and prunes exactly that batch while the table updates itself', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  // The observer opens first and never leaves: everything it is asked about
  // afterwards has to arrive by push, on a page that did not navigate.
  await gotoPage(expect, page, '/hilos/logs/rotations', PAGE_READY)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-table-title')).toHaveText(
    'Rotation batches',
  )
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-filter-state')).toBeVisible()
  await expect(page.getByTestId('hilos-table-filter-node')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-cards')).toHaveCount(1)

  // The window, and not merely the table around it: the table element is in the
  // document from the first paint, and reading the rows before the server had
  // answered is what made this spec pass while measuring a three-week-old batch.
  // The spinner stands until the first window lands, whether that window turns
  // out to hold rows or none.
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)

  // The control tab is the same session in the same context, so it needs no
  // registration of its own.
  const tabB = await page.context().newPage()
  control = tabB
  await gotoPage(expect, tabB, '/hilos/settings', PAGE_READY)
  await expect(tabB.getByTestId('hilos-viewport-table')).toBeVisible()

  const overview = await page.context().newPage()
  const overviewFrames = watchLogFrames(overview)
  const viewer = await page.context().newPage()
  const viewerFrames = watchLogFrames(viewer)
  try {
    await gotoPage(expect, overview, '/hilos/logs', PAGE_READY)

    // The three preparatory keys, none of which starts a clock. Retention keeps
    // its count criterion alone, at one batch, so everything but the newest batch
    // of the archive is recommended. The age criterion goes off: it compares whole
    // seconds, strictly, and would hold a batch made this second for up to two
    // more — while the count is not something ageing or waiting takes away.
    await setSetting(tabB, RETENTION_KEEP_BATCHES, '1')
    await setSetting(tabB, RETENTION_MAX_AGE, '0')
    await setSetting(tabB, TAKEOUT_UNDO_WINDOW, '120')

    // The age axis of rotation, and the one check that it reaches the screen at
    // all: the rule line on the observer says the new threshold. The axis itself
    // never comes due in this run — a day is long, and every attempt below starts
    // its count again — so the rotations are the command's.
    await setSetting(tabB, ROTATION_MAX_AGE, '86400')
    await walkLogs()
    await expect(page.getByTestId('hilos-rotation-rule')).toContainText(
      '1 day after the last rotation',
    )

    // The archive as it stands, read here rather than at the top: every write above
    // repaints the badges and so re-serves this window, which means the picture on
    // screen now is one the page has answered for four times over. Nothing can
    // have been added to it either — until the next line, only the nightly schedule
    // and the size threshold may rotate, and the scenario reaches neither.
    const before = await rowKeys(page)

    // Three batches, oldest first, each arriving on a page that has not navigated
    // since it opened. Batch C, the newest, is the one the count criterion keeps;
    // A and B are outside it. A rotation after the first always has something to
    // move: a rotation that moved anything writes a line saying so under the
    // agent's own name, and the next rotation carries that file off (HIL-480).
    const batchA = await rotateNewBatch(page, before)
    const batchB = await rotateNewBatch(page, before, batchA)
    const batchC = await rotateNewBatch(page, before, batchB)

    // Batch C is announced while it is still in staging, and a batch on its way is
    // left out of the verdict, so B reads as the newest kept one until the carrier
    // has put C into the archive — a second at most — and a walk has read it there.
    await expect
      .poll(async () => {
        await walkLogs()
        const rows = rotationFrames.rotations()
        return [
          rows.get(batchA)?.retentionState,
          rows.get(batchB)?.retentionState,
        ]
      })
      .toEqual(['due', 'due'])

    const batch = rotationFrames.rotations().get(batchA)!
    const batchAt = expectLogEpochMoment(expect, batch.batchAt)
    const batchCAt = batchInstant(batchC)
    const directory = logBatchDirectory(batchAt)
    await expect
      .poll(() =>
        Date.parse(
          String(
            overviewFrames.data('subscription_page_hilos_logs').at(-1)
              ?.lastRotationAt,
          ),
        ),
      )
      .toBeGreaterThanOrEqual(batchCAt)
    const lastRotationAt = expectLogUtcMoment(
      expect,
      overviewFrames.data('subscription_page_hilos_logs').at(-1)
        ?.lastRotationAt,
    )
    expect(
      [...rotationFrames.rotations().values()].map((row) => row.batchAt),
    ).toContain(Date.parse(lastRotationAt))
    await expect(overview.getByTestId('hilos-logs-tile-rotation')).toHaveText(
      await logMomentLabel(overview, lastRotationAt, READER_ZONE, 'overview'),
    )

    await gotoPage(
      expect,
      viewer,
      `/hilos/logs/view/${batch.node}/${batchAt}/daemon.log`,
      PAGE_READY,
    )
    await expect(viewer.getByTestId('hilos-log-entry').first()).toBeVisible()
    await expectLogBatchViewer(expect, viewer, viewerFrames, batch, READER_ZONE)

    // The verdict of the rule, arrived at from the settings written above, through
    // the agent, onto a screen: this batch is being asked for.
    const rowA = page.getByTestId(`${ROW_ID_PREFIX}${batchA}`)
    const rowB = page.getByTestId(`${ROW_ID_PREFIX}${batchB}`)
    await expect(rowA).toContainText(
      await logMomentLabel(page, batchAt, READER_ZONE),
    )
    await expect(rowA).toContainText(directory)
    await expect(rowA).toContainText('Awaiting carry-off')
    // The footer counts a window that holds rows. The archive can be empty when
    // the screen opens — the scenario has not rotated yet — and an empty window
    // draws no "0 of 0".
    await expect(page.getByTestId('hilos-table-count')).toBeVisible()

    // The instruction: where the batch lies and what to type to copy it off. The
    // wording belongs to HIL-483, so what is asserted is that there is an address
    // at all — a node that reported no log root shows an apology in its place.
    await rowA.getByTestId('hilos-rotation-takeout').click()
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(
      page.getByTestId('hilos-rotation-takeout-path'),
    ).not.toBeEmpty()
    await expect(page.getByTestId('hilos-rotation-takeout-path')).toHaveText(
      String(batch.absolutePath),
    )
    await expect(page.getByTestId('hilos-rotation-takeout-path')).toContainText(
      directory,
    )
    await expect(
      page.getByTestId('hilos-rotation-takeout-command'),
    ).not.toBeEmpty()

    // Another tab of the same session visits rotations and leaves just before the
    // confirmation. The reply must still reach this dialog and close it on success.
    const tabC = await page.context().newPage()
    await gotoPage(expect, tabC, '/hilos/logs/rotations', PAGE_READY)
    await expect(tabC.getByTestId('hilos-table-loading')).toHaveCount(0)
    await tabC.close()
    await page.bringToFront()

    // The confirmation is answered by the node that owns the directory, and the
    // modal closes on that answer rather than on the click.
    const confirm = page.getByTestId('hilos-rotation-takeout-confirm')
    await confirm.scrollIntoViewIfNeeded()
    await expect(confirm).toBeVisible()
    await expect(confirm).toBeEnabled()
    await confirm.focus()
    await confirm.click()
    await expect(page.getByTestId('modal')).toHaveCount(0)
    const request = rotationFrames.request('logs_takeout_confirm')!
    expect(deliveryObject(request.data)).toEqual({
      nodeId: batch.node,
      batchTimestamp: batchAt,
    })
    const ack = rotationFrames.ack('logs_takeout_confirm')!
    expect(ack.requestId).toBe(request.requestId)
    const takenAt = expectLogEpochMoment(
      expect,
      deliveryObject(deliveryObject(ack.data).reply).takenAt,
    )
    expect(takenAt).toBeGreaterThanOrEqual(batchCAt)
    // The node's own sentence, because the badge is repainted a moment later and
    // elsewhere on the page; its wording belongs to HIL-483 and is not pinned here.
    await expect(page.getByTestId('hilos-toast-success')).toBeVisible()

    // The badge repaints because the node's index says the marker is on disk, not
    // because this tab drew what it had just asked for; the walk sends that index
    // now instead of on the next walk of its own. Batch B is untouched.
    await walkLogs()
    await expect(rowA).toContainText('Taken')
    await expect(rowB).toContainText('Awaiting carry-off')

    // The takeout modal stands open on batch B while another tab puts it back
    // under protection. The batch leaves the Awaiting window under the dialog;
    // its focused row still reaches the dialog and locks the stale confirmation.
    await shownByTestId(page, 'hilos-table-filter-state').click()
    await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)
    await expect(rowB).toBeVisible()
    await rowB.getByTestId('hilos-rotation-takeout').click()
    const modal = page.getByTestId('modal')
    await expect(modal).toBeVisible()

    // Batch B is among the hundred newest, so the count criterion alone protects
    // it; the walk carries the new verdict to the screen at once.
    await setSetting(tabB, RETENTION_KEEP_BATCHES, '100')
    await walkLogs()
    await expect(rowB).toHaveCount(0)

    await expect(
      page.getByTestId('hilos-rotation-takeout-notice'),
    ).toContainText('This batch is protected again')
    await expect(
      page.getByTestId('hilos-rotation-takeout-confirm'),
    ).toBeDisabled()
    await expect(modal).toBeVisible()

    // Back to one batch: the count criterion goes back to leaving batch B
    // unprotected after the operator closes the dialog.
    await page.getByTestId('modal-close').click()
    await expect(modal).toHaveCount(0)
    await setSetting(tabB, RETENTION_KEEP_BATCHES, '1')
    await walkLogs()
    await shownByTestId(page, 'hilos-table-filter-state').click()
    await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
    await expect(rowB).toContainText('Awaiting carry-off')

    // The withdrawal dialog opens before the cleaner runs and stays open while
    // batch A goes.
    await expect(rowA).toContainText('Taken')
    await rowA.getByTestId('hilos-rotation-undo').click()
    await expect(modal).toBeVisible()
    const deadline = takenAt + 120_000
    await expect
      .poll(() => rotationFrames.rotations().get(batchA)?.pruneNotBefore)
      .toBe(deadline)
    await expect(page.getByTestId('hilos-rotation-undo-deadline')).toHaveText(
      `The cleaner may delete this batch after ${await logMomentLabel(page, deadline, READER_ZONE)}.`,
    )
    await setSetting(tabB, TAKEOUT_UNDO_WINDOW, '0')
    // A policy-only change changes no file, so no walk of the node's own would
    // send an index for it before the keepalive. The walk sends one now, before
    // the attempt that runs the cleaner.
    await walkLogs()
    await expect
      .poll(() => rotationFrames.rotations().get(batchA)?.pruneNotBefore)
      .toBeNull()
    await expect(page.getByTestId('hilos-rotation-undo-deadline')).toHaveText(
      'The cleaner may delete this batch as soon as it next runs.',
    )

    // The cleaner has no trigger of its own: it rides the ATTEMPT to rotate, so one
    // more attempt is what runs it (HIL-382) — batch D, should it move anything.
    await leaveBatchSecond(batchC)
    await rotateLogs()

    // What the whole scenario was built to see: the batch the operator answered for
    // is gone from the table, it went without anybody reloading anything, and the
    // batch nobody answered for is still there with the verdict it had.
    await expect(rowA).toHaveCount(0)
    await expect(rowB).toContainText('Awaiting carry-off')

    await expect(page.getByTestId('hilos-rotation-undo-notice')).toContainText(
      'This batch is no longer on the node.',
    )
    await expect(page.getByTestId('hilos-rotation-undo-confirm')).toBeDisabled()
    await expect(modal).toBeVisible()
    await page.getByTestId('modal-close').click()
    await expect(modal).toHaveCount(0)

    // The control tab is deliberately left open and left registered: all four keys
    // still carry the values written above, and the cleanup is the one thing that
    // returns them.
  } finally {
    overviewFrames.stop()
    viewerFrames.stop()
    await test.info().attach('rotation-consumer-frames', {
      body: JSON.stringify({
        overview: overviewFrames.packets,
        viewer: { packets: viewerFrames.packets, sent: viewerFrames.sent },
      }),
      contentType: 'application/json',
    })
    await viewer.close()
    await overview.close()
  }
})

test('resets the rotations filter wherever the address put it, after a reload too', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  // The general address, with the filter switched on by hand.
  await gotoPage(expect, page, '/hilos/logs/rotations', PAGE_READY)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await shownByTestId(page, 'hilos-table-filter-state').click()
  await resetRotationFilters(page)

  // The filtered address, opened directly: the filter is the address's, and the
  // reset used to hand it back.
  await gotoPage(expect, page, '/hilos/logs/rotations/due', PAGE_READY)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await resetRotationFilters(page)

  // The same address after a reload, which rebuilds the screen from the address
  // alone.
  await gotoPage(expect, page, '/hilos/logs/rotations/due', PAGE_READY)
  await page.reload()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await resetRotationFilters(page)

  // The reset wrote the general address, so a reload stays unfiltered.
  await page.reload()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expect(
    shownByTestId(page, 'hilos-table-filter-state'),
  ).not.toBeChecked()
})
