import type { Expect, Page } from '@playwright/test'
import { shownByTestId } from './table.js'

/**
 * Daemon section checks all three kits must meet alike. Tests, their order and
 * navigation stay in each demo's spec.
 */
export const DAEMON_ROOT_PATH = '/hilos/daemon'
export const DAEMON_WORKERS_PATH = '/hilos/daemon/standalone/workers'
export const DAEMON_WORKERS_CHILD = 'hilos-admin-child-hilos_daemon_workers'
export const DAEMON_ROOT_CRUMB = 'hilos-breadcrumb-hilos_daemon'
export const DAEMON_PLACEMENT_TYPE = 'hilos_daemon_node'
export const DAEMON_PLACEMENT_LONG_TYPE = 'hilos_notifications_library'
export const DAEMON_PLACEMENT_CELL = `hilos-daemon-placement-cell-${DAEMON_PLACEMENT_TYPE}-standalone`
export const DAEMON_PLACEMENT_TOTALS = [
  'instances',
  'types',
  'cluster-types',
  'unplaced',
] as const

/** The outlet must have mounted the destination before its ready mark counts. */
export async function expectDaemonPage(
  expect: Expect,
  page: Page,
  key: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-page')).toHaveAttribute(
    'data-page',
    key,
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
}

/** Wait for the real node report, which may arrive after the first page answer. */
export async function expectStandaloneOverview(
  expect: Expect,
  page: Page,
  databaseHost: string,
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Daemon')
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
  await expect(
    page.getByTestId('hilos-daemon-environment-unbuilt'),
  ).toHaveCount(0)
  const environmentTile = inspector.locator('dl').filter({ has: environment })
  await expect(environmentTile.locator('dt')).toHaveText('APP_ENV')
  await expect(environmentTile.locator('dd')).toHaveText([
    await environment.innerText(),
    'Application environment',
  ])

  const database = page.getByTestId('hilos-daemon-database')
  await expect(database).toContainText('Shared primary connection')
  await expect(page.getByTestId('hilos-daemon-database-address')).toHaveText(
    new RegExp(`^\\s*mysql:\\/\\/${databaseHost}:\\d+\\/[^\\s?@]+\\s*$`),
  )
  await expect(page.getByTestId('hilos-daemon-database-migration')).toHaveText(
    /^\s*Migration:\s+[1-9]\d*\s*$/,
  )
  await expect(database).not.toContainText(/password|username|credentials|@/i)
  await expect(page.getByTestId(DAEMON_WORKERS_CHILD)).toHaveAttribute(
    'href',
    DAEMON_WORKERS_PATH,
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
export async function expectStandalonePlacement(
  expect: Expect,
  page: Page,
): Promise<void> {
  const tab = page.getByTestId('hilos-daemon-placement-tab')
  await tab.focus()
  await tab.press('Enter')
  await expect(tab).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByTestId('hilos-daemon-nodes-tab')).toHaveAttribute(
    'aria-pressed',
    'false',
  )
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
  await expect(page.getByTestId('hilos-daemon-nodes-view')).toBeHidden()
  await expect(page.getByTestId('hilos-daemon-database')).toBeHidden()
  await expect(page.getByTestId('hilos-daemon-inspector')).toBeHidden()
  for (const total of DAEMON_PLACEMENT_TOTALS) {
    await expect(
      page.getByTestId(`hilos-daemon-placement-${total}`),
    ).toHaveText(total === 'unplaced' ? '0' : /^\s*[1-9][\d,]*\s*$/)
  }
  const view = page.getByTestId('hilos-daemon-placement-view')
  await expect(view).toContainText('All placed')
  await expect(view).toContainText('Declared in the project')
  await expect(page.getByTestId('hilos-daemon-placement-alerts')).toBeEmpty()
  const row = shownByTestId(page, `hilos-table-row-${DAEMON_PLACEMENT_TYPE}`)
  const card = shownByTestId(page, `hilos-table-card-${DAEMON_PLACEMENT_TYPE}`)
  await expect(row.or(card)).toContainText('Stays up')
  await expect(view).not.toContainText('★')
  await expect(shownByTestId(page, DAEMON_PLACEMENT_CELL)).toHaveAccessibleName(
    `${DAEMON_PLACEMENT_TYPE} on standalone: 1 instances`,
  )
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** Search keeps declared totals global; the live instance count may move. */
export async function expectUnfilteredTotals(
  expect: Expect,
  page: Page,
  totals: readonly string[],
): Promise<void> {
  for (const [index, total] of DAEMON_PLACEMENT_TOTALS.entries()) {
    const tile = page.getByTestId(`hilos-daemon-placement-${total}`)
    if (total === 'instances') {
      // The search below matches one instance, then none. Agents may start
      // or stop meanwhile; the count must still cover the whole daemon.
      await expect
        .poll(async () => Number((await tile.innerText()).replace(/,/g, '')))
        .toBeGreaterThan(1)
    } else {
      await expect(tile).toHaveText(totals[index])
    }
  }
}

/** The standalone worker roster has at least one live row or card. */
export async function expectStandaloneWorkers(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-node-diagram')).toHaveCount(0)
  await expect(
    shownByTestId(page, /^hilos-table-(row|card)-(regular|monopolistic):\d+$/),
  ).not.toHaveCount(0)
}

/** The standalone node owns the session-expiration schedule. */
export async function expectStandaloneCron(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(
    shownByTestId(
      page,
      /^hilos-table-(row|card)-hilos_sessions_library\/hilos_sessions_expire$/,
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-cron-not-leader')).toHaveCount(0)
}

/** Read the live HTTP listener, totals and routes in either table projection. */
export async function expectStandaloneHttp(
  expect: Expect,
  page: Page,
): Promise<void> {
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
  const status = shownByTestId(page, /^hilos-table-(row|card)-GET\/%2Fstatus$/)
  await expect(status).toBeVisible()
  await expect(status).toContainText('/status')
  const unrouted = shownByTestId(page, /^hilos-table-(row|card)-unrouted$/)
  await expect(unrouted).toBeVisible()
  await expect(unrouted).toContainText('No route')
}

/** Read the environment summary and its five-step reading ladder. */
export async function expectStandaloneEnvironment(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-node-state')).toHaveCount(0)
  for (const tile of ['keys', 'missing', 'example', 'drift']) {
    await expect(page.getByTestId(`hilos-daemon-env-${tile}`)).toBeVisible()
    await expect(page.getByTestId(`hilos-daemon-env-${tile}`)).toHaveText(
      /^\s*\d[\d,]*\s*$/,
    )
  }
  const count = await page.getByTestId('hilos-daemon-env-keys').innerText()
  expect(Number(count.replace(/,/g, ''))).toBeGreaterThan(0)
  const ladder = page.getByTestId('hilos-daemon-env-ladder')
  await expect(ladder).toBeVisible()
  await expect(ladder.locator('tr')).toHaveCount(5)
}

/** A single installation has no other environment to compare or table to open. */
export async function expectStandaloneMismatch(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-standalone'),
  ).toBeVisible()
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-standalone'),
  ).toContainText('nothing to compare it with')
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-standalone-node'),
  ).toHaveText('standalone')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-tile-answering'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-waiting'),
  ).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
}
