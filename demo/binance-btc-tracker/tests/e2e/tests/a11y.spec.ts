import { expect, test } from '@playwright/test'
import {
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  expectStatusHead,
  expectStatusIncidentDialog,
  shownByTestId,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  STATUS_FAULT_COMPONENT,
  withStatusAgentLoss,
} from '../helpers/status.js'

// Status's two pages and incident modal over the real socket. The framework's
// broader shell/table audit stays with its existing representative demos.
test.use({ colorScheme: 'light' })

test('Status pages expose the shared heading, current tab, title, named table and keyboard navigation', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, STATUS_PATH, PAGE_READY)
  await expectStatusHead(expect, page)
  await expect(page).toHaveTitle('Status · BTC Tracker')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Status · BTC Tracker',
  )
  const skip = page.getByTestId('skip-to-content')
  await expect(skip).toHaveAttribute('href', '#hilos-main-content')
  await skip.focus()
  await skip.press('Enter')
  await expect(page.getByRole('main')).toBeFocused()
  const tab = page.getByTestId('hilos-page-tab-hilos_status_components')
  await tab.focus()
  await tab.press('Enter')
  await expectPageReady(page)
  await expectStatusHead(expect, page, true)
  await expect(page).toHaveTitle('Status · BTC Tracker')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Status · BTC Tracker',
  )
  const table = page
    .getByTestId('hilos-status-components-table')
    .getByRole('table')
  await expect(table).toHaveAccessibleName('Status')
  const search = page.getByTestId('hilos-table-search')
  await expect(search).toHaveAccessibleName(
    'Search by name, description or agent…',
  )
  await search.focus()
  await expect(search).toBeFocused()
  await expect(
    shownByTestId(
      page,
      `hilos-status-component-state-${STATUS_FAULT_COMPONENT}`,
    ),
  ).toHaveText('Operational')
  await expect(page.getByTestId(/^hilos-table-sort-/)).toHaveCount(0)
  const overview = page.getByTestId('hilos-page-tab-hilos_status')
  await overview.focus()
  await overview.press('Enter')
  await expectPageReady(page)
  await expectStatusHead(expect, page)
})

test('a real candidate has text status and named, focus-trapped incident dialogs with Escape and focus return', async ({
  page,
}) => {
  // The real roster/collector observation and recovery are part of this modal proof.
  test.slow()
  await grantAdminToSelf(page)
  const workers = await page.context().newPage()
  const log = watchStatusFrames(page)
  try {
    await gotoPage(page, STATUS_PATH, PAGE_READY)
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await withStatusAgentLoss(workers, log, async () => {
      await expect
        .poll(() => log.latest()?.incidents?.length, {
          timeout: RESTART_MARK_TIMEOUT_MS,
        })
        .toBe(1)
      const id = log.latest()!.incidents![0].incidentId
      await expect(
        page.getByTestId(`hilos-status-incident-${id}`),
      ).toContainText('Checking')
      await expectStatusIncidentDialog(
        expect,
        page,
        page.getByTestId(`hilos-status-cancel-publication-${id}`),
        'Do not publish',
      )
      await expectStatusIncidentDialog(
        expect,
        page,
        page.getByTestId(`hilos-status-publish-now-${id}`),
        'Publish the outage now',
      )
    })
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
  } finally {
    log.stop()
    await workers.close()
  }
})
