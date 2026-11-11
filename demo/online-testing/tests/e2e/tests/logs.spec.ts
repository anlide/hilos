import type { Page } from '@playwright/test'
import { test, expect } from '../test.js'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
  typeInto,
  gotoPage,
  grantAdminToSelf,
  expectPageReady,
} from '../../../../../framework/frontend/e2e/index.js'

/** Every settings key the logging modes write, members first and the chosen mode last. */
const LOG_MODE_KEYS = [
  'logs.write_level',
  'logs.rotation.cron',
  'logs.rotation.max_live_size_bytes',
  'logs.rotation.max_age_seconds',
  'logs.archive_retention.max_age_seconds',
  'logs.preset',
]

/**
 * Return every logging mode setting to its catalog default by removing its custom value.
 *
 * @param page The test page.
 */
async function clearLogModeRows(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings')
  for (const key of LOG_MODE_KEYS) {
    await typeInto(page.getByTestId('hilos-table-search'), key)
    await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
    await clearCustomSetting(page, key)
  }
}

// The project's catalog and Angular router must connect all six Logs pages to
// their live controls and data. Follow their links, retain the due filter through
// reload and browser history, then change a logging mode and read its socket
// update on Rotations. Tail following and batch takeout have their own specs.

test('navigates Logs pages and preserves filters and live logging modes', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  await gotoPage(expect, page, '/hilos/logs')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Logs')
  const logsPages = page.getByRole('navigation', { name: 'Logs pages' })
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Overview',
  )
  await expect(page.getByTestId('hilos-logs-tile-daemon')).toContainText(/\d/)
  await expect(page.getByTestId('hilos-logs-tile-agents')).toContainText(/\d/)
  await expect(page.getByTestId('hilos-logs-tile-workers')).toContainText(/\d/)

  // The three windowed lists. Each is served by its own browser table, registered
  // against its page on the backend; the rows depend on what the stand has logged,
  // so read the returned count without prescribing the stand's stream inventory.
  await logsPages.getByTestId('hilos-page-tab-hilos_logs_keys').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/keys$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText('By key')
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(page.getByTestId('hilos-table-filter-class')).toBeVisible()
  await expect(page.getByTestId('hilos-table-count')).toContainText(/\d/)

  await logsPages.getByTestId('hilos-page-tab-hilos_logs_workers').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/workers$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'By worker',
  )
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(page.getByTestId('hilos-table-count')).toContainText(/\d/)
  const workerToggle = page.getByTestId('hilos-table-filter-type')
  await expect(workerToggle).toBeVisible()
  await workerToggle.click()
  await expect(page.getByTestId('hilos-table-filter-reset')).toBeVisible()
  await page.getByTestId('hilos-table-filter-reset').click()
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)

  await logsPages.getByTestId('hilos-page-tab-hilos_logs_rotations').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Rotations',
  )
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(page.getByTestId('hilos-table-filter-node')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  // A window with rows counts them. An archive nothing has rotated into yet
  // reports its empty state instead.
  await expect(
    shownByTestId(page, 'hilos-table-count').or(
      shownByTestId(page, /^hilos-rotation-empty-/),
    ),
  ).toBeVisible()
  const rotationToggle = page.getByTestId('hilos-table-filter-state')
  await expect(rotationToggle).toBeVisible()
  await rotationToggle.click()
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)
  await expect(page.getByTestId('hilos-table-filter-reset')).toBeVisible()

  await page.reload()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)
  await expect(heading).toHaveText('Logs')
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Rotations',
  )
  await expect(page.getByTestId('hilos-table-filter-reset')).toBeVisible()
  await page.goBack()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/workers$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'By worker',
  )
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)
  await page.goForward()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Rotations',
  )
  await expect(page.getByTestId('hilos-table-filter-reset')).toBeVisible()
  await page.getByTestId('hilos-table-filter-reset').click()
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)

  // The viewer: the address is three slots, the stream is never guessed, and the
  // empty result asks for a stream before reading one.
  await logsPages.getByTestId('hilos-page-tab-hilos_logs_view').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/view$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText('Viewer')
  await expect(page.getByTestId('hilos-log-source')).toBeVisible()
  await expect(page.getByTestId('hilos-log-stream')).toBeVisible()
  await expect(page.getByTestId('hilos-log-level')).toBeVisible()
  await expect(page.getByTestId('hilos-log-count')).toHaveText(
    '0 entries shown',
  )
  // The catalog rides a frame the backend sends ahead of page_response, and the
  // outlet mounts the view only after that answer: the frame is held for the
  // late listener (HIL-873), so the pane asks for a stream instead of saying
  // the cluster picture has not arrived.
  await expect(page.getByTestId('hilos-log-empty-unchosen')).toBeVisible()

  // The logging modes screen and the way out of it into the general settings.
  await logsPages.getByTestId('hilos-page-tab-hilos_logs_settings').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/settings$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Settings',
  )
  // The three cards ride the same frame ahead of the answer: with it held, the
  // screen an administrator opens to choose a mode has something to click.
  await expect(page.getByTestId('hilos-setting-preset-frugal')).toBeVisible()
  await expect(page.getByTestId('hilos-setting-preset-normal')).toBeVisible()
  await expect(
    page.getByTestId('hilos-setting-preset-investigation'),
  ).toBeVisible()

  // A stand that has never applied a mode has no settings rows at all, so the values
  // in force are the defaults, and those are what the chosen mode declares (HIL-906)
  // except for the rotation schedule, which the stand holds out of a run's reach. So
  // the card opens with that one difference.
  const differences = page.getByTestId('hilos-setting-preset-differences')
  await expect(differences.getByRole('listitem')).toHaveCount(1)
  await expect(differences).toContainText('0 3 29 2 *')

  await logsPages.getByTestId('hilos-page-tab-hilos_logs').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs$/)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Overview',
  )
  await expect(page.getByTestId('hilos-logs-tile-daemon')).toContainText(/\d/)
  await logsPages.getByTestId('hilos-page-tab-hilos_logs_settings').click()
  await expectPageReady(expect, page)
  await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
    'Settings',
  )

  // And the clicks themselves, which are the only writes the section makes: both were
  // refused until the agent serving this screen owned the settings it writes (HIL-888),
  // and everything above passed anyway. To have something to put back, one member of
  // the mode is edited by hand on the general settings screen, as the mockup has it —
  // the retention age, which only changes which batches are suggested for takeout.
  const retentionKey = 'logs.archive_retention.max_age_seconds'
  await page.getByTestId('hilos-setting-preset-settings-link').click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/settings$/)
  await typeInto(page.getByTestId('hilos-table-search'), retentionKey)
  await expect(
    page.getByTestId(`hilos-table-row-${retentionKey}`),
  ).toBeVisible()

  let passed = false
  let cleanupFailure: unknown
  try {
    await setCustomSetting(page, retentionKey, '1209600')
    await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)

    await gotoPage(expect, page, '/hilos/logs/settings')
    // The block shows without the edit too; its second line is the edit arriving.
    await expect(differences.getByRole('listitem')).toHaveCount(2)

    // Putting them back is the gesture the defect was found on, and it asks nothing
    // first: the card is already the chosen one.
    const revert = page.getByTestId('hilos-setting-preset-revert')
    await expect(revert).toBeEnabled()
    await revert.click()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      "The mode's values are back.",
    )
    await page.getByTestId('hilos-toast-close').click()
    await expect(page.getByTestId('hilos-toast-success')).toHaveCount(0)
    // The differences going is the new state arriving over the socket, not a guess about
    // when it will: the backend answers the action with nothing and pushes the state.
    await expect(differences).toHaveCount(0)

    // Choosing another mode, which is the second gesture and the second sentence. With
    // the values back there is nothing of anyone's own to overwrite, so no confirmation
    // opens and the card applies at once.
    const frugal = page.getByTestId('hilos-setting-preset-frugal')
    await expect(frugal).toBeEnabled()
    await frugal.click()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'The mode is applied.',
    )
    await page.getByTestId('hilos-toast-close').click()
    await expect(page.getByTestId('hilos-toast-success')).toHaveCount(0)

    // The mode chosen on the modes screen reaches the rotations strip over the
    // wire. Units hold a forged header, so only this visit proves the live one.
    await logsPages.getByTestId('hilos-page-tab-hilos_logs_rotations').click()
    await expectPageReady(expect, page)
    await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
      'Rotations',
    )
    await expect(page.getByTestId('hilos-rotation-rule')).toContainText(
      'Mode “Frugal”: rotates at 03:00 or at 256 MiB',
    )
    await logsPages.getByTestId('hilos-page-tab-hilos_logs_settings').click()
    await expectPageReady(expect, page)
    await expect(logsPages.locator('[aria-current="page"]')).toHaveText(
      'Settings',
    )

    // Back to the default, because the stand is shared and frugal raises the write
    // threshold to warnings — every later spec would run against a quieter log. The
    // chosen card is disabled, so this one becoming clickable is the push arriving.
    const normal = page.getByTestId('hilos-setting-preset-normal')
    await expect(normal).toBeEnabled()
    await normal.click()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'The mode is applied.',
    )
    await page.getByTestId('hilos-toast-close').click()
    await expect(frugal).toBeEnabled()
    passed = true
  } finally {
    // Clearing the mode settings returns the stand to what the test found at
    // its start (no mode applied, the stand's rotation schedule the one
    // difference) and keeps any earlier failure in the test report.
    try {
      await clearLogModeRows(page)
    } catch (cleanupError) {
      if (passed) {
        cleanupFailure = cleanupError
      } else {
        await test.info().attach('cleanup-error', {
          body: String(cleanupError),
          contentType: 'text/plain',
        })
      }
    }
  }
  if (cleanupFailure) throw cleanupFailure
})
