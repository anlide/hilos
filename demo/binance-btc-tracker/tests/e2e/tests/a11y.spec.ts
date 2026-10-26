import { expect, test } from '@playwright/test'
import {
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  expectPublicStatusAccessibility,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusHead,
  expectLanguagePageHead,
  expectLogsPageHead,
  expectStatusIncidentDialog,
  shownByTestId,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import {
  expectPageReady,
  gotoPage,
  gotoPublicStatus,
  PAGE_READY,
} from '../helpers/page.js'
import {
  STATUS_FAULT_COMPONENT,
  withStatusAgentLoss,
} from '../helpers/status.js'

// Status's two pages and incident modal over the real socket. The framework's
// broader shell/table audit stays with its existing representative demos.
test.use({ colorScheme: 'light' })

test('Analytics Person has one entity heading naming its table and a decorative avatar without tabs', async ({
  page,
}) => {
  const userId = await grantAdminToSelf(page)
  await expect(page.getByTestId('self-user')).toBeVisible()
  const name = await page.getByTestId('self-user').innerText()
  const label = `${name} (#${userId})`
  await gotoPage(page, `/hilos/analytics/people/${userId}`, PAGE_READY)

  const carrier = page.getByTestId('hilos-admin-page')
  await expect(carrier).toHaveAttribute('data-page', 'hilos_analytics_person')
  const head = carrier.getByTestId('hilos-page-head')
  const heading = head.getByRole('heading', { level: 1 })
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(heading).toHaveText(label)
  await expect(heading).toHaveAttribute('id', /\S+/)
  const headingId = (await heading.getAttribute('id'))!
  const table = carrier
    .getByTestId('hilos-analytics-browser-sessions-table')
    .getByRole('table')
  await expect(table).toBeVisible()
  await expect(table).toHaveAttribute('aria-labelledby', headingId)
  await expect(table).toHaveAccessibleName(label)
  await expect(carrier.getByTestId('hilos-table-title')).toHaveCount(0)

  const avatar = head.getByTestId('analytics-person-head-avatar')
  await expect(avatar).toBeVisible()
  await expect(avatar).toHaveAttribute('aria-hidden', 'true')
  await expect(avatar.locator('img:not([alt=""])')).toHaveCount(0)
  await expect(head.getByRole('img')).toHaveCount(0)
  const breadcrumb = head.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveRole('navigation')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  await expect(breadcrumb.locator('li')).toHaveText([
    'Hilos',
    'Analytics',
    'People',
    label,
  ])
  const current = breadcrumb.locator('li').last()
  await expect(current).toHaveAttribute('aria-current', 'page')
  await expect(current.getByRole('link')).toHaveCount(0)
  await expect(head.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByRole('tablist')).toHaveCount(0)
})

test('Logs pages expose named navigation, one heading and ordinary keyboard links', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/logs', PAGE_READY)
  await expectLogsPageHead(expect, page, 'hilos_logs')
  const overview = page.getByTestId('hilos-page-tab-hilos_logs')
  const keys = page.getByTestId('hilos-page-tab-hilos_logs_keys')
  await overview.focus()
  await expect(overview).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(keys).toBeFocused()
  await keys.press('Enter')
  await expectPageReady(page)
  await expect(page).toHaveURL(/\/hilos\/logs\/keys$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_keys')
  await keys.focus()
  await page.keyboard.press('Tab')
  const workers = page.getByTestId('hilos-page-tab-hilos_logs_workers')
  await expect(workers).toBeFocused()
  await workers.press('Enter')
  await expectPageReady(page)
  await expect(page).toHaveURL(/\/hilos\/logs\/workers$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_workers')
})

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

test('public Status exposes file-backed semantics, shell landmarks and keyboard day details', async ({
  page,
}) => {
  const log = watchPublicStatus(page)
  try {
    await gotoPublicStatus(page)
    await expectPublicStatusPair(expect, page, log, ({ status }) =>
      status.components.every((component) => component.state === 'operational'),
    )
    await expect(page).toHaveTitle('Status · BTC Tracker')
    await expect(page.getByTestId('page-title')).toHaveText(
      'Status · BTC Tracker',
    )
    await expectPublicStatusAccessibility(expect, page, 'Settings library')
    await expect(page.getByRole('navigation')).not.toHaveCount(0)
    await expect(page.getByRole('contentinfo')).toHaveCount(1)
    expectPublicStatusReadOnly(expect, log)
  } finally {
    await log.stop()
    await test.info().attach('public-status-accessibility-real-files', {
      body: JSON.stringify(log),
      contentType: 'application/json',
    })
  }
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
