import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_READY } from '../helpers/page'

// Rotation, carrying a batch off and cleaning it up, end to end (HIL-763): the
// second and last through-scenario of the logs epic. It forces a rotation, sees
// the batch it made land on the rotations screen, reads the takeout instruction,
// confirms it, and watches the cleaner remove exactly that batch while the table
// updates itself.
//
// It drives the stand the way an operator would, through four catalog settings
// and the log-store owner's own live walk — no test-only command exists here and
// none is wanted (the owner's decision, 2026-09-02). That is the point of the
// scenario rather than an accident of it: the one thing nothing else checks is
// that a setting written into the database reaches the agent, that the policy is
// re-read, and that the verdict it yields travels to a screen nobody reloaded.
// A stand-configuration override would have measured an installation that does
// not exist in production, and there is no injectable clock to age anything with
// (docs/agents/cli/commands.md, "Time-based features: no universal clock").
//
// What is NOT checked here, because it belongs to the leaves that built it:
// takeout refusals and the marker's shape (HIL-483), the order files are removed
// in (HIL-382), withdrawing the acknowledgement (HIL-759), the screen's filters
// and empty states (HIL-387), the retention predicate itself (HIL-381). Nor the
// cluster half of any of it: this installation is single-node, and its rows are
// keyed on a dash in place of a node name.
//
// The scenario shares the stand with every other chat spec, and rotation moves
// live log files out from under all of them — which is safe only because the
// suite is serial here (CI=1 on chat-e2e-runner, workers=1 in the config). It is
// also why the four keys go back to their defaults in an afterEach: a threshold
// left raised keeps rotating every five seconds for the rest of the run, and the
// worker logs a red e2e is diagnosed from would walk into the archive.

/** The trigger axis the scenario switches on and off; 0 is the stand's default (no rotation ever). */
const ROTATION_MAX_AGE = 'logs.rotation.max_age_seconds'

/** Count criterion of the retention rule: the newest N batches are protected whatever their age. */
const RETENTION_KEEP_BATCHES = 'logs.archive_retention.keep_batches'

/** Age criterion of the retention rule, in seconds. Both criteria hold at once, so both are moved. */
const RETENTION_MAX_AGE = 'logs.archive_retention.max_age_seconds'

/** How long this node protects a confirmed batch from its own cleaner, in seconds. */
const TAKEOUT_UNDO_WINDOW = 'logs.takeout.undo_window_seconds'

/** Every key the scenario writes, in the order the cleanup returns them by. */
const MOVED_KEYS = [
  ROTATION_MAX_AGE,
  RETENTION_KEEP_BATCHES,
  RETENTION_MAX_AGE,
  TAKEOUT_UNDO_WINDOW,
]

/** Prefix the framework table gives every row's `data-id`; the key follows it. */
const ROW_ID_PREFIX = 'hilos-table-row-'

/**
 * Cap for anything that rides the log-store owner's live walk.
 *
 * The walk runs every five seconds (LogStoreAgent::LIVE_SCAN_INTERVAL_SECONDS)
 * and re-reads the policy on every pass, so a written setting is obeyed within
 * one interval; the cap is four of them, because a starved box stretches the
 * walk and not the rule.
 */
const WALK_WAIT_MS = 20_000

/** The control tab, so the cleanup can reach the settings screen after a failure. */
let control: Page | null = null

/**
 * Narrow the server window to one settings key so assertions ignore pagination.
 *
 * @param tab The settings tab.
 * @param key Catalog key to isolate.
 */
async function isolate(tab: Page, key: string): Promise<void> {
  await tab.getByTestId('hilos-table-search').fill(key)
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toBeVisible()
}

/**
 * Write a custom value for one catalog key, the way a person would.
 *
 * The value is typed rather than filled: a Vue input bound with `v-model` can miss
 * a value that arrives in one assignment, and the project's cure is `fill('')`
 * plus `pressSequentially`.
 *
 * @param tab The settings tab.
 * @param key Catalog key to write.
 * @param value Value to write, as the field takes it.
 */
async function setSetting(tab: Page, key: string, value: string): Promise<void> {
  await isolate(tab, key)
  await tab.getByTestId(`hilos-settings-edit-${key}`).click()
  await tab.getByTestId('hilos-settings-edit-custom').check()
  const field = tab.getByTestId('hilos-settings-edit-value')
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
  await tab.getByTestId('hilos-settings-edit-save').click()

  // The dialog closes on the server's word, so its going is what says the value
  // was accepted and written; the row then reads as a custom value rather than
  // as the catalog default it was.
  await expect(tab.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toContainText('custom')
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
  if (((await row.textContent()) ?? '').includes('custom') === false) {
    return
  }

  await tab.getByTestId(`hilos-settings-edit-${key}`).click()
  await tab.getByTestId('hilos-settings-edit-custom').uncheck()
  await tab.getByTestId('hilos-settings-edit-save').click()
  await expect(row).toContainText('default')
}

/**
 * The row keys currently in a window, in the order the table hands them over.
 *
 * @param page The tab holding the table.
 */
async function rowKeys(page: Page): Promise<string[]> {
  return page.locator(`[data-id^="${ROW_ID_PREFIX}"]`).evaluateAll(
    (rows, prefix) =>
      rows.map((row) =>
        (row.getAttribute('data-id') ?? '').slice(prefix.length),
      ),
    ROW_ID_PREFIX,
  )
}

/**
 * The rotation instant a row key carries. The key is `<node>:<unix timestamp>`,
 * and the node of a single-node installation is written as a dash.
 *
 * @param key A rotations-table row key.
 */
function batchInstant(key: string): number {
  return Number(key.split(':')[1] ?? 0)
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

test.afterEach(async () => {
  // Runs on the passing and the failing path alike, and it is the failing one it
  // exists for: the trigger this spec raises is the only thing on the stand that
  // rotates at all, and nothing else would ever lower it again.
  const tab = control
  control = null
  if (tab === null) {
    return
  }
  for (const key of MOVED_KEYS) {
    await resetSetting(tab, key)
  }
})

test('rotates on the configured threshold, carries a batch off on the operator confirmation, and prunes exactly that batch while the table updates itself', async ({
  page,
}) => {
  // Two batches are waited for, one after another, and each rides a five-second
  // walk; the default cap would expire in the middle of the first one.
  test.slow()

  await signUpAdmin(page)

  // The observer opens first and never leaves: everything it is asked about
  // afterwards has to arrive by push, on a page that did not navigate.
  await gotoPage(page, '/hilos/logs/rotations', PAGE_READY)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

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
  await gotoPage(tabB, '/hilos/settings', PAGE_READY)
  await expect(tabB.getByTestId('hilos-viewport-table')).toBeVisible()

  // The three preparatory keys, none of which starts a clock. Both retention
  // criteria are needed together: they are joined by "and", and the count one —
  // the twenty newest batches are always protected — is not something ageing or
  // waiting can take away.
  await setSetting(tabB, RETENTION_KEEP_BATCHES, '0')
  await setSetting(tabB, RETENTION_MAX_AGE, '1')
  await setSetting(tabB, TAKEOUT_UNDO_WINDOW, '0')

  // The archive as it stands, read here rather than at the top: every write above
  // repaints the badges and so re-serves this window, which means the picture on
  // screen now is one the page has answered for three times over. Nothing can
  // have been added to it either — until the next line, no axis of rotation is on.
  const before = await rowKeys(page)

  // And the trigger. Every axis of rotation is off on a stand, so until this line
  // no rotation would ever happen; from here the owner of the log directory
  // rotates on each of its live walks.
  await setSetting(tabB, ROTATION_MAX_AGE, '1')

  // The gate, before anything is waited for: the rule line on the observer says
  // the new threshold. Without it a setting that never landed and a rotation that
  // never ran are the same red — a timeout on a row that did not appear.
  await expect(page.getByTestId('hilos-rotation-rule')).toContainText(
    '1 s after the last rotation',
    { timeout: WALK_WAIT_MS },
  )

  // Batch A: the first rotation this scenario caused. It arrives on a page that
  // has not navigated since it opened.
  await expect
    .poll(async () => (await newBatchKeys(page, before)).length, {
      timeout: WALK_WAIT_MS,
    })
    .toBeGreaterThanOrEqual(1)
  const batchA = (await newBatchKeys(page, before))[0] ?? ''
  expect(batchA).not.toBe('')

  // Batch B, the second one, and it is guaranteed to exist rather than hoped for:
  // a rotation that moved anything writes a line saying so under the agent's own
  // name, the master puts that line in the live file, and the next rotation
  // carries that file off (HIL-480).
  await expect
    .poll(async () => (await newBatchKeys(page, before)).length, {
      timeout: WALK_WAIT_MS,
    })
    .toBeGreaterThanOrEqual(2)
  const batchB = (await newBatchKeys(page, before)).filter(
    (key) => key !== batchA,
  )[0]
  expect(batchB).toBeTruthy()

  // The trigger goes back down as soon as both batches are here: left up it makes
  // a batch every five seconds, and the rest of the scenario wants a table that
  // holds still.
  await setSetting(tabB, ROTATION_MAX_AGE, '0')

  // The verdict of the rule, arrived at from the settings written above, through
  // the agent, onto a screen: this batch is being asked for.
  const rowA = page.getByTestId(`${ROW_ID_PREFIX}${batchA}`)
  const rowB = page.getByTestId(`${ROW_ID_PREFIX}${batchB}`)
  await expect(rowA).toContainText('Awaiting carry-off', {
    timeout: WALK_WAIT_MS,
  })

  // The instruction: where the batch lies and what to type to copy it off. The
  // wording belongs to HIL-483, so what is asserted is that there is an address
  // at all — a node that reported no log root shows an apology in its place.
  await rowA.getByTestId('hilos-rotation-takeout').click()
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('hilos-rotation-takeout-path')).not.toBeEmpty()
  await expect(
    page.getByTestId('hilos-rotation-takeout-command'),
  ).not.toBeEmpty()

  // The confirmation is answered by the node that owns the directory, and the
  // modal closes on that answer rather than on the click.
  await page.getByTestId('hilos-rotation-takeout-confirm').click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
  // The node's own sentence, because the badge is repainted a moment later and
  // elsewhere on the page; its wording belongs to HIL-483 and is not pinned here.
  await expect(page.getByTestId('hilos-toast-success')).toBeVisible()

  // The badge repaints because the node's next index says the marker is on disk,
  // not because this tab drew what it had just asked for. Batch B is untouched.
  await expect(rowA).toContainText('Taken', { timeout: WALK_WAIT_MS })
  await expect(rowB).toContainText('Awaiting carry-off')

  // The cleaner has no trigger of its own: it rides the ATTEMPT to rotate, so one
  // more attempt is what runs it (HIL-382).
  await setSetting(tabB, ROTATION_MAX_AGE, '1')

  // What the whole scenario was built to see: the batch the operator answered for
  // is gone from the table, it went without anybody reloading anything, and the
  // batch nobody answered for is still there with the verdict it had.
  await expect(rowA).toHaveCount(0, { timeout: WALK_WAIT_MS })
  await expect(rowB).toContainText('Awaiting carry-off')

  // The control tab is deliberately left open and left registered: the trigger is
  // up again as of the line above, and the cleanup is the one thing that lowers
  // it.
})
