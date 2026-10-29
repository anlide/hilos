import { test, expect } from '@playwright/test'

import {
  leaveSecondAdmin,
  shownByTestId,
  deliveryObject,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  createTestUser,
  grantAdminToSelf,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// spec-owner: demo — it covers Chat's project setting alongside shared admin views.

// HIL-1253: the browser knows the node's admin view mode from the session
// response, so the shell draws the admin gear for a viewer who may look — a
// guest without an account included — and the admin routes do not refuse that
// viewer on the client. A grant turns the gear full and a revoke takes it back
// to the view, live, with no 403 drawn on the way: the server answers the page
// again, the view or the full one, and the tab only waits for it.
//
// HIL-1260: on every admin screen the viewer reads one strip saying the screen
// may be looked at and not changed, and what the server keeps from them reads
// as one mark, "Hidden" — a guest's look at the people who accepted the terms
// and at the people list names nobody.
//
// The lever is node-wide; every test here leaves it off, failed or not.

/** The words of the view-mode strip, as the shell draws them. */
const VIEW_MODE_STRIP_TEXT =
  'View mode · You can look around, but not change anything.'

test.afterEach(() => setAdminViewMode(false))

test('a guest without an account sees the admin gear and opens the admin section in the view mode', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  await setAdminViewMode(true)
  // The flip is not sent to the open tab; the next handshake carries it.
  await gotoPage(expect, page, '/')
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toBeVisible()
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expect(gear).toHaveAttribute(
    'aria-label',
    'Hilos dashboard — View mode',
  )
  await expect(gear.locator('.bi-eye')).toBeVisible()

  await gear.click()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
})

test('a guest reads the view-mode strip on the admin screens and the personal data as hidden', async ({
  browser,
  page,
}) => {
  // An account of this test's own: the acceptances and the users then hold at
  // least one person whose name is kept from the guest.
  const someone = await browser.newContext()
  await createTestUser(expect, await someone.newPage())
  await someone.close()

  await setAdminViewMode(true)
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'view',
  )
  await expect(page.getByTestId('view-mode-banner')).toHaveCount(0)

  const screens = [
    { path: '/hilos', hidden: null },
    { path: '/hilos/legal/acceptances', hidden: 'legal-acceptances-table' },
    { path: '/hilos/users', hidden: 'hilos-viewport-table' },
  ]
  for (const screen of screens) {
    await gotoPage(expect, page, screen.path, PAGE_READY)
    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    if (screen.hidden !== null) {
      await expect(
        shownByTestId(page.getByTestId(screen.hidden), 'hilos-hidden').first(),
      ).toHaveText('Hidden')
    }
  }
})

test('a viewer sees watchdog recipients hidden and thresholds read-only', async ({
  page,
}) => {
  const deliveries = watchDeliveryFrames(page)
  const isHiddenMark = (value: unknown): boolean => {
    const mark = deliveryObject(value)
    return Object.keys(mark).length === 1 && mark._hidden === true
  }

  try {
    await setAdminViewMode(true)
    await gotoPage(expect, page, '/hilos/maintenance', PAGE_READY)

    const maintenanceFrames = () =>
      deliveries.packets
        .flatMap((packet) => packet.frames)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).type === 'subscription_page_hilos_maintenance',
        )
    const publishedFrames = () =>
      maintenanceFrames().filter(({ wire }) => {
        const state = deliveryObject(deliveryObject(wire).data)
        return (
          typeof state.watchdogQuiesceTimeoutSeconds === 'number' &&
          Number.isInteger(state.watchdogQuiesceTimeoutSeconds) &&
          typeof state.watchdogSilenceTimeoutSeconds === 'number' &&
          Number.isInteger(state.watchdogSilenceTimeoutSeconds) &&
          typeof state.watchdogAlertIntervalSeconds === 'number' &&
          Number.isInteger(state.watchdogAlertIntervalSeconds) &&
          isHiddenMark(state.watchdogAlertEmails) &&
          typeof state.watchdogManualRestartIsNormal === 'boolean'
        )
      })

    await expect
      .poll(() => publishedFrames().length, {
        message:
          'the viewer receives published watchdog thresholds and the visible restart policy',
      })
      .toBeGreaterThan(0)

    const stateFrames = maintenanceFrames()
    expect(stateFrames.length).toBeGreaterThan(0)
    for (const frame of stateFrames) {
      expect(frame.failure).toBeUndefined()
      expect(frame.raw).toEqual(expect.any(String))
      expect(
        deliveryObject(deliveryObject(frame.wire).data).watchdogAlertEmails,
      ).toEqual({ _hidden: true })
    }

    const latestFrame = publishedFrames().at(-1)!
    const state = deliveryObject(deliveryObject(latestFrame.wire).data)
    const quiesceSeconds = Number(state.watchdogQuiesceTimeoutSeconds)
    const silentSeconds = Number(state.watchdogSilenceTimeoutSeconds)
    const repeatSeconds = Number(state.watchdogAlertIntervalSeconds)
    const restartIsNormal = state.watchdogManualRestartIsNormal === true
    const secondsText = (value: number): string =>
      `${value} ${value === 1 ? 'second' : 'seconds'}`

    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    const thresholds = page.getByTestId('hilos-maintenance-watchdog-thresholds')
    await expect(thresholds).toBeVisible()
    await expect(thresholds.getByRole('heading', { level: 2 })).toHaveText(
      'Watchdog thresholds',
    )
    await expect(thresholds.locator('dl dt')).toHaveText([
      'Wait for nodes to quiesce',
      'Consider silent after',
      'Repeat alert',
      'Send mail to',
      'Restart under manual maintenance',
    ])
    await expect(thresholds.locator('dl dd')).toHaveText([
      secondsText(quiesceSeconds),
      secondsText(silentSeconds),
      secondsText(repeatSeconds),
      'Hidden',
      restartIsNormal ? 'No mail — a log entry' : 'Send an alert',
    ])

    const recipients = thresholds.locator('dl dd').nth(3)
    await expect(thresholds.getByTestId('hilos-hidden')).toHaveCount(1)
    await expect(recipients.getByTestId('hilos-hidden')).toHaveText('Hidden')
    await expect(recipients).toHaveText('Hidden')
    await expect(
      thresholds.locator('input, select, textarea, button, a'),
    ).toHaveCount(0)
  } finally {
    deliveries.stop()
  }
})

test('a viewer sees open framework settings while a project key stays hidden', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  const search = page.getByTestId('hilos-table-search')
  await typeInto(search, 'legal.consent_form')
  const openRow = shownByTestId(page, 'hilos-table-row-legal.consent_form')
  await expect(openRow).toBeVisible()
  await expect(openRow.getByTestId('setting-value')).toContainText('checkbox')
  await expect(openRow.getByTestId('hilos-hidden')).toHaveCount(0)

  await typeInto(search, 'default_bot_provider')
  const closedRow = shownByTestId(page, 'hilos-table-row-default_bot_provider')
  await expect(closedRow).toBeVisible()
  await expect(closedRow.getByTestId('hilos-hidden').first()).toHaveText(
    'Hidden',
  )
  await typeInto(search, 'local')
  await expect(closedRow).toHaveCount(0)

  await gotoPage(expect, page, '/hilos/legal/settings', PAGE_READY)
  await expect(
    shownByTestId(page, 'legal-setting-value-legal.consent_form'),
  ).toContainText('Checkbox')

  const { userId } = await createTestUser(expect, page)
  await setAdmin(userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  const adminSearch = page.getByTestId('hilos-table-search')
  await typeInto(adminSearch, 'default_bot_provider')
  const adminRow = shownByTestId(page, 'hilos-table-row-default_bot_provider')
  await expect(adminRow).toBeVisible()
  await expect(adminRow.getByTestId('setting-value')).toContainText('local')
  await expect(adminRow.getByTestId('hilos-hidden')).toHaveCount(0)
  await typeInto(adminSearch, 'legal.consent_form')
  await expect(
    shownByTestId(page, 'hilos-table-row-legal.consent_form').getByTestId(
      'setting-value',
    ),
  ).toContainText('checkbox')
})

test('a signed-in non-admin views the people, is granted the full section and taken back to the view live', async ({
  browser,
  page,
}) => {
  await setAdminViewMode(true)
  // Revocation requires another administrator, even when this test runs alone.
  // Made before the people open, that one's row is in the table from its first
  // answer rather than arriving live in the middle of the scenario.
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  await leaveSecondAdmin(browser, (page) => grantAdminToSelf(expect, page), {
    baseURL,
    ignoreHTTPSErrors,
  })
  const { userId } = await createTestUser(expect, page)
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toHaveAttribute('data-access', 'view')

  await gotoPage(expect, page, '/hilos/users', PAGE_READY)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const strip = page.getByTestId('view-mode-banner')
  await expect(strip).toBeVisible()

  // The grant re-sends the session response to this tab: the gear turns full
  // and the page is answered again, now the full one; the strip leaves.
  await setAdmin(userId, true)
  await expect(gear).toHaveAttribute('data-access', 'full')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(strip).toHaveCount(0)

  // The revoke with the mode on answers the view rather than a 403, and the
  // tab waits for it instead of drawing the refusal ahead of the server.
  await setAdmin(userId, false)
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(strip).toBeVisible()
})
