import { expect, test } from '@playwright/test'
import {
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  expectStatusHead,
  expectLanguagePageHead,
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

// FIXME(F-031, 2026-10-25): Timeout ожидания воркера в withStatusAgentLoss · чужой · попытка 1 · HIL-1591
test(
  'a real candidate has text status and named, focus-trapped incident dialogs with Escape and focus return',
  { tag: '@quarantine:F-031' },
  async ({ page }) => {
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
  },
)

test('language pages expose one named head, current link and named table through keyboard navigation', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'BTC Tracker',
  })
  await expect(page.getByTestId('language-head-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-head-default')).toHaveAttribute(
    'title',
    'Default language',
  )
  await expect(
    page.getByTestId('language-head-default').locator('i'),
  ).toHaveAttribute('aria-hidden', 'true')
  for (const key of [
    'hilos_i18n_language_names',
    'hilos_i18n_language_locales',
  ]) {
    const link = page.getByTestId(`hilos-page-tab-${key}`)
    await link.focus()
    await expect(link).toBeFocused()
    await link.press('Enter')
    await expectPageReady(page)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: key,
      appName: 'BTC Tracker',
    })
    await expect(page.getByRole('table')).toHaveAccessibleName('English')
  }
})

test('the default language deletion explanation is named, keyboard readable and returns focus', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  const row = shownByTestId(page, /^hilos-table-row-/).filter({
    has: page.getByTestId('i18n-languages-delete-en'),
  })
  const trash = row.getByTestId('i18n-languages-delete-en')
  await expect(trash).toBeDisabled()
  await expect(trash).toHaveAccessibleDescription(
    'Cannot delete: this is the default language.',
  )
  const why = row.getByTestId('hilos-action-reason')
  await expect(why).toHaveAccessibleName('Why this action is unavailable')
  await why.focus()
  await why.press('Enter')
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Action unavailable')
  await expect(dialog).toBeFocused()
  await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(
    'Cannot delete: this is the default language.',
  )
  await dialog.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.getByTestId('modal-close').press('Tab')
  await expect(page.getByTestId('hilos-action-reason-close')).toBeFocused()
  await page.getByTestId('hilos-action-reason-close').press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await dialog.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(why).toBeFocused()
  await expect(trash).toBeDisabled()
  await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
})
