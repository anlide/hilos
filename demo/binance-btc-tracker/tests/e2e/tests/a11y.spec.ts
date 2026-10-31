import { expect, test as base } from '@playwright/test'
import {
  DAEMON_NODE_PATH,
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  STATUS_WORKS_PATH,
  STATUS_WORK_SCHEDULE,
  STATUS_WORK_CHANGE,
  draftStatusWork,
  expectStatusWorksAccessibility,
  expectStatusWorkScheduleDialog,
  holdSocketAction,
  latestStatusWork,
  openStatusWorkSchedule,
  statusWorkRows,
  statusWorkFixtures,
  type StatusWorkFixtureScope,
  submitStatusWork,
  trackedActionReplies,
  dismissToasts,
  MAINTENANCE_PATH,
  expectMaintenanceWatchdogThresholds,
  expectDaemonNodeTableName,
  expectPublicStatusAccessibility,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusHead,
  expectLanguagePageHead,
  expectLogsPageHead,
  expectChangeLogSectionHead,
  expectStatusIncidentDialog,
  shownByTestId,
  watchPublicStatus,
  watchStatusFrames,
  DAEMON_ROOT_PATH,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonSectionAccessibility,
  expectPageReady,
  expectMaintenancePageHead,
  gotoPage,
  gotoPublicStatus,
  PAGE_READY,
  grantAdminToSelf,
  sessionToken,
  watchDeliveryFrames,
  clickSubmit,
  typeInto,
  STATUS_SUBSCRIBERS_PATH,
  expectStatusSubscribersAccessibility,
  expectStatusSubscriberRemoveDialog,
  expectStatusSubscribeDialog,
  expectStatusUnsubscribeDialog,
  expectTrackedActionReply,
  openStatusMailLink,
  removeStatusSubscriber,
  statusSubscriberId,
  subscribeToStatus,
  uniqueEmail,
  watchHeight,
  deliveryObject,
} from '../../../../../framework/frontend/e2e/index.js'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'
import {
  STATUS_FAULT_COMPONENT,
  withStatusAgentLoss,
} from '../helpers/status.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'
import {
  enterProtectedMode,
  leaveProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'

const statusExpect = expect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })
const pausedExpect = statusExpect.configure({
  timeout: Math.min(STATUS_EXPECT_TIMEOUT_MS, RESTART_MARK_TIMEOUT_MS),
})

// Status's two pages and incident modal over the real socket. The framework's
// broader shell/table audit stays with its existing representative demos.
const test = base.extend<{ statusWorks: StatusWorkFixtureScope }>({
  statusWorks: statusWorkFixtures(expect),
})

test.use({ colorScheme: 'light' })

test('Maintenance Overview exposes one shared heading and five readable, read-only watchdog thresholds', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
    await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
    await expectMaintenanceWatchdogThresholds(expect, page, frames)
    const slot = page.getByTestId('hilos-maintenance-watchdog-slot')
    await expect(slot).toHaveRole('status')
    await expect(slot).toHaveAttribute('aria-live', 'polite')
    const idle = slot.getByTestId('hilos-maintenance-watchdog-idle')
    await expect(idle).toHaveAttribute('aria-hidden', 'true')
    await expect(idle.locator('button, a, input, [tabindex]')).toHaveCount(0)
    await expect(
      slot.getByTestId('hilos-maintenance-watchdog-details'),
    ).toHaveCount(0)
  } finally {
    frames.stop()
    await test.info().attach('maintenance-watchdog-accessibility-wire', {
      body: JSON.stringify(frames),
      contentType: 'application/json',
    })
  }
})

test('Backups names its table from one heading and exposes both Maintenance links to the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/backup', PAGE_READY)
  const head = page.getByTestId('hilos-page-head')
  const heading = head.getByRole('heading', { level: 1 })
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Backups')
  await expect(heading).toHaveAttribute('id', /\S+/)
  const headingId = (await heading.getAttribute('id'))!
  const table = page.getByTestId('hilos-viewport-table').getByRole('table')
  await expect(table).toHaveAttribute('aria-labelledby', headingId)
  await expect(table).toHaveAccessibleName('Backups')
  const icon = head.locator('[aria-hidden="true"]:has(> .bi-safe2)')
  await expect(icon).toHaveCount(1)
  await expect(icon).toBeVisible()
  await expect(head.getByRole('img')).toHaveCount(0)

  const circle = page.getByTestId('hilos-backup-circle-pointer-open')
  await expect(circle).toHaveRole('link')
  await expect(circle).toHaveAccessibleName('Open Maintenance')
  await expect(circle).toHaveAttribute('href', '/hilos/maintenance/circle')
  await circle.focus()
  await expect(circle).toBeFocused()
  await circle.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/maintenance\/circle$/)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance_circle')
  await expect(page.getByTestId('hilos-maintenance-circle-table')).toBeVisible()

  // The personal link exists only in a verification window. A test freeze is
  // enough for keyboard navigation; the restore ladder has its own real replay.
  try {
    expect(
      await enterProtectedMode('e2e-freeze', '', await sessionToken(page)),
    ).toBe('active')
    expect(await leaveProtectedMode()).toBe('verifying')
    await gotoPage(expect, page, '/hilos/backup', PAGE_READY)
    const reopen = page.getByTestId('hilos-backup-reopen')
    await expect(reopen).toHaveRole('link')
    await expect(reopen).toHaveAccessibleName('Open the system')
    await expect(reopen).toHaveAttribute('href', '/hilos/maintenance')
    await reopen.focus()
    await expect(reopen).toBeFocused()
    await reopen.press('Enter')
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/maintenance$/)
    await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
    await expect(page.getByTestId('hilos-backup-reopen-modal')).toHaveCount(0)
  } finally {
    await openProtectedModeIfAny()
  }
})

test('Analytics Person has one entity heading naming its table and a decorative avatar without tabs', async ({
  page,
}) => {
  const userId = await grantAdminToSelf(expect, page)
  await expect(page.getByTestId('self-user')).toBeVisible()
  const name = await page.getByTestId('self-user').innerText()
  const label = `${name} (#${userId})`
  await gotoPage(expect, page, `/hilos/analytics/people/${userId}`, PAGE_READY)

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
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/logs', PAGE_READY)
  await expectLogsPageHead(expect, page, 'hilos_logs')
  const overview = page.getByTestId('hilos-page-tab-hilos_logs')
  const keys = page.getByTestId('hilos-page-tab-hilos_logs_keys')
  await overview.focus()
  await expect(overview).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(keys).toBeFocused()
  await keys.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/keys$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_keys')
  await keys.focus()
  await page.keyboard.press('Tab')
  const workers = page.getByTestId('hilos-page-tab-hilos_logs_workers')
  await expect(workers).toBeFocused()
  await workers.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/workers$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_workers')
})

test('Status pages expose the shared heading, current tab, title, named table and keyboard navigation', async ({
  page,
}) => {
  const expect = statusExpect
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
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
  await expectPageReady(expect, page)
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
  await expectPageReady(expect, page)
  await expectStatusHead(expect, page)
})

test('public Status exposes file-backed semantics, shell landmarks and keyboard day details', async ({
  page,
}) => {
  const expect = statusExpect
  const log = watchPublicStatus(page)
  try {
    await gotoPublicStatus(expect, page)
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

// FIXME(F-056, 31.10.2026): таймаут первого чтения Workers (expect.poll 12000 ms) · чужой · попытка 1 · HIL-1729
test(
  'a real candidate has text status and named, focus-trapped incident dialogs with Escape and focus return',
  { tag: '@quarantine:F-056' },
  async ({ page }) => {
    const expect = statusExpect
    // The real roster/collector observation and recovery are part of this modal proof.
    test.slow()
    await grantAdminToSelf(expect, page)
    const workers = await page.context().newPage()
    const log = watchStatusFrames(page)
    try {
      await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
      await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
      await withStatusAgentLoss(expect, workers, log, async () => {
        const expect = pausedExpect
        await expect.poll(() => log.latest()?.incidents?.length).toBe(1)
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
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en', PAGE_READY)
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
    await expectPageReady(expect, page)
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
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
  const row = shownByTestId(page, /^hilos-table-row-/).filter({
    has: page.getByTestId('i18n-languages-delete-en'),
  })
  const trash = row.getByTestId('i18n-languages-delete-en')
  await expect(trash).toBeDisabled()
  await expect(trash).toHaveAccessibleDescription(
    'This language cannot be deleted because it is the default language.',
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
    'This language cannot be deleted because it is the default language.',
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

test('the Env orphans tab names its one table through the heading from the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, DAEMON_NODE_PATH, PAGE_READY)
  const link = page.getByTestId('hilos-page-tab-hilos_daemon_env_orphans')
  await link.focus()
  await expect(link).toBeFocused()
  await link.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(`${DAEMON_NODE_PATH}/env/orphans`)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expectDaemonNodeTableName(expect, page)
})

test.describe('Daemon section accessibility', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`Daemon section links, shared heading and Placement keyboard controls as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, DAEMON_ROOT_PATH)
      await expectDaemonPage(expect, page, 'hilos_daemon')
      await expectDaemonSectionHead(expect, page, 'hilos_daemon')
      const placement = page.getByTestId(
        'hilos-page-tab-hilos_daemon_placement',
      )
      await placement.focus()
      await expect(placement).toBeFocused()
      await placement.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_placement')
      await expectDaemonSectionAccessibility(expect, page)
      if (viewer)
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      const overview = page.getByTestId('hilos-page-tab-hilos_daemon')
      await overview.focus()
      await expect(overview).toBeFocused()
      await overview.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon')
      await expectDaemonSectionHead(expect, page, 'hilos_daemon')
    })
  }
})

test('Change Log names its section navigation and tables from one shared heading', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/change-log')
  await expectChangeLogSectionHead(expect, page, 'hilos_change_log')
  await expect(page.getByTestId(/^hilos-change-log-link-/)).toHaveCount(2)
  await expect(page.getByTestId('hilos-change-log-link-feed')).toHaveRole(
    'link',
  )
  await expect(page.getByTestId('hilos-change-log-link-tables')).toHaveRole(
    'link',
  )
  for (const key of [
    'hilos_change_log_feed',
    'hilos_change_log_tables',
    'hilos_change_log_noisy',
  ]) {
    const tab = page.getByTestId(`hilos-page-tab-${key}`)
    await tab.focus()
    await page.keyboard.press('Enter')
    await expectPageReady(expect, page)
    await expectChangeLogSectionHead(expect, page, key)
    await expect(
      page.getByRole('table', { name: 'Change Log', exact: true }),
    ).toBeVisible()
    await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  }
})

test('Status Subscribers names its table and destructive dialog, supports keyboard sorting and explains viewer controls', async ({
  page,
  browser,
  baseURL,
}) => {
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const guest = await guestContext.newPage()
  const viewer = await guestContext.newPage()
  const logs = [page, guest, viewer].map(watchDeliveryFrames)
  const email = uniqueEmail()
  try {
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, logs[1], email)
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, STATUS_PATH)
    const link = page.getByTestId('hilos-page-tab-hilos_status_subscribers')
    await link.focus()
    await link.press('Enter')
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page, 'subscribers')
    await expect(page.getByTestId('page-title')).toHaveAttribute(
      'aria-live',
      'polite',
    )
    const skip = page.getByTestId('skip-to-content')
    const beforeSkip = logs[0].sent.length
    await skip.focus()
    await skip.press('Enter')
    await expect(page.getByRole('main')).toBeFocused()
    await expectPageReady(expect, page)
    expect(
      logs[0].sent
        .slice(beforeSkip)
        .filter(({ wire }) => deliveryObject(wire).type === 'page_subscribe'),
    ).toEqual([])
    await expectStatusSubscribersAccessibility(expect, page, logs[0])
    const id = await statusSubscriberId(expect, page, email)
    await expect(
      shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Awaiting confirmation')
    await expectStatusSubscriberRemoveDialog(expect, page, id)
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_SUBSCRIBERS_PATH)
    await expectStatusHead(expect, viewer, 'subscribers')
    const trash = shownByTestId(viewer, `hilos-status-subscriber-remove-${id}`)
    await expect(trash).toBeDisabled()
    await expect(trash).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-email-${id}`).getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    expect(JSON.stringify(logs[2].packets)).not.toContain(email)
    expect(await viewer.locator('body').textContent()).not.toContain(email)
    await removeStatusSubscriber(expect, page, logs[0], id)
  } finally {
    await test.info().attach('status-subscribers-accessibility-wire', {
      body: JSON.stringify(logs),
      contentType: 'application/json',
    })
    for (const log of logs) log.stop()
    await guestContext.close()
    await setAdminViewMode(false)
  }
})

test('public Status subscription and mailed unsubscribe expose named keyboard dialogs and announced feedback', async ({
  page,
}) => {
  const log = watchPublicStatus(page)
  const email = uniqueEmail()
  try {
    await gotoPublicStatus(expect, page)
    await expectPublicStatusPair(expect, page, log, ({ status }) =>
      status.components.every((component) => component.state === 'operational'),
    )
    await expectPublicStatusAccessibility(expect, page, 'Settings library')
    expectPublicStatusReadOnly(expect, log)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const room = await watchHeight(
      page.getByTestId('public-status-subscription-slot'),
    )
    await expectStatusSubscribeDialog(expect, page)
    const errorRoom = await watchHeight(
      page.getByTestId('status-subscription-error-slot'),
    )
    await typeInto(page.getByTestId('status-subscription-email'), 'invalid')
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-error')).toBeVisible()
    await expect(page.getByTestId('status-subscription-submit')).toBeEnabled()
    await expect(page.getByTestId('status-subscription-email')).toHaveAttribute(
      'aria-invalid',
      'true',
    )
    await errorRoom.unchanged()
    const since = log.wire.sent.length
    await typeInto(page.getByTestId('status-subscription-email'), email)
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_subscribe',
      since,
      { status: 'check_inbox' },
    )
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveAttribute('role', 'status')
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveText('Check your inbox — we sent a link to confirm the address.')
    await room.unchanged()
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    await openStatusMailLink(expect, page, links.unsubscribe)
    await expectStatusUnsubscribeDialog(expect, page, email)
    await openStatusMailLink(expect, page, links.unsubscribe)
    const removing = log.wire.sent.length
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_unsubscribe',
      removing,
      { status: 'unsubscribed' },
    )
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveText('You are unsubscribed. No more letters will come.')
  } finally {
    await log.stop()
    await test.info().attach('status-subscriptions-accessibility-wire', {
      body: JSON.stringify(log),
      contentType: 'application/json',
    })
  }
})

test('Status Works keyboard forms retain focus, announce live conflicts, lock busy requests and explain view mode', async ({
  page,
  browser,
  baseURL,
  statusWorks,
}) => {
  // Two real admin connections, native calendar entry and two correlated writes.
  test.slow()
  const gate = await holdSocketAction(page)
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const viewerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const other = await otherContext.newPage()
  const viewer = await viewerContext.newPage()
  const log = watchDeliveryFrames(page)
  const echo = watchDeliveryFrames(other)
  try {
    await grantAdminToSelf(expect, page)
    await grantAdminToSelf(expect, other)
    await gotoPage(expect, page, STATUS_PATH)
    const tab = page.getByTestId('hilos-page-tab-hilos_status_works')
    await tab.focus()
    await tab.press('Enter')
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page, 'works')
    await expect(page.getByTestId('page-title')).toHaveAttribute(
      'aria-live',
      'polite',
    )
    await expectStatusWorksAccessibility(expect, page, log)
    await expectStatusWorkScheduleDialog(expect, page)
    await openStatusWorkSchedule(expect, page)
    const startsAt = Math.floor(Date.now() / 1000) * 1000 + 600_000
    const draft = {
      title: 'Accessible planned work',
      description: 'The live modal stays readable.',
      startsAt,
      endsAt: startsAt + 3_600_000,
      componentKeys: ['settings'],
    }
    statusWorks.reserve(draft)
    await draftStatusWork(expect, page, draft)
    const since = log.sent.length
    gate.hold(STATUS_WORK_SCHEDULE)
    await clickSubmit(expect, page.getByTestId('hilos-status-work-save'))
    await expect.poll(() => gate.captured.length).toBe(1)
    await expect(page.getByTestId('hilos-status-work-save')).toHaveAttribute(
      'aria-busy',
      'true',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeDisabled()
    await expect(page.getByTestId('hilos-status-work-close')).toBeDisabled()
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('hilos-status-work-form')).toBeVisible()
    await expect(page.getByTestId('modal-confirm')).toHaveCount(0)
    gate.release()
    await expect(page.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect
      .poll(() => trackedActionReplies(log, STATUS_WORK_SCHEDULE, since).length)
      .toBe(1)
    expect(
      trackedActionReplies(log, STATUS_WORK_SCHEDULE, since)[0].frame.type,
    ).toBe('action_success')
    await expect
      .poll(() =>
        statusWorkRows(log).find(
          (work) => work.title === 'Accessible planned work',
        ),
      )
      .toBeDefined()
    const work = statusWorkRows(log).find(
      (work) => work.title === 'Accessible planned work',
    )!
    await gotoPage(expect, other, STATUS_WORKS_PATH)
    await dismissToasts(page)
    const opener = shownByTestId(page, `hilos-status-work-edit-${work.id}`)
    await opener.focus()
    await opener.press('Enter')
    await expect(page.getByTestId('modal')).toHaveAccessibleName(
      'Change scheduled maintenance',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeFocused()
    await typeInto(page.getByTestId('hilos-status-work-title'), 'Keep my draft')
    await shownByTestId(other, `hilos-status-work-edit-${work.id}`).click()
    await typeInto(
      other.getByTestId('hilos-status-work-title'),
      'The incoming announcement',
    )
    await submitStatusWork(expect, other, echo, STATUS_WORK_CHANGE)
    await expect
      .poll(() => latestStatusWork(log, work.publicId)?.version)
      .toBe(2)
    const notice = page.getByTestId('hilos-status-work-notice-slot')
    await expect(notice).toHaveAttribute('role', 'status')
    await expect(notice).toHaveAttribute('aria-live', 'polite')
    await expect(page.getByTestId('hilos-status-work-notice')).toContainText(
      'Changed elsewhere',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
      'Keep my draft',
    )
    await expect(page.getByTestId('hilos-status-work-save')).toBeDisabled()
    const mine = page.getByTestId('conflict-accept-mine')
    const theirs = page.getByTestId('conflict-accept-theirs')
    await expect(mine).toHaveAccessibleName('Keep mine')
    await expect(theirs).toHaveAccessibleName('Take theirs')
    await mine.focus()
    await mine.press('Tab')
    await expect(theirs).toBeFocused()
    await theirs.press('Enter')
    await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
      'The incoming announcement',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect(opener).toBeFocused()
    const cancel = shownByTestId(page, `hilos-status-work-cancel-${work.id}`)
    await cancel.focus()
    await cancel.press('Enter')
    const modal = page.getByTestId('modal')
    await expect(modal).toHaveRole('dialog')
    await expect(modal).toHaveAttribute('aria-modal', 'true')
    await expect(modal).toHaveAccessibleName('Cancel scheduled maintenance')
    await expect(modal).toBeFocused()
    const confirm = page.getByTestId('hilos-status-work-cancel-confirm')
    await confirm.focus()
    await confirm.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await expect(confirm).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(modal).toBeHidden()
    await expect(cancel).toBeFocused()
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_WORKS_PATH)
    await expectStatusHead(expect, viewer, 'works')
    await openStatusWorkSchedule(expect, viewer)
    await expect(viewer.getByTestId('hilos-status-work-save')).toBeDisabled()
    await expect(
      viewer.getByTestId('hilos-status-work-save'),
    ).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    await expect(viewer.getByTestId('hilos-status-work-notice')).toContainText(
      'You can look around, but not change anything.',
    )
    await viewer.keyboard.press('Escape')
    await expect(viewer.getByTestId('hilos-status-work-form')).toBeHidden()
  } finally {
    gate.release()
    await test.info().attach('status-works-accessibility-real-wire', {
      body: JSON.stringify({
        writer: log,
        focused: echo,
        held: gate.captured,
      }),
      contentType: 'application/json',
    })
    log.stop()
    echo.stop()
    await otherContext.close()
    await viewerContext.close()
    await setAdminViewMode(false)
  }
})
