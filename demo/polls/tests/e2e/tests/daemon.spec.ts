import { expect, test, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  RESTART_AGENT,
  readRestartWorker,
  withStoppedRestartWorker,
} from '../helpers/daemonRestart.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  ROWS,
  fitsInScrollport,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'

const ROOT_PATH = '/hilos/daemon'
const WORKERS_PATH = '/hilos/daemon/standalone/workers'
const WORKERS_CHILD = 'hilos-admin-child-hilos_daemon_workers'
const ROOT_CRUMB = 'hilos-breadcrumb-hilos_daemon'
const PLACEMENT_TYPE = 'hilos_daemon_node'
const PLACEMENT_CELL = `hilos-daemon-placement-cell-${PLACEMENT_TYPE}-standalone`
const PLACEMENT_TOTALS = [
  'instances',
  'types',
  'cluster-types',
  'unplaced',
] as const

// DaemonNodeAgent publishes changes every 5s, after the master's 0.5s roster
// coalescing. A mark can therefore arrive beyond Playwright's default 5s;
// this observation still fits inside the helper's independent 10s watchdog.
const RESTART_PICTURE_TIMEOUT_MS = 6_000

/** The outlet must have mounted the destination before its ready mark counts. */
async function expectDaemonPage(page: Page, key: string): Promise<void> {
  await expect(page.getByTestId('hilos-admin-page')).toHaveAttribute(
    'data-page',
    key,
  )
  await expectPageReady(page)
}

/** Wait for the real node report, which may arrive after the first page answer. */
async function expectStandaloneOverview(page: Page): Promise<void> {
  await expectDaemonPage(page, 'hilos_daemon')
  const node = page.getByTestId('hilos-daemon-node-standalone')
  const inspector = page.getByTestId('hilos-daemon-inspector')
  await expect(node).toBeVisible()
  await expect(node).toHaveAccessibleName('Select node standalone')
  await expect(node).toHaveAttribute('aria-pressed', 'true')
  await expect(node).toContainText('Single installation')
  await expect(inspector).toHaveAttribute('data-node-id', 'standalone')
  await expect(inspector).toContainText('Single installation')
  await expect(page.getByTestId('hilos-daemon-overview-waiting')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-consensus')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-inspector-state')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-daemon-link-/)).toHaveCount(0)
  await expect(inspector).not.toContainText(/Role:|Quorum:|Term:/)

  const chips = page.getByTestId(/^hilos-daemon-worker-standalone-/)
  await expect.poll(() => chips.count()).toBeGreaterThan(0)
  for (const chip of await chips.all()) {
    await expect(chip).toHaveAttribute('data-occupancy', /^(free|occupied)$/)
    await expect(chip).toHaveAttribute(
      'title',
      /^(Ordinary|Monopolistic) worker · (free|occupied) · \d+ agents$/,
    )
    await expect(chip).toHaveText(
      /^\s*[wm]\d+\s+·\s+(free|occupied)\s+·\s+\d+\s+agents\s*$/,
    )
  }
  await expect(page.getByTestId('hilos-daemon-cores')).toHaveText(
    /^\s*\d[\d,.]*\s*$/,
  )
  await expect(page.getByTestId('hilos-daemon-daemon-cores')).toHaveText(
    /^\s*\d[\d,.]*\s+cores\s*$/,
  )
  await expect(page.getByTestId('hilos-daemon-total-memory')).toHaveText(
    /^\s*\d[\d,.]*\s+(B|KB|MB|GB|TB)\s*$/,
  )
  await expect(page.getByTestId('hilos-daemon-workers-measured')).toHaveText(
    /^\s*\d[\d,]*\s+of\s+\d[\d,]*\s+worker processes measured\s*$/,
  )
  await expect(inspector).toContainText(
    /Container CPU quota|CPUs available to the process \(nproc\)/,
  )
  await expect(inspector).toContainText(
    /Container memory limit|Physical machine memory/,
  )
  await expect(
    page.getByTestId('hilos-daemon-inspector-connections'),
  ).toHaveText(/^\s*\d[\d,]*\s+connections\s*$/)
  await expect(page.getByTestId('hilos-daemon-monopolistic-count')).toHaveText(
    /^\s*\d[\d,]*\s*$/,
  )
  await expect(inspector).toContainText('Pool grows on demand')
  await expect(
    page.getByTestId('hilos-daemon-environment-attention'),
  ).toHaveText(/^\s*\d[\d,]*\s*$/)
  await expect(
    page.getByTestId('hilos-daemon-environment-differences'),
  ).toHaveText('—')
  const environment = page.getByTestId('hilos-daemon-environment-link')
  await expect(environment).toHaveText('On the environment page')
  await expect(environment).toHaveAttribute(
    'href',
    '/hilos/daemon/standalone/env',
  )
  const environmentTile = inspector.locator('dl').filter({ has: environment })
  await expect(environmentTile.locator('dt')).toHaveText('APP_ENV')
  await expect(environmentTile.locator('dd')).toHaveText([
    await environment.innerText(),
    'Application environment',
  ])

  const database = page.getByTestId('hilos-daemon-database')
  await expect(database).toContainText('Shared primary connection')
  await expect(page.getByTestId('hilos-daemon-database-address')).toHaveText(
    /^\s*mysql:\/\/polls-mysql-test:\d+\/[^\s?@]+\s*$/,
  )
  // This is the applied index returned by the live primary DB, never a count
  // of migration files. Keep the value as an artifact for the stand SQL check.
  const migration = page.getByTestId('hilos-daemon-database-migration')
  await expect(migration).toHaveText(/^\s*Migration:\s+[1-9]\d*\s*$/)
  await test.info().attach('daemon-primary-migration', {
    body: await migration.innerText(),
    contentType: 'text/plain',
  })
  await expect(database).not.toContainText(/password|username|credentials|@/i)
  await expect(page.getByTestId(WORKERS_CHILD)).toHaveAttribute(
    'href',
    WORKERS_PATH,
  )
  await expect(page.getByTestId('hilos-daemon-node-log')).toHaveAttribute(
    'href',
    '/hilos/logs/view/standalone/live',
  )
  await expect(page.getByTestId('hilos-daemon-placement-tab')).toBeEnabled()
  await expect(page.getByTestId('hilos-daemon-placement-tab')).toHaveAttribute(
    'aria-pressed',
    'false',
  )
  const maintenance = page
    .getByTestId('hilos-daemon-maintenance')
    .locator('button')
  await expect(maintenance).toHaveCount(3)
  for (const button of await maintenance.all()) {
    await expect(button).toBeDisabled()
    await expect(button).toHaveAttribute(
      'aria-describedby',
      'hilos-daemon-maintenance-unavailable',
    )
  }
}

/** Open the local diagram and wait for its real standalone roster window. */
async function expectStandalonePlacement(page: Page): Promise<void> {
  const tab = page.getByTestId('hilos-daemon-placement-tab')
  await tab.focus()
  await tab.press('Enter')
  await expect(tab).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByTestId('hilos-daemon-nodes-tab')).toHaveAttribute(
    'aria-pressed',
    'false',
  )
  await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))
  await expect(page.getByTestId('hilos-daemon-nodes-view')).toBeHidden()
  await expect(page.getByTestId('hilos-daemon-database')).toBeHidden()
  await expect(page.getByTestId('hilos-daemon-inspector')).toBeHidden()
  for (const total of PLACEMENT_TOTALS) {
    await expect(
      page.getByTestId(`hilos-daemon-placement-${total}`),
    ).toHaveText(total === 'unplaced' ? '0' : /^\s*[1-9][\d,]*\s*$/)
  }
  const view = page.getByTestId('hilos-daemon-placement-view')
  await expect(view).toContainText('All placed')
  await expect(view).toContainText('Declared in the project')
  await expect(page.getByTestId('hilos-daemon-placement-alerts')).toBeEmpty()
  const row = shownByTestId(page, `hilos-table-row-${PLACEMENT_TYPE}`)
  const card = shownByTestId(page, `hilos-table-card-${PLACEMENT_TYPE}`)
  await expect(row.or(card)).toContainText('Stays up')
  await expect(view).not.toContainText('★')
  await expect(shownByTestId(page, PLACEMENT_CELL)).toHaveAccessibleName(
    `${PLACEMENT_TYPE} on standalone: 1 instances`,
  )
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** Search keeps declared totals global; the live instance count may move. */
async function expectUnfilteredTotals(
  page: Page,
  totals: readonly string[],
): Promise<void> {
  for (const [index, total] of PLACEMENT_TOTALS.entries()) {
    const tile = page.getByTestId(`hilos-daemon-placement-${total}`)
    if (total === 'instances') {
      // The search below matches one instance, then none. Lazy agents may
      // start meanwhile, so assert the count still covers the whole daemon.
      await expect
        .poll(async () => Number((await tile.innerText()).replace(/,/g, '')))
        .toBeGreaterThan(1)
    } else {
      await expect(tile).toHaveText(totals[index])
    }
  }
}

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
    await expect(card).toHaveAttribute('href', ROOT_PATH)
    await card.click()
    await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))
    await expectStandaloneOverview(page)

    await gotoPage(page, ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(page)
    const node = page.getByTestId('hilos-daemon-node-standalone')
    await node.focus()
    await node.press('Enter')
    await node.press('Space')
    await node.click()
    await expect(node).toHaveAttribute('aria-pressed', 'true')
    await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))

    await page.getByTestId(WORKERS_CHILD).click()
    await expect(page).toHaveURL(new RegExp(`${WORKERS_PATH}$`))
    await expectDaemonPage(page, 'hilos_daemon_workers')
    await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
      'standalone',
    )
    await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
    await expect(page.getByTestId('hilos-daemon-node-diagram')).toHaveCount(0)
    await expect(
      shownByTestId(page, /^hilos-table-row-(regular|monopolistic):\d+$/),
    ).not.toHaveCount(0)
    const crumb = page.getByTestId(ROOT_CRUMB)
    await expect(crumb).toHaveAttribute('href', ROOT_PATH)
    await crumb.click()
    await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))
    await expectStandaloneOverview(page)

    await page.getByTestId('hilos-daemon-node-log').click()
    await expectDaemonPage(page, 'hilos_logs_view')
    await expect(page.getByTestId('hilos-log-source')).toBeVisible()
    const streams = page.getByTestId('hilos-log-stream')
    // Nonempty catalog options prove the selected node's live viewer is ready.
    // Its existing policy leaves the stream for the operator to choose.
    await expect(
      streams.locator('option[value]:not([value=""])'),
    ).not.toHaveCount(0)
    await expect(streams).toHaveValue('')
    await expect(page.getByTestId('hilos-log-empty-unchosen')).toBeVisible()
    await expect(page).toHaveURL(/\/hilos\/logs\/view\/standalone\/live$/)
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))
    await expectStandaloneOverview(page)
  })

  test('keeps the complete technical root available in admin view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, ROOT_PATH, PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(page.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'view',
    )
    await expectStandaloneOverview(page)
  })

  for (const viewer of [false, true]) {
    test(`searches real placement ids and preserves local selection as ${viewer ? 'a view-mode reader' : 'an admin'}`, async ({
      page,
    }) => {
      if (viewer) {
        await setAdminViewMode(true)
      } else {
        await signUpAdmin(page)
      }
      await gotoPage(page, ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(page)
      await expectStandalonePlacement(page)
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      }
      const totals = await Promise.all(
        PLACEMENT_TOTALS.map((total) =>
          page.getByTestId(`hilos-daemon-placement-${total}`).innerText(),
        ),
      )
      const cell = shownByTestId(page, PLACEMENT_CELL)
      const table = page.getByTestId('hilos-viewport-table').locator('table')
      const selection = table.getByTestId('hilos-daemon-placement-selection')
      const row = table.getByTestId(`hilos-table-row-${PLACEMENT_TYPE}`)
      const detail = table.getByTestId(
        `hilos-table-row-detail-${PLACEMENT_TYPE}`,
      )
      const expand = shownByTestId(page, `hilos-table-expand-${PLACEMENT_TYPE}`)
      await expect(page.getByTestId(/^hilos-table-expand-/)).toHaveCount(0)
      await cell.click()
      await expect(cell).toHaveAttribute('aria-expanded', 'true')
      await expect(selection).toBeVisible()
      await expect(
        detail.getByTestId('hilos-daemon-placement-selection'),
      ).toBeVisible()
      expect(
        await row.evaluate((element) =>
          element.nextElementSibling?.getAttribute('data-id'),
        ),
      ).toBe(`hilos-table-row-detail-${PLACEMENT_TYPE}`)
      await expect(expand).toHaveAttribute('aria-expanded', 'true')
      const ids = (await selection.locator('code').allTextContents()).slice(2)
      expect(ids).toEqual([PLACEMENT_TYPE])
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
      await shownByTestId(page, 'hilos-daemon-placement-collapse').click()
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.focus()
      await cell.press('Space')
      await expect(selection).toContainText(ids[0])

      const otherType = 'hilos_notifications_library'
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
      await expectUnfilteredTotals(page, totals)
      await expect(
        page.getByTestId('hilos-daemon-placement-alerts'),
      ).toBeEmpty()

      await page.getByTestId('hilos-daemon-nodes-tab').click()
      await expectStandaloneOverview(page)
      await expect(selection).toBeHidden()
      await expectStandalonePlacement(page)
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
      await expectUnfilteredTotals(page, totals)
      await shownByTestId(page, 'hilos-table-no-matches-reset').click()
      await expect(search).toHaveValue('')
      await expect(cell).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
    })
  }

  for (const theme of ['light', 'dark'] as const) {
    test(`keeps the live root and child controls readable in ${theme} at desktop and 375px`, async ({
      page,
    }) => {
      // Each size follows Workers and its breadcrumb over live subscriptions.
      test.slow()
      await page.emulateMedia({ colorScheme: theme })
      // Angular's theme menu is pending HIL-1441; use the existing browser pick
      // before registration so the account adopts the same confirmed theme.
      await page.addInitScript((pick) => {
        localStorage.setItem('hilos.theme.pick', pick)
      }, theme)
      await signUpAdmin(page)
      await gotoPage(page, ROOT_PATH, PAGE_READY)
      await expect(page.locator('html')).toHaveAttribute('data-bs-theme', theme)
      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 812 })
        await expectStandaloneOverview(page)
        expect(await sidewaysOverflow(page)).toEqual([0, 0])
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
        await expect(
          page.getByTestId('hilos-daemon-node-standalone'),
        ).toHaveCSS('background-color', colors.body)
        await expect(
          page.getByTestId('hilos-daemon-node-standalone'),
        ).toHaveCSS('color', colors.text)
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
          page.getByTestId(WORKERS_CHILD),
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
        await expectStandalonePlacement(page)
        const projection = width < 768 ? 'card' : 'row'
        await expect(
          shownByTestId(page, `hilos-table-${projection}-${PLACEMENT_TYPE}`),
        ).toBeVisible()
        const longType = 'hilos_notifications_library'
        const cell = shownByTestId(
          page,
          `hilos-daemon-placement-cell-${longType}-standalone`,
        )
        await cell.click()
        const selection = shownByTestId(
          page,
          'hilos-daemon-placement-selection',
        )
        const detail = shownByTestId(page, `hilos-table-row-detail-${longType}`)
        await expect(
          detail.getByTestId('hilos-daemon-placement-selection'),
        ).toBeVisible()
        if (projection === 'card') {
          await expect(
            page
              .getByTestId(`hilos-table-card-${longType}`)
              .getByTestId('hilos-daemon-placement-selection'),
          ).toBeVisible()
        } else {
          expect(
            await page
              .getByTestId(`hilos-table-row-${longType}`)
              .evaluate((element) =>
                element.nextElementSibling?.getAttribute('data-id'),
              ),
          ).toBe(`hilos-table-row-detail-${longType}`)
        }
        await expect(
          shownByTestId(page, 'hilos-daemon-placement-selection'),
        ).toHaveCount(1)
        await expect(selection.locator('code')).toHaveText([
          longType,
          'standalone',
          longType,
        ])
        expect(await sidewaysOverflow(page)).toEqual([0, 0])
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
        await test.info().attach(`placement-${theme}-${width}`, {
          body: await page
            .getByTestId('hilos-daemon-placement-view')
            .screenshot(),
          contentType: 'image/png',
        })
        await shownByTestId(page, 'hilos-daemon-placement-collapse').click()
        await page.getByTestId('hilos-daemon-nodes-tab').click()
        await expectStandaloneOverview(page)
        await page.getByTestId(WORKERS_CHILD).click()
        await expectDaemonPage(page, 'hilos_daemon_workers')
        await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
          'standalone',
        )
        await page.getByTestId(ROOT_CRUMB).click()
        await expectStandaloneOverview(page)
        await page.getByTestId('hilos-admin-child-hilos_daemon_agents').click()
        await expectDaemonPage(page, 'hilos_daemon_agents')
        await expect(
          shownByTestId(page, 'hilos-daemon-agent-restart-hilos_daemon_node'),
        ).toBeEnabled()
        expect(await sidewaysOverflow(page)).toEqual([0, 0])
        await page.getByTestId(ROOT_CRUMB).click()
        await expectStandaloneOverview(page)
        // The four remaining SDK pages use the same node child links at both sizes.
        for (const child of ['cron', 'http_server', 'env', 'env_mismatch']) {
          const key = `hilos_daemon_${child}`
          await page.getByTestId(`hilos-admin-child-${key}`).click()
          await expectDaemonPage(page, key)
          if (child === 'env_mismatch') {
            await expect(
              page.getByTestId('hilos-daemon-env-mismatch-standalone'),
            ).toBeVisible()
          } else {
            await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
              'standalone',
            )
          }
          await expect(page.locator('h1')).toHaveCount(1)
          expect(await sidewaysOverflow(page)).toEqual([0, 0])
          await page.getByTestId(ROOT_CRUMB).click()
          await expectStandaloneOverview(page)
        }
        await expect(page.locator('html')).toHaveAttribute(
          'data-bs-theme',
          theme,
        )
        expect(await sidewaysOverflow(page)).toEqual([0, 0])
      }
    })
  }
})

// The polls stand enables the framework Daemon section. Its standalone
// workers page waits for the node picture and opens a worker's agent panel.
test('shows the standalone node and its workers', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/workers', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-node-diagram')).toHaveCount(0)

  const expand = page.locator('[data-id^="hilos-table-expand-"]').first()
  await expect(expand).toBeVisible()
  await expand.click()
  await expect(
    page.locator('[data-id^="hilos-table-row-detail-"]').first(),
  ).toBeVisible()
})

test('finds the section node agent on the standalone agents page', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect.poll(() => rows.count()).toBeGreaterThan(1)

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('hilos_daemon_node', { delay: 10 })
  await expect(
    page.locator('[data-id^="hilos-table-row-hilos_daemon_node"]'),
  ).toBeVisible()
  await expect(rows).toHaveCount(1)
})

test('shows the standalone node and its schedule', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/cron', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(
    page.locator(
      '[data-id^="hilos-table-row-"][data-id$="/hilos_sessions_expire"]',
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-cron-not-leader')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-table-sort-/)).toHaveCount(0)
})

// Framework HTTP reporting: the listener and totals arrive through the page
// response, and the registered routes through its real socket viewport.
test('shows the standalone HTTP listener, counters and routes', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/http', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-http-address')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-http-address')).toHaveText(
    /^\s*(?:\[[^\]]+\]|[^:\s]+):\d+\s*$/,
  )
  for (const tile of ['requests', 'errors', 'slow']) {
    await expect(page.getByTestId(`hilos-daemon-http-${tile}`)).toBeVisible()
    await expect(page.getByTestId(`hilos-daemon-http-${tile}`)).toHaveText(
      /^\s*\d[\d,.\s]*$/,
    )
  }
  await expect(page.getByTestId('hilos-daemon-http-period')).toHaveText(
    /^\s*(?:Since start, (?:<1|\d+) h|Last 24 h)\s*$/,
  )
  await expect(page.getByTestId('hilos-daemon-http-status')).toHaveCount(0)
  const status = page.getByTestId('hilos-table-row-GET/%2Fstatus')
  await expect(status).toBeVisible()
  await expect(status).toContainText('/status')
  const unrouted = page.getByTestId('hilos-table-row-unrouted')
  await expect(unrouted).toBeVisible()
  await expect(unrouted).toContainText('No route')
})

test('shows the standalone environment, its ladder and only the length of a secret', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/env', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()

  for (const tile of ['keys', 'missing', 'example', 'drift']) {
    const el = page.getByTestId(`hilos-daemon-env-${tile}`)
    await expect(el).toBeVisible()
    await expect(el).toHaveText(/^\s*\d[\d,]*\s*$/)
  }
  const keysCount = await page.getByTestId('hilos-daemon-env-keys').innerText()
  expect(parseInt(keysCount.replace(/,/g, ''), 10)).toBeGreaterThan(0)

  const ladder = page.getByTestId('hilos-daemon-env-ladder')
  await expect(ladder).toBeVisible()
  await expect(ladder.locator('tr')).toHaveCount(5)

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('DB_PASSWORD', { delay: 10 })

  const row = shownByTestId(page, 'hilos-table-row-DB_PASSWORD')
  await expect(row).toBeVisible()
  await expect(row).toContainText(/Set · \d+ chars/)

  await expect(page.locator('body')).not.toContainText('hilos_pass')
})

test('shows no environment comparison on a standalone installation', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, ROOT_PATH, PAGE_READY)

  const child = page.getByTestId('hilos-admin-child-hilos_daemon_env_mismatch')
  await expect(child).toBeVisible()
  await expect(child).toHaveAttribute('href', '/hilos/daemon/env-mismatch')
  await child.click()

  await expectDaemonPage(page, 'hilos_daemon_env_mismatch')
  const standalone = page.getByTestId('hilos-daemon-env-mismatch-standalone')
  await expect(standalone).toBeVisible()
  await expect(standalone).toContainText('standalone')

  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-tile-answering'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-waiting'),
  ).toHaveCount(0)
})

test.describe('restarting an agent', () => {
  // The master's 13-second caller window must be judged on the first attempt.
  test.describe.configure({ retries: 0 })
  test.afterEach(() => setAdminViewMode(false))

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
          timeout: RESTART_PICTURE_TIMEOUT_MS,
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
        ).toHaveCount(0, { timeout: RESTART_PICTURE_TIMEOUT_MS })
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

  test('a view-mode viewer reads the live agents but cannot open restart', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(
      shownByTestId(page, `hilos-daemon-agent-restart-${RESTART_AGENT}`),
    ).toBeDisabled()
    await expect(page.getByTestId('modal')).toHaveCount(0)
  })
})

test.describe('Daemon environment in admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a viewer reads all four screens while environment values remain hidden', async ({
    page,
  }) => {
    // Four cold subscriptions and one catalog-key search over real server replies.
    test.slow()
    await setAdminViewMode(true)
    for (const [path, key] of [
      ['/hilos/daemon/standalone/cron', 'hilos_daemon_cron'],
      ['/hilos/daemon/standalone/http', 'hilos_daemon_http_server'],
      ['/hilos/daemon/standalone/env', 'hilos_daemon_env'],
      ['/hilos/daemon/env-mismatch', 'hilos_daemon_env_mismatch'],
    ]) {
      await gotoPage(page, path!, PAGE_READY)
      await expectDaemonPage(page, key!)
      await expect(page.locator('h1')).toHaveCount(1)
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      if (key === 'hilos_daemon_env') {
        const search = page.getByTestId('hilos-table-search')
        await search.fill('')
        await search.pressSequentially('DB_PASSWORD', { delay: 10 })
        const row = shownByTestId(page, 'hilos-table-row-DB_PASSWORD')
        await expect(row).toBeVisible()
        await expect(row.getByTestId('hilos-hidden')).toBeVisible()
        await expect(row).not.toContainText(/Set · \d+ chars/)
        await expect(page.locator('body')).not.toContainText('hilos_pass')
      }
    }
  })
})
