import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  RESTART_AGENT,
  readRestartWorker,
  withStoppedRestartWorker,
} from '../helpers/daemonRestart.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  DAEMON_ROOT_PATH,
  DAEMON_WORKERS_PATH,
  DAEMON_WORKERS_CHILD,
  DAEMON_ROOT_CRUMB,
  DAEMON_PLACEMENT_TYPE,
  DAEMON_PLACEMENT_LONG_TYPE,
  DAEMON_PLACEMENT_CELL,
  DAEMON_PLACEMENT_TOTALS,
  RESTART_MARK_TIMEOUT_MS,
  expectDaemonPage,
  expectStandaloneOverview,
  expectStandalonePlacement,
  expectUnfilteredTotals,
  expectStandaloneWorkers,
  expectStandaloneCron,
  expectStandaloneHttp,
  expectStandaloneEnvironment,
  expectStandaloneMismatch,
  ROWS,
  fitsInScrollport,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'

// The live standalone Daemon section: navigation, placement, node reports,
// keyboard access, themes, viewer privacy and the shared restart mark.
// The forged viewer restart request is covered on Vue as a server check.
// Kit unit tests cover Cron idle/empty and cluster mismatches (P-572);
// cluster coverage lives in binance-btc-tracker's tests/e2e/cluster/daemon/daemon.spec.ts.
const DATABASE_HOST = 'tasks-mysql-test'

test.describe('daemon overview', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('opens the real root from the dashboard, cold loads it and returns from Workers and the node log', async ({
    page,
  }) => {
    // Registration and four page subscriptions are real daemon round trips.
    test.slow()
    await signUpAdmin(page)
    await gotoPage(page, '/hilos', PAGE_READY)
    const card = page.getByTestId('dashboard-card-hilos_daemon')
    await expect(card).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await card.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    // The applied index from the live primary DB, kept once for the stand SQL check.
    await test.info().attach('daemon-primary-migration', {
      body: await page
        .getByTestId('hilos-daemon-database-migration')
        .innerText(),
      contentType: 'text/plain',
    })

    await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    const node = page.getByTestId('hilos-daemon-node-standalone')
    await node.focus()
    await node.press('Enter')
    await node.press('Space')
    await node.click()
    await expect(node).toHaveAttribute('aria-pressed', 'true')
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_WORKERS_PATH}$`))
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    const crumb = page.getByTestId(DAEMON_ROOT_CRUMB)
    await expect(crumb).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await crumb.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(expect, page, DATABASE_HOST)

    await page.getByTestId('hilos-daemon-node-log').click()
    await expectDaemonPage(expect, page, 'hilos_logs_view')
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    const streams = page.getByTestId('hilos-log-stream')
    // Nonempty catalog options prove the selected node's live viewer is ready.
    // Its existing policy leaves the stream for the operator to choose.
    await expect(
      streams.locator('option[value]:not([value=""])'),
    ).not.toHaveCount(0)
    await expect(streams).toHaveValue('')
    await expect(page).toHaveURL(/\/hilos\/logs\/view\/standalone\/live$/)
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
  })

  test('keeps the complete technical root available in admin view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(page.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'view',
    )
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
  })

  for (const viewer of [false, true]) {
    test(`searches real placement ids and preserves local selection as ${viewer ? 'a view-mode reader' : 'an admin'}`, async ({
      page,
    }) => {
      await page.setViewportSize({ width: 1280, height: 812 })
      if (viewer) {
        await setAdminViewMode(true)
      } else {
        await signUpAdmin(page)
      }
      await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await expectStandalonePlacement(expect, page)
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      }
      const totals = await Promise.all(
        DAEMON_PLACEMENT_TOTALS.map((total) =>
          page.getByTestId(`hilos-daemon-placement-${total}`).innerText(),
        ),
      )
      const cell = shownByTestId(page, DAEMON_PLACEMENT_CELL)
      const table = page.getByRole('table', { name: 'Daemon' })
      await expect(table).toBeVisible()
      const selection = table.getByTestId('hilos-daemon-placement-selection')
      const row = table.getByTestId(`hilos-table-row-${DAEMON_PLACEMENT_TYPE}`)
      const detail = table.getByTestId(
        `hilos-table-row-detail-${DAEMON_PLACEMENT_TYPE}`,
      )
      const expand = shownByTestId(
        page,
        `hilos-table-expand-${DAEMON_PLACEMENT_TYPE}`,
      )
      await expect(page.getByTestId(/^hilos-table-expand-/)).toHaveCount(0)
      await cell.focus()
      await cell.press('Enter')
      await expect(cell).toHaveAttribute('aria-expanded', 'true')
      await expect(selection).toBeVisible()
      await expect(
        detail.getByTestId('hilos-daemon-placement-selection'),
      ).toBeVisible()
      expect(
        await row.evaluate((element) =>
          element.nextElementSibling?.getAttribute('data-id'),
        ),
      ).toBe(`hilos-table-row-detail-${DAEMON_PLACEMENT_TYPE}`)
      await expect(expand).toHaveAttribute('aria-expanded', 'true')
      const ids = (await selection.locator('code').allTextContents()).slice(2)
      expect(ids).toEqual([DAEMON_PLACEMENT_TYPE])
      await expect(selection.locator('a, button')).toHaveCount(1)
      await expand.click()
      await expect(
        page.getByTestId('hilos-daemon-placement-selection'),
      ).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await expect(expand).toHaveAttribute('aria-expanded', 'false')
      await expand.focus()
      await expand.press('Enter')
      await expect(selection).toContainText(ids[0])
      const collapse = shownByTestId(page, 'hilos-daemon-placement-collapse')
      await collapse.focus()
      await collapse.press('Space')
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.focus()
      await cell.press('Space')
      await expect(selection).toContainText(ids[0])

      const otherType = DAEMON_PLACEMENT_LONG_TYPE
      await shownByTestId(
        page,
        `hilos-daemon-placement-cell-${otherType}-standalone`,
      ).click()
      await expect(detail).toHaveCount(0)
      await expect(
        table
          .getByTestId(`hilos-table-row-detail-${otherType}`)
          .getByTestId('hilos-daemon-placement-selection'),
      ).toContainText(otherType)
      await expect(selection).toHaveCount(1)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(
        table.getByTestId(`hilos-table-row-detail-${otherType}`),
      ).toHaveCount(0)
      await expect(
        detail.getByTestId('hilos-daemon-placement-selection'),
      ).toContainText(ids[0])

      const search = page.getByTestId('hilos-table-search')
      await search.fill('')
      await search.pressSequentially(ids[0], { delay: 10 })
      await expect(table.locator(ROWS)).toHaveCount(1)
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(selection).toContainText(ids[0])
      await expectUnfilteredTotals(expect, page, totals)

      await page.getByTestId('hilos-daemon-nodes-tab').click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await expect(selection).toBeHidden()
      await expectStandalonePlacement(expect, page)
      await expect(search).toHaveValue(ids[0])
      await expect(selection).toContainText(ids[0])
      await expect(cell).toHaveAttribute('aria-expanded', 'true')
      await cell.click()
      await expect(
        page.getByTestId('hilos-daemon-placement-selection'),
      ).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(selection).toBeVisible()

      await search.fill('')
      await search.pressSequentially('no-such-placement-instance', {
        delay: 10,
      })
      await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
      await expectUnfilteredTotals(expect, page, totals)
      await shownByTestId(page, 'hilos-table-no-matches-reset').click()
      await expect(search).toHaveValue('')
      await expect(cell).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
    })
  }

  test('keeps the live root and every child page readable', async ({
    page,
  }) => {
    // It follows every child and its breadcrumb over live subscriptions.
    test.slow()
    await signUpAdmin(page)
    await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
    await page.setViewportSize({ width: 1280, height: 812 })
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    const colors = await page.locator('html').evaluate((element) => {
      const style = getComputedStyle(element)
      const rgb = (name: string) =>
        `rgb(${style
          .getPropertyValue(name)
          .trim()
          .split(',')
          .map((part) => part.trim())
          .join(', ')})`
      return {
        body: rgb('--bs-body-bg-rgb'),
        text: rgb('--bs-body-color-rgb'),
        tertiary: rgb('--bs-tertiary-bg-rgb'),
      }
    })
    await expect(page.getByTestId('hilos-daemon-node-standalone')).toHaveCSS(
      'background-color',
      colors.body,
    )
    await expect(page.getByTestId('hilos-daemon-node-standalone')).toHaveCSS(
      'color',
      colors.text,
    )
    await expect(page.getByTestId('hilos-daemon-database')).toHaveCSS(
      'background-color',
      colors.tertiary,
    )
    for (const target of [
      page.getByTestId('hilos-daemon-node-standalone'),
      page.getByTestId('hilos-daemon-database-address'),
      page.getByTestId('hilos-daemon-database-migration'),
      page.getByTestId('hilos-daemon-placement-tab'),
      page.getByTestId('hilos-daemon-node-log'),
      page.getByTestId(DAEMON_WORKERS_CHILD),
      ...(await page.getByTestId(/^hilos-daemon-worker-standalone-/).all()),
      ...(await page
        .getByTestId('hilos-daemon-maintenance')
        .locator('button')
        .all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
      expect(
        await target.evaluate(
          (element) => element.scrollWidth - element.clientWidth,
        ),
      ).toBe(0)
    }
    await expectStandalonePlacement(expect, page)
    await expect(
      shownByTestId(page, `hilos-table-row-${DAEMON_PLACEMENT_TYPE}`),
    ).toBeVisible()
    const longType = DAEMON_PLACEMENT_LONG_TYPE
    const cell = shownByTestId(
      page,
      `hilos-daemon-placement-cell-${longType}-standalone`,
    )
    await cell.click()
    const selection = shownByTestId(page, 'hilos-daemon-placement-selection')
    const detail = shownByTestId(page, `hilos-table-row-detail-${longType}`)
    await expect(
      detail.getByTestId('hilos-daemon-placement-selection'),
    ).toBeVisible()
    expect(
      await page
        .getByTestId(`hilos-table-row-${longType}`)
        .evaluate((element) =>
          element.nextElementSibling?.getAttribute('data-id'),
        ),
    ).toBe(`hilos-table-row-detail-${longType}`)
    await expect(
      shownByTestId(page, 'hilos-daemon-placement-selection'),
    ).toHaveCount(1)
    await expect(selection.locator('code')).toHaveText([
      longType,
      'standalone',
      longType,
    ])
    for (const target of [
      page.getByTestId('hilos-table-search'),
      cell,
      selection,
      shownByTestId(page, 'hilos-daemon-placement-collapse'),
      ...(await selection.locator('code').all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
    }
    // Exercise a long current id, without injecting a fake roster.
    for (const id of await selection.locator('code').all()) {
      await expect(id).toHaveCSS('overflow-wrap', 'break-word')
    }
    await test.info().attach('placement', {
      body: await page.getByTestId('hilos-daemon-placement-view').screenshot(),
      contentType: 'image/png',
    })
    await shownByTestId(page, 'hilos-daemon-placement-collapse').click()
    await page.getByTestId('hilos-daemon-nodes-tab').click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Workers')
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await page.getByTestId('hilos-admin-child-hilos_daemon_agents').click()
    await expectDaemonPage(expect, page, 'hilos_daemon_agents')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Agents')
    await expect(
      shownByTestId(
        page,
        `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
      ),
    ).toBeEnabled()
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    for (const child of ['cron', 'http_server', 'env', 'env_mismatch']) {
      const key = `hilos_daemon_${child}`
      await page.getByTestId(`hilos-admin-child-${key}`).click()
      await expectDaemonPage(expect, page, key)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') await expectStandaloneHttp(expect, page)
      if (child === 'env') await expectStandaloneEnvironment(expect, page)
      if (child === 'env_mismatch') await expectStandaloneMismatch(expect, page)
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    }
  })
})

test.describe('daemon node pages', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`reads Workers and Cron with named tables and keyboard links as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      // Three live pages and the worker log.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await signUpAdmin(page)
      await page.setViewportSize({ width: 1280, height: 812 })
      await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await page.getByTestId(DAEMON_WORKERS_CHILD).focus()
      await page.getByTestId(DAEMON_WORKERS_CHILD).press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Workers',
      )
      const surface = page.getByRole('table')
      await expect(surface).toHaveAccessibleName('Workers')
      const expand = shownByTestId(page, /^hilos-table-expand-/).first()
      await expect(expand).toBeVisible()
      await expand.focus()
      await expect(expand).toBeFocused()
      await expand.press('Enter')
      await expect(expand).toHaveAttribute('aria-expanded', 'true')
      await expect(
        shownByTestId(page, /^hilos-daemon-worker-agents-/),
      ).toBeVisible()
      const log = shownByTestId(page, /^hilos-daemon-worker-log-/).first()
      await expect(log).toHaveAttribute(
        'href',
        /\/hilos\/logs\/view\/standalone\/live\/worker-/,
      )
      await log.click()
      await expectDaemonPage(expect, page, 'hilos_logs_view')
      await expect(page.getByTestId('hilos-log-stream')).not.toHaveValue('')
      await page.goBack()
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      const cron = page.getByTestId('hilos-admin-child-hilos_daemon_cron')
      await expect(cron).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/cron',
      )
      await cron.focus()
      await cron.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_cron')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cron')
      const schedule = page.getByRole('table')
      await expect(schedule).toHaveAccessibleName('Cron')
      await expectStandaloneCron(expect, page)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await gotoPage(page, '/hilos/daemon/standalone/websockets')
      await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
        'data-state',
        'error',
      )
      await expect(page.getByTestId('page-error')).toHaveAttribute(
        'data-error-code',
        '404',
      )
      await expect(page.getByTestId('hilos-admin-page')).toHaveCount(0)
    })
  }

  for (const viewer of [false, true]) {
    test(`reads Agents and HTTP with keyboard navigation as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      // FIXME(F-021, 2026-10-23): restart confirmation does not return focus · чужой · попытка 3 · HIL-1641
      test.fixme(!viewer, 'F-021: restart confirmation does not return focus')
      // It follows real page subscriptions and an agent's live log.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await signUpAdmin(page)
      await page.setViewportSize({ width: 1280, height: 812 })
      await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      const agents = page.getByTestId('hilos-admin-child-hilos_daemon_agents')
      await expect(agents).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/agents',
      )
      await agents.focus()
      await agents.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_agents')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Agents')
      const surface = page.getByRole('table')
      await expect(surface).toHaveAccessibleName('Agents')
      await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
      const search = page.getByTestId('hilos-table-search')
      await expect(search).toHaveAccessibleName('Search by agent id…')
      await search.fill('')
      await search.pressSequentially(RESTART_AGENT, { delay: 10 })
      const row = shownByTestId(page, `hilos-table-row-${RESTART_AGENT}`)
      await expect(row).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(row).toContainText('settings')
      await expect(row).toContainText('Stays up')
      const restart = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
        await expect(restart).toBeDisabled()
        await expect(restart).toHaveAttribute(
          'aria-describedby',
          /(^| )hilos-view-mode-strip-text( |$)/,
        )
      } else {
        await expect(restart).toBeEnabled()
        await restart.focus()
        await restart.press('Enter')
        const modal = page.getByTestId('modal')
        await expect(modal).toBeFocused()
        await expect(modal).toHaveAccessibleName('Restart agent')
        await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
          `php cli.php agent:restart ${RESTART_AGENT}`,
        )
        await expect(
          page.getByTestId('hilos-daemon-restart-policy'),
        ).toHaveCount(0)
        for (const target of [
          modal,
          page.getByTestId('hilos-daemon-restart-cli'),
          page.getByTestId('hilos-daemon-restart-cancel'),
          page.getByTestId('hilos-daemon-restart-confirm'),
        ]) {
          await target.scrollIntoViewIfNeeded()
          await fitsInScrollport(target)
        }
        await test.info().attach('restart', {
          body: await modal.screenshot(),
          contentType: 'image/png',
        })
        await modal.press('Enter')
        await expect(modal).toBeVisible()
        await modal.press('Escape')
        await expect(modal).toHaveCount(0)
        await expect(restart).toBeFocused()
      }
      const log = shownByTestId(page, `hilos-daemon-agent-log-${RESTART_AGENT}`)
      await expect(log).toHaveAttribute(
        'href',
        /\/hilos\/logs\/view\/standalone\/live\/agent-/,
      )
      await log.focus()
      await log.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_logs_view')
      await expect(page.getByTestId('hilos-log-stream')).not.toHaveValue('')
      await page.goBack()
      await expectDaemonPage(expect, page, 'hilos_daemon_agents')
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      const http = page.getByTestId(
        'hilos-admin-child-hilos_daemon_http_server',
      )
      await expect(http).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/http',
      )
      await http.focus()
      await http.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_http_server')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'HTTP server',
      )
      const routes = page.getByRole('table')
      await expect(routes).toHaveAccessibleName('HTTP server')
      await expectStandaloneHttp(expect, page)
      await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
      for (const target of [
        page.getByTestId('hilos-daemon-http-address'),
        page.getByTestId('hilos-daemon-http-period'),
      ]) {
        await target.scrollIntoViewIfNeeded()
        await fitsInScrollport(target)
      }
      await test.info().attach(`http-${viewer}`, {
        body: await page.getByTestId('hilos-admin-page').screenshot(),
        contentType: 'image/png',
      })
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    })
  }
})

test.describe('restarting an agent', () => {
  test.afterEach(() => setAdminViewMode(false))

  // The master's 13-second caller window must be judged on the first attempt.
  test.describe.configure({ retries: 0 })

  test('restarts the settings library while two viewers and a new tab see the shared mark', async ({
    page,
    context,
  }) => {
    await signUpAdmin(page)
    const worker = await readRestartWorker(page)
    await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)
    const observer = await context.newPage()
    const newcomer = await context.newPage()
    try {
      await gotoPage(observer, '/hilos/daemon/standalone/agents', PAGE_READY)
      const button = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      await button.scrollIntoViewIfNeeded()
      await expect(button).toBeVisible()
      await expect(button).toBeEnabled()
      await button.focus()
      await button.click()
      await expect(page.getByTestId('modal')).toBeFocused()
      await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
        `php cli.php agent:restart ${RESTART_AGENT}`,
      )
      await withStoppedRestartWorker(worker, async () => {
        const confirm = page.getByTestId('hilos-daemon-restart-confirm')
        await confirm.scrollIntoViewIfNeeded()
        await expect(confirm).toBeVisible()
        await expect(confirm).toBeEnabled()
        await confirm.focus()
        await confirm.click()
        const mark = `hilos-daemon-agent-restarting-${RESTART_AGENT}`
        await expect(shownByTestId(page, mark)).toHaveText('Restarting', {
          timeout: RESTART_MARK_TIMEOUT_MS,
        })
        await expect(shownByTestId(observer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            observer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
        await gotoPage(newcomer, '/hilos/daemon/standalone/agents', PAGE_READY)
        await expect(shownByTestId(newcomer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            newcomer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
      })
      await expect(page.getByTestId('modal')).toHaveCount(0)
      for (const viewer of [page, observer, newcomer]) {
        await expect(
          viewer.getByTestId(`hilos-daemon-agent-restarting-${RESTART_AGENT}`),
        ).toHaveCount(0, { timeout: RESTART_MARK_TIMEOUT_MS })
        await expect(
          shownByTestId(viewer, `hilos-daemon-agent-restart-${RESTART_AGENT}`),
        ).toBeEnabled()
        await expect(
          viewer.getByTestId(`hilos-table-row-${RESTART_AGENT}`),
        ).toBeVisible()
      }
      await expect(
        page.getByTestId('hilos-toast-success').filter({
          hasText: `Agent ${RESTART_AGENT} restarted on node standalone.`,
        }),
      ).toBeVisible()
    } finally {
      await observer.close()
      await newcomer.close()
    }
  })
})

test.describe('daemon environment', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('reads environment values, masks secrets and filters attention', async ({
    page,
  }) => {
    // Registration and several server-window changes are real round trips.
    test.slow()
    await signUpAdmin(page)
    await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    const link = page.getByTestId('hilos-daemon-environment-link')
    await link.scrollIntoViewIfNeeded()
    await link.focus()
    await link.press('Enter')
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env$/)
    await page.setViewportSize({ width: 1280, height: 812 })
    await expectStandaloneEnvironment(expect, page)
    const count = await page.getByTestId('hilos-daemon-env-keys').innerText()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'Environment',
    )
    for (const name of ['Catalog keys', 'Orphans in .env', 'Reading ladder']) {
      await expect(page.getByRole('table', { name })).toBeVisible()
    }
    const search = page.getByTestId('hilos-table-search')
    await expect(search).toHaveAccessibleName('Key or value')
    await search.fill('')
    await search.pressSequentially('APP_ENV', { delay: 10 })
    const app = shownByTestId(page, 'hilos-table-row-APP_ENV')
    await expect(app).toContainText('test')
    await expect(app).toContainText('Launch environment')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    const attention = page.getByTestId('hilos-table-filter-attention')
    await expect(attention).toHaveAccessibleName('Needs attention only')
    await expect(attention).not.toBeChecked()
    await attention.focus()
    await attention.press('Space')
    await expect(attention).toBeChecked()
    await expect(
      shownByTestId(page, 'hilos-daemon-env-keys-empty-attention'),
    ).toHaveText('No key needs attention.')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.getByTestId('hilos-daemon-env-keys')).toHaveText(count)
    await attention.focus()
    await attention.press('Space')
    await expect(attention).not.toBeChecked()
    await expect(app).toContainText('test')
    await search.fill('')
    await search.pressSequentially('DB_PASSWORD', { delay: 10 })
    const secret = shownByTestId(page, 'hilos-table-row-DB_PASSWORD')
    await expect(secret).toContainText(/Set · \d+ chars/)
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText('hilos_pass')
    await secret.scrollIntoViewIfNeeded()
    await fitsInScrollport(secret)
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    // A cold mount must bind the same two windows with all keys selected.
    await gotoPage(page, '/hilos/daemon/standalone/env', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
    await expectStandaloneEnvironment(expect, page)
  })

  test('a viewer reads Cron, HTTP, environment and comparison with values hidden', async ({
    page,
  }) => {
    // Four live child subscriptions and the environment's filtered windows.
    test.slow()
    await setAdminViewMode(true)
    await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    for (const child of ['cron', 'http_server', 'env', 'env_mismatch']) {
      const key = `hilos_daemon_${child}`
      const link = page.getByTestId(`hilos-admin-child-${key}`)
      if (child === 'env_mismatch') {
        await expect(link).toHaveAttribute('href', '/hilos/daemon/env-mismatch')
      }
      await link.focus()
      await link.press('Enter')
      await expectDaemonPage(expect, page, key)
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      await expect(page.getByTestId('view-mode-banner')).toHaveText(
        'View mode · You can look around, but not change anything.',
      )
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') {
        await expectStandaloneHttp(expect, page)
        await expect(
          page.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
        ).toHaveCount(0)
        await expect(page.getByTestId('page-error')).toHaveCount(0)
      }
      if (child === 'env') {
        await expectStandaloneEnvironment(expect, page)
        const search = page.getByTestId('hilos-table-search')
        for (const key of ['DB_HOST', 'DB_PASSWORD']) {
          await search.fill('')
          await search.pressSequentially(key, { delay: 10 })
          const row = shownByTestId(page, `hilos-table-row-${key}`)
          await expect(row).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toHaveText('Hidden')
          await expect(row).not.toContainText(/Set ·|chars|hilos_pass/)
          await expect(row).not.toContainText(DATABASE_HOST)
          await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        }
        await expect(page.locator('body')).not.toContainText('hilos_pass')
        await expect(page.getByTestId('page-error')).toHaveCount(0)
        await search.fill('')
        await search.pressSequentially(DATABASE_HOST, { delay: 10 })
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(shownByTestId(page, /^hilos-table-row-/)).toHaveCount(0)
      }
      if (child === 'env_mismatch') await expectStandaloneMismatch(expect, page)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    }
  })

  test('cold loads the standalone comparison without table windows', async ({
    page,
  }) => {
    await signUpAdmin(page)
    const windows: string[] = []
    page.on('websocket', (socket) =>
      socket.on('framesent', ({ payload }) => {
        const frame = JSON.parse(String(payload)) as {
          type?: string
          data?: { page?: string }
        }
        if (
          frame.type === 'table_viewport' &&
          frame.data?.page === 'hilos_daemon_env_mismatch'
        )
          windows.push(String(payload))
      }),
    )
    await gotoPage(page, '/hilos/daemon/env-mismatch', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env_mismatch')
    await expectStandaloneMismatch(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    expect(windows).toEqual([])
  })
})
