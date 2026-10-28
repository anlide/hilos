import { typeInto } from './input.js'
import type { Expect, Page } from '@playwright/test'
import { shownByTestId } from './table.js'
import { fitsInScrollport } from './geometry.js'
import { watchSocketFrames, type SocketFrame } from './socket.js'
import { deliveryObject } from './deliveryFrames.js'
import { nextClientPaint, watchClientDocument } from './lazyBundles.js'
import type { DeliveryFrameLog } from './deliveryFrames.js'
import type { watchTableSearch } from './tableFocus.js'
import { daemonOverviewSchema } from '../core/src/admin/daemon/index.js'
import type {
  HilosDaemonNodeHeading,
  HilosDaemonNodeOverviewData,
} from '../core/src/admin/daemon/index.js'

/**
 * Daemon section checks all three kits must meet alike. Tests, their order and
 * navigation stay in each demo's spec.
 */
export const DAEMON_ROOT_PATH = '/hilos/daemon'
export const DAEMON_PLACEMENT_PATH = '/hilos/daemon/placement'
export const DAEMON_WORKERS_PATH = '/hilos/daemon/standalone/workers'
export const DAEMON_WORKERS_CHILD = 'hilos-page-tab-hilos_daemon_workers'
export const DAEMON_NODE_PATH = '/hilos/daemon/standalone'
export const DAEMON_NODE_TABS = [
  { key: 'hilos_daemon_node', path: '', label: 'Overview' },
  { key: 'hilos_daemon_workers', path: '/workers', label: 'Workers' },
  { key: 'hilos_daemon_agents', path: '/agents', label: 'Agents' },
  { key: 'hilos_daemon_cron', path: '/cron', label: 'Cron' },
  { key: 'hilos_daemon_http_server', path: '/http', label: 'HTTP' },
  { key: 'hilos_daemon_env', path: '/env', label: 'Environment' },
  {
    key: 'hilos_daemon_env_orphans',
    path: '/env/orphans',
    label: 'Env orphans',
  },
] as const
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

// A changed connection is coalesced, reported within 5 s and fanned out within 0.5 s.
const DAEMON_SOURCE_TIMEOUT_MS = 10_000

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

/** Root picture: links, processes, database and section navigation. */
export async function expectStandaloneOverview(
  expect: Expect,
  page: Page,
  databaseHost: string,
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Daemon')
  const node = page.getByTestId('hilos-daemon-node-standalone')
  await expect(node).toHaveRole('link')
  await expect(node).toHaveAccessibleName('Node standalone')
  await expect(node).toHaveAttribute('href', DAEMON_NODE_PATH)
  await expect(node).not.toHaveAttribute('aria-pressed')
  await expect(node).toContainText('Single installation')
  await expect(page.getByTestId('hilos-daemon-inspector')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-node-overview')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-maintenance')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-node-log')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-consensus')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-overview-waiting')).toHaveCount(0)
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
  const database = page.getByTestId('hilos-daemon-database')
  await expect(database).toContainText('Shared primary connection')
  await expect(page.getByTestId('hilos-daemon-database-address')).toHaveText(
    new RegExp(`^\\s*mysql:\\/\\/${databaseHost}:\\d+\\/[^\\s?@]+\\s*$`),
  )
  await expect(page.getByTestId('hilos-daemon-database-migration')).toHaveText(
    /^\s*Migration:\s+[1-9]\d*\s*$/,
  )
  await expect(database).not.toContainText(/password|username|credentials|@/i)
  await expectDaemonSectionHead(expect, page, 'hilos_daemon')
  await expect(page.getByTestId('hilos-daemon-entry-points')).toContainText(
    'Browsers',
  )
  await expect(page.getByTestId('hilos-daemon-entry-command-port')).toHaveText(
    /^:\d+$/,
  )
  await expect(page.getByTestId('hilos-daemon-entry-http-port')).toHaveText(
    /^:\d+$/,
  )
  await expect(page.getByTestId('hilos-daemon-entry-metrics')).toHaveAttribute(
    'href',
    `${DAEMON_NODE_PATH}/http`,
  )
  await expect(page.getByTestId('hilos-daemon-placement-view')).toHaveCount(0)
}

/** Shared section identity and addressable tabs, without an entity in the row. */
export async function expectDaemonSectionHead(
  expect: Expect,
  page: Page,
  current: string,
  clustered = false,
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText('Daemon')
  await expect(page.getByTestId('hilos-page-head-block')).toContainText(
    'Nodes and their processes, with agent placement across them.',
  )
  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect
    .poll(() =>
      crumbs.locator('li').evaluateAll((items) =>
        items.map((item) => {
          const words = item.cloneNode(true) as Element
          for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
            hidden.remove()
          return {
            label: words.textContent?.trim(),
            current: item.getAttribute('aria-current'),
          }
        }),
      ),
    )
    .toEqual([
      { label: 'Hilos', current: null },
      { label: 'Daemon', current: 'page' },
    ])
  await expect(
    page.getByTestId('hilos-page-head-block').locator('.bi-cpu'),
  ).toHaveCount(1)
  const nav = page.getByTestId('hilos-page-tabs')
  await expect(nav).toHaveRole('navigation')
  await expect(nav).toHaveAccessibleName('Daemon pages')
  await expect(nav.getByRole('link')).toHaveText(
    clustered
      ? ['Overview', 'Agent placement', 'Env mismatch']
      : ['Overview', 'Agent placement'],
  )
  await expect(
    nav.locator('[role="tablist"], [role="tab"], [aria-pressed]'),
  ).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-tab-hilos_daemon')).toHaveAttribute(
    'href',
    DAEMON_ROOT_PATH,
  )
  await expect(
    page.getByTestId('hilos-page-tab-hilos_daemon_placement'),
  ).toHaveAttribute('href', DAEMON_PLACEMENT_PATH)
  const mismatch = page.getByTestId('hilos-page-tab-hilos_daemon_env_mismatch')
  if (clustered)
    await expect(mismatch).toHaveAttribute('href', '/hilos/daemon/env-mismatch')
  else await expect(mismatch).toHaveCount(0)
  await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(page.getByTestId(`hilos-page-tab-${current}`)).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(
    page.getByTestId('hilos-page-tab-hilos_daemon_node'),
  ).toHaveCount(0)
}

/** Shared entity head and real links on every addressed Node page. */
export async function expectDaemonNodeHead(
  expect: Expect,
  page: Page,
  pageKey: string,
  nodeId = 'standalone',
  options: { showLogLinks?: boolean } = {},
): Promise<void> {
  await expectDaemonPage(expect, page, pageKey)
  const title = page.getByRole('heading', { level: 1 })
  await expect(title).toHaveCount(1)
  await expect(title).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(title).toHaveText(nodeId)
  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  // React and Angular draw decorative separators inside each list item.
  await expect
    .poll(() =>
      crumbs.locator('li').evaluateAll((items) =>
        items.map((item) => {
          const words = item.cloneNode(true) as Element
          for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
            hidden.remove()
          return words.textContent?.trim()
        }),
      ),
    )
    .toEqual(['Hilos', 'Daemon', nodeId])
  await expect(page.getByTestId(DAEMON_ROOT_CRUMB)).toHaveAttribute(
    'href',
    DAEMON_ROOT_PATH,
  )
  await expect(crumbs.locator('[aria-current="page"]')).toContainText(nodeId)
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveCount(1)
  await expect(tabs).toHaveRole('navigation')
  await expect(tabs).toHaveAccessibleName('Node pages')
  await expect(tabs.getByRole('link')).toHaveText(
    DAEMON_NODE_TABS.map((tab) => tab.label),
  )
  for (const tab of DAEMON_NODE_TABS) {
    const link = tabs.getByTestId(`hilos-page-tab-${tab.key}`)
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAttribute(
      'href',
      `${DAEMON_ROOT_PATH}/${nodeId}${tab.path}`,
    )
    if (tab.key === pageKey)
      await expect(link).toHaveAttribute('aria-current', 'page')
    else await expect(link).not.toHaveAttribute('aria-current')
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)
  await expect(page.getByTestId(/^hilos-admin-child-/)).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-inspector')).toHaveCount(0)
  const log = page.getByTestId('hilos-daemon-node-log')
  if (options.showLogLinks ?? true) {
    await expect(log).toHaveAttribute('href', `/hilos/logs/view/${nodeId}/live`)
  } else await expect(log).toHaveCount(0)
}

/** NodeShell tables use the visible entity heading, including Angular projection. */
export async function expectDaemonNodeTableName(
  expect: Expect,
  page: Page,
): Promise<void> {
  const title = page.getByTestId('hilos-page-head-title')
  const id = await title.getAttribute('id')
  expect(id).toBeTruthy()
  await expect(page.getByRole('table')).toHaveAccessibleName('standalone')
  await expect(page.getByRole('table')).toHaveAttribute('aria-labelledby', id!)
}

/** Diagnostics formerly read in the Inspector now belong to the Node Overview. */
export async function expectStandaloneNodeOverview(
  expect: Expect,
  page: Page,
  options: { showLogLinks?: boolean } = {},
): Promise<void> {
  await expectDaemonNodeHead(
    expect,
    page,
    'hilos_daemon_node',
    'standalone',
    options,
  )
  const overview = page.getByTestId('hilos-daemon-node-overview')
  await expect(overview).toHaveAttribute('data-node-id', 'standalone')
  await expect(page.getByTestId('hilos-daemon-node-solo')).toHaveCount(1)
  await expect(overview.locator('h2')).toHaveText([
    'Processor',
    'Memory',
    'Node',
    'Environment',
    'Node maintenance',
  ])
  await expect(
    overview
      .locator('h2')
      .nth(0)
      .locator('xpath=following-sibling::div[1]')
      .locator('dl'),
  ).toHaveCount(4)
  await expect(
    overview
      .locator('h2')
      .nth(1)
      .locator('xpath=following-sibling::div[1]')
      .locator('dl'),
  ).toHaveCount(4)
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
  await expect(overview).toContainText(
    /Container CPU quota|CPUs available to the process \(nproc\)/,
  )
  await expect(overview).toContainText(
    /Container memory limit|Physical machine memory/,
  )
  await expect(page.getByTestId('hilos-daemon-node-connections')).toHaveText(
    /^\s*\d[\d,]*\s+connections\s*$/,
  )
  await expect(page.getByTestId('hilos-daemon-monopolistic-count')).toHaveText(
    /^\s*\d[\d,]*\s*$/,
  )
  await expect(overview).toContainText('Pool grows on demand')
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
  const environmentTile = overview.locator('dl').filter({ has: environment })
  await expect(environmentTile.locator('dt')).toHaveText('APP_ENV')
  await expect(environmentTile.locator('dd')).toHaveText([
    await environment.innerText(),
    'Application environment',
  ])

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

/** The old Inspector's links and maintenance controls still fit their new page. */
export async function expectDaemonNodeFits(
  expect: Expect,
  page: Page,
): Promise<void> {
  const maintenance = page
    .getByTestId('hilos-daemon-maintenance')
    .locator('button')
  await expect(maintenance).toHaveCount(3)
  for (const target of [
    page.getByTestId('hilos-daemon-node-log'),
    page.getByTestId(DAEMON_WORKERS_CHILD),
    ...(await maintenance.all()),
  ]) {
    await target.scrollIntoViewIfNeeded()
    await fitsInScrollport(target)
  }
}

/** Open Placement at its own address and wait for its real standalone roster window. */
export async function expectStandalonePlacement(
  expect: Expect,
  page: Page,
): Promise<void> {
  const tab = page.getByTestId('hilos-page-tab-hilos_daemon_placement')
  await tab.focus()
  await tab.press('Enter')
  await expectDaemonPage(expect, page, 'hilos_daemon_placement')
  await expectDaemonSectionHead(expect, page, 'hilos_daemon_placement')
  await expect(page).toHaveURL(new RegExp(`${DAEMON_PLACEMENT_PATH}$`))
  await expect(page.getByTestId('hilos-daemon-nodes-view')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-database')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-inspector')).toHaveCount(0)
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
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(
    shownByTestId(
      page,
      /^hilos-table-(row|card)-hilos_sessions_library\/hilos_sessions_expire$/,
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-cron-not-leader')).toHaveCount(0)
}

/** Read one row from the latest real Cron window and its subsequent live edits. */
export function readDaemonCronMoment(
  frames: readonly SocketFrame[],
  rowKey: string,
) {
  let current: { frame: SocketFrame; rule: Record<string, unknown> } | undefined
  for (const frame of frames) {
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    if (data.page !== 'hilos_daemon_cron') continue
    const window =
      wire.type === 'page_response'
        ? deliveryObject(
            deliveryObject(deliveryObject(data.payload).windows)
              .hilosDaemonCron,
          )
        : wire.type === 'table_window' && data.tableKey === 'hilosDaemonCron'
          ? data
          : undefined
    if (Array.isArray(window?.rows)) {
      const row = window.rows.find(
        (value) => deliveryObject(value).rowKey === rowKey,
      )
      current = row
        ? {
            frame,
            rule: deliveryObject(
              deliveryObject(deliveryObject(row).slots).rule,
            ),
          }
        : undefined
    } else if (
      wire.type === 'table_viewport_delta' &&
      data.tableKey === 'hilosDaemonCron' &&
      data.rowKey === rowKey
    ) {
      if (data.kind === 'row_removed') current = undefined
      else if (data.kind === 'row_updated' || data.kind === 'row_moved') {
        current = {
          frame,
          rule: deliveryObject(
            deliveryObject(deliveryObject(data.row).slots).rule,
          ),
        }
      }
    }
  }
  return current
}

/** Independent browser Intl oracle for the real wire moments, never the SDK formatter. */
export async function expectDaemonCronMoments(
  expect: Expect,
  page: Page,
  frames: readonly SocketFrame[],
  rowKey: string,
  timeZone: string,
): Promise<{ lastRun: string; nextRun: string }> {
  await expect.poll(() => readDaemonCronMoment(frames, rowKey)).toBeDefined()
  expect(frames.filter((frame) => frame.failure !== undefined)).toEqual([])
  const { frame, rule } = readDaemonCronMoment(frames, rowKey)!
  expect(frame.raw).toEqual(expect.any(String))
  expect(rule).toMatchObject({ rowKey, idleReason: null })
  for (const value of [rule.lastRunAt, rule.nextRunAt]) {
    if (value === null) continue
    expect(Number.isSafeInteger(value)).toBe(true)
    // Contemporary epoch milliseconds; epoch seconds and double conversion fail.
    expect(value).toBeGreaterThan(Date.UTC(2020, 0))
    expect(value).toBeLessThan(Date.UTC(2100, 0))
  }
  expect(rule.nextRunAt).not.toBeNull()
  const expected = await page.evaluate(
    ({ lastRunAt, nextRunAt, timeZone }) => {
      const calendar = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
      })
      const dayNumber = (at: number) => {
        const parts = calendar.formatToParts(at)
        const part = (type: string) =>
          Number(parts.find((part) => part.type === type)!.value)
        return (
          Date.UTC(part('year'), part('month') - 1, part('day')) / 86_400_000
        )
      }
      const now = dayNumber(Date.now())
      const moment = (at: number) => {
        const days = dayNumber(at) - now
        const date =
          Math.abs(days) <= 1
            ? new Intl.RelativeTimeFormat('en-US', { numeric: 'auto' }).format(
                days,
                'day',
              )
            : new Intl.DateTimeFormat('en-US', {
                timeZone,
                dateStyle: 'medium',
              }).format(at)
        const time = new Intl.DateTimeFormat('en-US', {
          timeZone,
          hour: '2-digit',
          minute: '2-digit',
        }).format(at)
        return `${date}, ${time}`
      }
      return {
        lastRun: lastRunAt === null ? 'Not yet' : moment(lastRunAt),
        nextRun: moment(nextRunAt),
      }
    },
    {
      lastRunAt: rule.lastRunAt as number | null,
      nextRunAt: rule.nextRunAt as number,
      timeZone,
    },
  )
  const row = shownByTestId(page, `hilos-table-row-${rowKey}`)
  await expect(row).toBeVisible()
  await expect(row.getByRole('cell')).toContainText([
    expected.lastRun,
    expected.nextRun,
  ])
  return expected
}

/** Retain the actual document, table and row while the session restates its zone. */
export async function watchDaemonCronDisplay(
  expect: Expect,
  page: Page,
  rowKey: string,
) {
  const documentWatch = await watchClientDocument(page)
  const table = await page.getByTestId('hilos-viewport-table').elementHandle()
  const row = await shownByTestId(
    page,
    `hilos-table-row-${rowKey}`,
  ).elementHandle()
  if (table === null || row === null)
    throw new Error('A mounted Cron row is required.')
  return {
    async unchanged(): Promise<void> {
      await documentWatch.unchanged()
      expect(await table.evaluate((original) => original.isConnected)).toBe(
        true,
      )
      expect(await row.evaluate((original) => original.isConnected)).toBe(true)
    },
    async dispose(): Promise<void> {
      await table.dispose()
      await row.dispose()
      await documentWatch.dispose()
    },
  }
}

/** Read the live HTTP listener, totals and routes in either table projection. */
export async function expectStandaloneHttp(
  expect: Expect,
  page: Page,
): Promise<void> {
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
  await expect(ladder.locator('li')).toHaveCount(5)
}

/**
 * From the node row, open Env orphans and read its one untitled table.
 *
 * The caller is on the Daemon root, where that node's row is a link.
 */
export async function expectStandaloneEnvOrphans(
  expect: Expect,
  page: Page,
  nodeId = 'standalone',
): Promise<void> {
  const node = page.getByTestId(`hilos-daemon-node-${nodeId}`)
  await node.scrollIntoViewIfNeeded()
  await node.focus()
  await node.press('Enter')
  await expectDaemonPage(expect, page, 'hilos_daemon_node')
  const link = page.getByTestId('hilos-page-tab-hilos_daemon_env_orphans')
  await link.focus()
  await expect(link).toBeFocused()
  await link.press('Enter')
  await expect(page).toHaveURL(
    new RegExp(`/hilos/daemon/${nodeId}/env/orphans$`),
  )
  await expectDaemonPage(expect, page, 'hilos_daemon_env_orphans')
  await expect(page.getByRole('table')).toHaveCount(1)
  await expect(
    page.getByRole('table', { name: 'Orphans in .env' }),
  ).toHaveCount(0)
  await expect(page.locator('body')).not.toContainText('Orphans in .env')
  await expect(link).toHaveAttribute('aria-current', 'page')
}

/**
 * A view-mode viewer sees each orphan value hidden, with no character count.
 *
 * An empty live window has no value to hide. A present row is the hidden mark
 * and nothing that states a length.
 */
export async function expectViewerEnvOrphans(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByRole('table')).toHaveCount(1)
  await expect(
    page.getByRole('table', { name: 'Orphans in .env' }),
  ).toHaveCount(0)
  const rows = shownByTestId(page, /^hilos-table-(row|card)-/)
  const empty = page.getByTestId('hilos-daemon-env-orphans-empty')
  await expect(rows.or(empty).first()).toBeVisible()
  for (const row of await rows.all()) {
    await expect(row.getByTestId('hilos-hidden')).toHaveText('Hidden')
    await expect(row).not.toContainText(/Set ·|\d+\s*chars/)
  }
  await expect(page.locator('body')).not.toContainText(/Set ·\s*\d+\s*chars/)
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

/** The stand's members and the consensus verdict expected from real inspect replies. */
interface ClusterOverview {
  masters: readonly string[]
  slaves: readonly string[]
  leaderId: string
  term: number
  silentNodeIds: readonly string[]
}

/** Wait for the complete cluster picture, including silent members after leader loss. */
export async function expectClusterOverview(
  expect: Expect,
  page: Page,
  { masters, slaves, leaderId, term, silentNodeIds }: ClusterOverview,
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon')
  await expect(page.getByTestId('hilos-daemon-overview-waiting')).toHaveCount(0)
  for (const id of [
    'hilos-daemon-inspector',
    'hilos-daemon-node-overview',
    'hilos-daemon-maintenance',
    'hilos-daemon-node-log',
    'hilos-daemon-uptime',
    'hilos-daemon-cores',
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
  const nodes = page.getByTestId('hilos-daemon-nodes')
  await expect(nodes.getByTestId(/^hilos-daemon-node-/)).toHaveCount(
    masters.length + slaves.length,
  )
  for (const nodeId of [...masters, ...slaves]) {
    const node = nodes.getByTestId(`hilos-daemon-node-${nodeId}`)
    const state = silentNodeIds.includes(nodeId)
      ? 'silent'
      : nodeId === leaderId
        ? 'leader'
        : masters.includes(nodeId)
          ? 'standby'
          : 'data'
    await expect(node).toBeVisible()
    await expect(node).toHaveRole('link')
    await expect(node).toHaveAccessibleName(`Node ${nodeId}`)
    await expect(node).toHaveAttribute('href', `${DAEMON_ROOT_PATH}/${nodeId}`)
    await expect(node).not.toHaveAttribute('aria-pressed')
    await expect(node).toHaveAttribute('data-state', state)
    if (state !== 'silent') {
      // A declared member without its node report is not a live initial picture.
      await expect(node.getByTestId(/^hilos-daemon-worker-/)).not.toHaveCount(0)
      await expect(
        node.getByTestId(`hilos-daemon-silent-${nodeId}`),
      ).toHaveCount(0)
    } else {
      await expect(
        node.getByTestId(`hilos-daemon-silent-${nodeId}`),
      ).toHaveText(/^Silent since (?!—$).+/)
      await expect(node.getByTestId(/^hilos-daemon-worker-/)).toHaveCount(0)
    }
  }
  await expect(nodes.locator('[data-state="leader"]')).toHaveCount(1)
  await expect(page.getByTestId('hilos-daemon-term')).toHaveText(
    `Term: ${term}`,
  )
  const onlineMasters = masters.filter(
    (node) => !silentNodeIds.includes(node),
  ).length
  await expect(page.getByTestId('hilos-daemon-quorum')).toHaveText(
    `Quorum: ${onlineMasters} of ${masters.length} · Available`,
  )
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expectDaemonPage(expect, page, 'hilos_daemon')
}

/** A node-addressed page must show a report, including a genuinely reported empty roster. */
export async function expectClusterDaemonNodePage(
  expect: Expect,
  page: Page,
  { pageKey, nodeId }: { pageKey: string; nodeId: string },
): Promise<void> {
  await expectDaemonPage(expect, page, pageKey)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(nodeId)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const section = pageKey.replace('hilos_daemon_', '')
  if (section !== 'env') {
    await expect(page.getByTestId('hilos-daemon-node-state')).toHaveText(
      /^(Leader|Standby|Data)$/,
    )
  } else {
    // Environment answers from its own node agent, without a consensus verdict.
    await expect(page.getByTestId('hilos-daemon-node-state')).toHaveCount(0)
  }
  if (['workers', 'agents', 'cron'].includes(section)) {
    await expect(
      page.getByTestId(`hilos-daemon-${section}-empty-waiting`),
    ).toHaveCount(0)
    await expect(
      page.getByTestId(`hilos-daemon-${section}-empty-silent`),
    ).toHaveCount(0)
    await expect(
      shownByTestId(page, /^hilos-table-(row|card)-/).or(
        page.getByTestId(`hilos-daemon-${section}-empty-none`),
      ),
    ).not.toHaveCount(0)
  } else if (section === 'http_server') {
    await expect(page.getByTestId('hilos-daemon-http-status')).toHaveCount(0)
    await expect(page.getByTestId('hilos-daemon-http-address')).toHaveText(
      /^\s*(?:\[[^\]]+\]|[^:\s]+):\d+\s*$/,
    )
    for (const tile of ['requests', 'errors', 'slow']) {
      await expect(page.getByTestId(`hilos-daemon-http-${tile}`)).toHaveText(
        /^\s*\d[\d,.\s]*$/,
      )
    }
  } else if (section === 'env') {
    await expect(page.getByTestId('hilos-daemon-env-keys')).toHaveText(
      /^\s*[1-9][\d,]*\s*$/,
    )
  }
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** Check the addressed cluster Overview against its actual latest node report. */
export async function expectClusterDaemonNodeOverview(
  expect: Expect,
  page: Page,
  frames: readonly DaemonPageFrame[],
  {
    nodeId,
    state,
    role,
  }: {
    nodeId: string
    state: 'Leader' | 'Standby' | 'Data' | 'Silent'
    role: 'master' | 'slave' | null
  },
): Promise<void> {
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node', nodeId)
  await expect(page).toHaveURL(`${DAEMON_ROOT_PATH}/${nodeId}`)
  await expect(page.getByTestId('hilos-daemon-node-overview')).toHaveAttribute(
    'data-node-id',
    nodeId,
  )
  await expect(page.getByTestId('hilos-daemon-node-state')).toHaveText(state)
  await expect(page.getByTestId('hilos-daemon-node-role')).toHaveText(
    `Role: ${role ?? '—'}`,
  )
  const read = (frame: DaemonPageFrame) =>
    (
      frame.wire as {
        data: {
          payload: {
            data: {
              node: HilosDaemonNodeHeading
              overview: HilosDaemonNodeOverviewData
              pageHeadOwner?: unknown
              pageTabs?: unknown
            }
          }
        }
      }
    ).data.payload.data
  await expect.poll(() => frames.length).toBeGreaterThan(0)
  const first = read(frames[0])
  expect(first.pageHeadOwner).toBeDefined()
  expect(first.pageTabs).toBeDefined()
  expect(first.overview.nodeId).toBe(nodeId)
  for (const frame of frames) {
    const data = read(frame)
    expect(data.overview.nodeId).toBe(nodeId)
    expect(data.node).toHaveProperty('role', role)
  }
  await expect
    .poll(() => {
      const latest = read(frames.at(-1)!)
      return { state: latest.node.state, online: latest.overview.online }
    })
    .toEqual({ state: state.toLowerCase(), online: state !== 'Silent' })
  await expect(page.getByTestId('hilos-page-head-action')).toHaveAttribute(
    'href',
    DAEMON_ROOT_PATH,
  )
  // Compare the rendered CPU and process counts with the addressed report,
  // including unknown fields, without importing the renderer's formatters.
  await expect
    .poll(async () => {
      const overview = read(frames.at(-1)!).overview
      const reported = await page.evaluate((overview) => {
        const number = (value: number | null | undefined) =>
          value == null
            ? '—'
            : value.toLocaleString(undefined, { maximumFractionDigits: 2 })
        return [
          number(overview.measurements?.cores),
          `${number(overview.measurements?.daemonCores)} cores`,
          number(
            overview.workers === null
              ? null
              : overview.workers.filter(
                  (worker) => worker.kind === 'monopolistic',
                ).length,
          ),
          `${number(overview.measurements?.workersMeasured)} of ${number(overview.workers?.length)} worker processes measured`,
        ]
      }, overview)
      const displayed = await Promise.all(
        ['cores', 'daemon-cores', 'monopolistic-count', 'workers-measured'].map(
          async (id) =>
            (await page.getByTestId(`hilos-daemon-${id}`).innerText())
              .replace(/\s+/g, ' ')
              .trim(),
        ),
      )
      return {
        reported,
        displayed,
        matches: JSON.stringify(reported) === JSON.stringify(displayed),
      }
    })
    .toMatchObject({ matches: true })
  // Read the displayed duration back into minutes; null must stay unknown.
  // No browser clock participates, and a newer report may cross a minute boundary.
  await expect
    .poll(async () => {
      const text = (
        await page.getByTestId('hilos-daemon-uptime').innerText()
      ).trim()
      const minutes = read(frames.at(-1)!).overview.uptimeMinutes
      if (minutes === null) return text === '—'
      const duration = /^(?:(\d+) d )?(?:(\d+) h )?(\d+) min$/.exec(text)
      return (
        duration !== null &&
        Number(duration[1] ?? 0) * 1440 +
          Number(duration[2] ?? 0) * 60 +
          Number(duration[3]) ===
          minutes
      )
    })
    .toBe(true)
  const notice = page.getByTestId('hilos-daemon-report-notice')
  if (state === 'Silent') {
    await expect(notice).toHaveText(
      /^Silent · values from the last report\. Last arrival: (?!—\.).+\.$/,
    )
    await expect(page.getByTestId('hilos-daemon-node-silent')).toHaveText(
      /^Silent since (?!—$).+/,
    )
    const lastLive = frames
      .filter((frame) => read(frame).overview.online)
      .at(-1)
    expect(lastLive).toBeDefined()
    const previous = read(lastLive!).overview
    const latest = read(frames.at(-1)!).overview
    expect(read(frames.at(-1)!).node.silentSince).toEqual(expect.any(Number))
    for (const key of [
      'workers',
      'measurements',
      'standing',
      'environment',
      'uptimeMinutes',
      'workerRestarts24h',
    ] as const)
      expect(latest[key]).toEqual(previous[key])
  } else {
    await expect(notice).toBeHidden()
    await expect(page.getByTestId('hilos-daemon-node-silent')).toHaveCount(0)
  }
  await expect(
    page.getByTestId('hilos-daemon-environment-link'),
  ).toHaveAttribute('href', `${DAEMON_ROOT_PATH}/${nodeId}/env`)
  const maintenance = page
    .getByTestId('hilos-daemon-maintenance')
    .locator('button')
  await expect(maintenance).toHaveCount(3)
  for (const button of await maintenance.all())
    await expect(button).toBeDisabled()
  await expect(page.getByTestId('page-error')).toHaveCount(0)
}

/** A real agent schedule on a reported node, without any daemon-rule notice. */
export async function expectClusterDaemonCronSchedule(
  expect: Expect,
  page: Page,
  frames: readonly DaemonPageFrame[],
  { agentId, ruleName }: { agentId: string; ruleName: string },
): Promise<void> {
  interface CronRow {
    rowKey: string
    slots: {
      rule: {
        agentId: string | null
        name: string
        nextRunAt: number | null
        idleReason: string | null
      }
    }
  }
  const snapshot = () => {
    let cronReported = false
    let rows: CronRow[] = []
    for (const frame of frames) {
      const { data } = frame.wire as {
        data: {
          tableKey?: string
          rows?: CronRow[]
          payload?: {
            data?: { cronReported?: boolean }
            windows?: { hilosDaemonCron?: { rows: CronRow[] } }
          }
        }
      }
      if (frame.type === 'page_response') {
        cronReported = data.payload?.data?.cronReported === true
        rows = data.payload?.windows?.hilosDaemonCron?.rows ?? []
      } else if (data.tableKey === 'hilosDaemonCron') {
        rows = data.rows ?? []
      }
    }
    return {
      cronReported,
      rule: rows.find(
        (row) =>
          row.slots.rule.agentId === agentId &&
          row.slots.rule.name === ruleName,
      )?.slots.rule,
      daemonRows: rows.filter((row) => row.slots.rule.agentId === null),
    }
  }
  await expect.poll(snapshot).toMatchObject({
    cronReported: true,
    rule: {
      agentId,
      name: ruleName,
      nextRunAt: expect.any(Number),
      idleReason: null,
    },
    daemonRows: [],
  })
  const rowKey = `${agentId}/${ruleName}`
  await expect(
    shownByTestId(page, `hilos-table-row-${rowKey}`).or(
      shownByTestId(page, `hilos-table-card-${rowKey}`),
    ),
  ).toContainText(ruleName)
  await expect(
    shownByTestId(page, `hilos-daemon-cron-executor-${rowKey}`),
  ).toHaveText(agentId)
  for (const id of [
    'hilos-table-loading',
    'hilos-daemon-cron-empty-waiting',
    'hilos-daemon-cron-empty-silent',
    'hilos-daemon-cron-empty-none',
    'hilos-daemon-cron-silent',
    'hilos-daemon-cron-not-leader',
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
}

/** Expand incoming worker rows until the reported agent instance is visible. */
export async function expectDaemonWorkerAgent(
  expect: Expect,
  page: Page,
  agentId: string,
): Promise<void> {
  await expect
    .poll(async () => {
      const collapsed = await shownByTestId(page, /^hilos-table-expand-/)
        .and(page.locator('[aria-expanded="false"]'))
        .evaluateAll((buttons) =>
          buttons.map((button) => button.getAttribute('data-id')),
        )
      for (const id of collapsed) {
        if (id !== null) await shownByTestId(page, id).click()
      }
      return shownByTestId(page, /^hilos-daemon-worker-agents-/)
        .filter({ hasText: agentId })
        .isVisible()
    })
    .toBe(true)
}

/** Check LEADER rows against a new owner while retaining the dead member's unknown column. */
export async function expectClusterLeaderPlacement(
  expect: Expect,
  page: Page,
  {
    agentTypes,
    leaderId,
    silentNodeId,
  }: { agentTypes: readonly string[]; leaderId: string; silentNodeId?: string },
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon')
  await expect(page.getByTestId('hilos-daemon-placement-view')).toBeVisible()
  for (const agentType of agentTypes) {
    const row = shownByTestId(page, `hilos-table-row-${agentType}`).or(
      shownByTestId(page, `hilos-table-card-${agentType}`),
    )
    await expect(row).toContainText('Leader-hosted')
    await expect(
      shownByTestId(
        page,
        `hilos-daemon-placement-cell-${agentType}-${leaderId}`,
      ),
    ).toHaveAccessibleName(`${agentType} on ${leaderId}: 1 instances`)
    if (silentNodeId !== undefined) {
      await expect(
        shownByTestId(
          page,
          `hilos-daemon-placement-cell-${agentType}-${silentNodeId}`,
        ),
      ).toHaveText('?')
    }
  }
  await expect(page.getByTestId('page-error')).toHaveCount(0)
}

/** Remote values are compared as digests; an offline node cannot keep its old fingerprint. */
export async function expectClusterEnvironmentMismatch(
  expect: Expect,
  page: Page,
  {
    key,
    remoteNodeId,
    silentNodeId,
    frames,
    after = 0,
  }: {
    key: string
    remoteNodeId: string
    silentNodeId?: string
    frames?: readonly DaemonMismatchFrame[]
    after?: number
  },
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon_env_mismatch')
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-waiting'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-tile-diverged'),
  ).toHaveAttribute('data-tone', 'danger')
  const cellId = `hilos-daemon-env-mismatch-cell-${key}-${remoteNodeId}`
  const remote = shownByTestId(page, cellId)
  await expect(remote).toHaveAttribute('data-kind', 'digest')
  await expect(remote.locator('code')).toHaveText(/^#[0-9a-f]{4,16}$/)
  await expect(remote).toContainText('Launch environment')
  await expect(shownByTestId(page, `${cellId}-stands-out`)).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(shownByTestId(page, `hilos-table-row-${key}`)).toBeVisible()
  if (frames !== undefined) {
    // A cold mirror may initially refuse the window; its ordinary recovery must
    // deliver the same single table and the real diverged row on this visit.
    await expect
      .poll(() => {
        let rows: unknown[] = []
        let answered = false
        for (const frame of frames.slice(after)) {
          const data = deliveryObject(deliveryObject(frame.wire).data)
          if (frame.type === 'page_response') {
            answered = true
            const windows = deliveryObject(deliveryObject(data.payload).windows)
            const window = deliveryObject(windows.hilosDaemonEnvMismatch)
            if (Array.isArray(window.rows)) rows = window.rows
          } else if (data.tableKey === 'hilosDaemonEnvMismatch') {
            if (Array.isArray(data.rows)) rows = data.rows
          }
        }
        return {
          answered,
          row: rows.find((row) => deliveryObject(row).rowKey === key),
        }
      })
      .toMatchObject({
        answered: true,
        row: { rowKey: key, slots: { key: { key, kind: 'diverged' } } },
      })
    for (const frame of frames.slice(after)) {
      const data = deliveryObject(deliveryObject(frame.wire).data)
      if (frame.type === 'table_window') {
        expect(data.tableKey).toBe('hilosDaemonEnvMismatch')
      } else {
        const payload = deliveryObject(data.payload)
        for (const field of ['windows', 'refusedWindows']) {
          const keys = Object.keys(deliveryObject(payload[field]))
          expect(
            keys.filter((key) => key !== 'hilosDaemonEnvMismatch'),
          ).toEqual([])
        }
      }
      expect(frame.raw).not.toContain('hilosDaemonEnvDiverged')
      expect(frame.raw).not.toContain('hilosDaemonEnvSourcesDiffer')
    }
  }
  if (silentNodeId !== undefined) {
    const silent = shownByTestId(
      page,
      `hilos-daemon-env-mismatch-cell-${key}-${silentNodeId}`,
    )
    await expect(silent).toHaveAttribute('data-kind', 'unknown')
    await expect(silent).toContainText('?')
  }
  await expect(page.getByTestId('page-error')).toHaveCount(0)
}

/** Full incoming text is retained so privacy checks cover every matching answer. */
export interface DaemonPageFrame {
  type: 'page_response' | 'table_window'
  raw: string
  /** The inner envelope; raw remains the original physical message. */
  wire: unknown
}

/** A complete comparison-page answer retained for privacy checks. */
export type DaemonMismatchFrame = DaemonPageFrame

/** Observe the Node page's complete answer and later data-only updates. */
export function watchDaemonNodeFrames(page: Page) {
  return watchDaemonPageFrames(page, 'hilos_daemon_node')
}

/** A changed browser count reaches this Overview as a data-only live answer. */
export async function expectDaemonNodeLive(
  expect: Expect,
  page: Page,
  frames: readonly DaemonPageFrame[],
  after: number,
): Promise<void> {
  // The existing node source coalesces for 0.5 s, reports within 5 s and fans
  // out within 0.5 s; use the same source bound as daemonSource.ts.
  await expect
    .poll(
      () =>
        frames.slice(after).some((frame) => {
          const data = (
            frame.wire as { data: { payload: Record<string, unknown> } }
          ).data
          return (
            Object.keys(data.payload).length === 1 && 'data' in data.payload
          )
        }),
      { timeout: 10_000 },
    )
    .toBe(true)
  await expect(page.getByTestId('hilos-daemon-node-overview')).toHaveAttribute(
    'data-node-id',
    'standalone',
  )
}

/** One complete answer carries identity, heading and diagnostics without a data action. */
export async function expectDaemonNodeAnswer(
  expect: Expect,
  page: Page,
  frames: readonly DaemonPageFrame[],
): Promise<void> {
  await expect.poll(() => frames.length).toBeGreaterThan(0)
  const first = frames[0].wire as {
    data: {
      payload: {
        data: {
          pageHeadOwner: unknown
          pageTabs: unknown
          node: unknown
          overview: {
            nodeId: string
            uptimeMinutes: number | null
            environmentComparison: {
              divergedKeys: number | null
              sourcesDifferKeys: number | null
            }
          }
        }
      }
    }
  }
  expect(first.data.payload.data.pageHeadOwner).toBeDefined()
  expect(first.data.payload.data.pageTabs).toBeDefined()
  expect(first.data.payload.data.node).toBeDefined()
  const overview = first.data.payload.data.overview
  expect(overview.nodeId).toBe('standalone')
  expect(overview.environmentComparison).toMatchObject({
    divergedKeys: null,
    sourcesDifferKeys: null,
  })
  await expect(
    page.getByTestId('hilos-daemon-environment-differences'),
  ).toHaveText('—')
  // A live report can replace the first answer during these checks, including
  // at a minute boundary. Compare the screen with the latest received report.
  await expect
    .poll(async () => {
      const latest = frames.at(-1)!.wire as typeof first
      const minutes = latest.data.payload.data.overview.uptimeMinutes
      const reported =
        minutes === null
          ? '—'
          : minutes >= 1440
            ? `${Math.floor(minutes / 1440)} d ${Math.floor((minutes % 1440) / 60)} h ${minutes % 60} min`
            : minutes >= 60
              ? `${Math.floor(minutes / 60)} h ${minutes % 60} min`
              : `${minutes} min`
      const displayed = (
        await page.getByTestId('hilos-daemon-uptime').innerText()
      ).trim()
      return { reported, displayed, matches: displayed === reported }
    })
    .toMatchObject({ matches: true })
}

/** Arm before navigation; observe only the mismatch page, including its later recovery. */
export function watchDaemonMismatchFrames(page: Page): {
  frames: DaemonMismatchFrame[]
  stop(): void
} {
  return watchDaemonPageFrames(page, 'hilos_daemon_env_mismatch')
}

/** Arm before the first socket opens; retain the node's actual schedule reports. */
export function watchDaemonCronFrames(page: Page): {
  frames: DaemonPageFrame[]
  stop(): void
} {
  return watchDaemonPageFrames(page, 'hilos_daemon_cron')
}

/** Observe complete page/window answers without changing the browser connection. */
function watchDaemonPageFrames(
  page: Page,
  pageKey: string,
): {
  frames: DaemonPageFrame[]
  stop(): void
} {
  const frames: DaemonPageFrame[] = []
  const observer = watchSocketFrames(page, ({ wire, raw }) => {
    if (raw === undefined) return
    if (wire === undefined) throw new Error('Invalid Daemon socket evidence')
    const frame = wire as { type?: string; data?: { page?: string } } | null
    if (
      (frame?.type === 'page_response' || frame?.type === 'table_window') &&
      frame.data?.page === pageKey
    ) {
      frames.push({ type: frame.type, raw, wire })
    }
  })
  return { frames, stop: observer.stop }
}

/** The strict browser projection, read from its nested page-response boundary. */
export function readDaemonSectionOverview(frame: SocketFrame) {
  const wire = deliveryObject(frame.wire)
  if (wire.type !== 'page_response')
    throw new Error('Expected a page response.')
  const payload = deliveryObject(deliveryObject(wire.data).payload)
  return daemonOverviewSchema
    .nullable()
    .parse(deliveryObject(payload.data).daemon)
}

/** A concrete initial source tuple requires the stand's first full picture. */
export async function expectDaemonPlacementSourceReady(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon_placement')
  await expect(page.getByTestId('hilos-daemon-placement-view')).toContainText(
    'All placed',
    { timeout: DAEMON_SOURCE_TIMEOUT_MS },
  )
  await expect(shownByTestId(page, DAEMON_PLACEMENT_CELL)).toBeVisible()
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** With a reported source, one initial reply carries head, overview and window. */
export async function expectDaemonPlacementAnswer(
  expect: Expect,
  page: Page,
  frames: readonly SocketFrame[],
  clustered = false,
) {
  const replies = frames.filter((frame) => {
    const wire = deliveryObject(frame.wire)
    return (
      wire.type === 'page_response' &&
      deliveryObject(wire.data).page === 'hilos_daemon_placement'
    )
  })
  const complete = replies.filter(
    (frame) =>
      deliveryObject(deliveryObject(deliveryObject(frame.wire).data).payload)
        .windows !== undefined,
  )
  expect(complete).toHaveLength(1)
  const first = replies[0]!
  expect(first).toBe(complete[0])
  expect(first.failure).toBeUndefined()
  const payload = deliveryObject(
    deliveryObject(deliveryObject(first.wire).data).payload,
  )
  const data = deliveryObject(payload.data)
  expect(data.pageHeadOwner).toEqual({
    page: 'hilos_daemon',
    label: 'Daemon',
    lead: 'Nodes and their processes, with agent placement across them.',
    icon: 'bi-cpu',
  })
  expect(data.pageBreadcrumb).toEqual([
    { page: 'hilos', label: 'Hilos' },
    { page: 'hilos_daemon', label: 'Daemon' },
  ])
  expect(data.pageTabs).toEqual([
    { page: 'hilos_daemon', label: 'Overview' },
    { page: 'hilos_daemon_placement', label: 'Agent placement' },
    ...(clustered
      ? [{ page: 'hilos_daemon_env_mismatch', label: 'Env mismatch' }]
      : []),
  ])
  expect(data).not.toHaveProperty('database')
  const windows = deliveryObject(payload.windows)
  expect(Object.keys(windows)).toEqual(['hilosDaemonPlacement'])
  const window = deliveryObject(windows.hilosDaemonPlacement)
  expect(window.rows).toEqual(
    expect.arrayContaining([
      expect.objectContaining({ rowKey: DAEMON_PLACEMENT_TYPE }),
    ]),
  )
  const overview = readDaemonSectionOverview(first)
  expect(overview).not.toBeNull()
  expect(overview!.entryPoints).not.toBeNull()
  expect(overview!.clustered).toBe(clustered)
  await expectDaemonPage(expect, page, 'hilos_daemon_placement')
  await expectDaemonSectionHead(
    expect,
    page,
    'hilos_daemon_placement',
    clustered,
  )
  await expect(page).toHaveURL(new RegExp(`${DAEMON_PLACEMENT_PATH}$`))
  await expect(page.getByTestId('hilos-daemon-database')).toHaveCount(0)
  await expect(page.getByTestId('hilos-daemon-nodes-view')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  return overview!
}

/** Cold navigation cannot reveal any previous head, body or actionable table. */
export async function expectDaemonPlacementPending(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'loading',
  )
  await expect(page.getByTestId('hilos-page-skeleton')).toBeVisible()
  await expect(
    page.getByTestId('hilos-page-skeleton').getByRole('status'),
  ).toHaveText('Loading…')
  await expect(page.getByTestId('hilos-page-departure')).toHaveAttribute(
    'inert',
    '',
  )
  await expect(page.getByTestId('hilos-page-departure')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  for (const id of [
    'hilos-page-head',
    'hilos-breadcrumb',
    'hilos-daemon-placement-view',
    'hilos-viewport-table',
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(0)
}

/** A cold document subscribes only to Placement; later table traffic stays there. */
export async function expectDaemonPlacementRequests(
  expect: Expect,
  log: DeliveryFrameLog,
): Promise<void> {
  const socket = log.sockets()
  const sent = log.sent
    .filter((frame) => frame.socket === socket)
    .map((frame) => deliveryObject(frame.wire))
  expect(
    sent
      .filter((wire) => wire.type === 'page_subscribe')
      .map((wire) => wire.page),
  ).toEqual(['hilos_daemon_placement'])
  expect(sent.filter((wire) => wire.type === 'action')).toEqual([])
  await expect
    .poll(() =>
      log.sent.some((frame) => {
        const wire = deliveryObject(frame.wire)
        return (
          frame.socket === socket &&
          wire.type === 'table_rendered' &&
          wire.tableKey === 'hilosDaemonPlacement'
        )
      }),
    )
    .toBe(true)
  expect(
    log.sent
      .filter((frame) => frame.socket === socket)
      .map((frame) => deliveryObject(frame.wire))
      .filter(
        (wire) =>
          ['table_viewport', 'table_rendered'].includes(String(wire.type)) &&
          wire.tableKey === 'hilosDaemonPlacement',
      )
      .map((wire) => wire.page),
  ).toEqual(expect.arrayContaining(['hilos_daemon_placement']))
  for (const frame of log.sent.filter((frame) => frame.socket === socket)) {
    const wire = deliveryObject(frame.wire)
    if (wire.tableKey === 'hilosDaemonPlacement')
      expect(wire.page).toBe('hilos_daemon_placement')
  }
}

/** Entry ports and the metrics address come from the actual browser projection. */
export async function expectDaemonEntryPoints(
  expect: Expect,
  page: Page,
  frame: SocketFrame,
) {
  const overview = readDaemonSectionOverview(frame)
  expect(overview?.entryPoints).not.toBeNull()
  const source = overview!.entryPoints!
  expect(overview!.nodes).toContainEqual(
    expect.objectContaining({ nodeId: source.nodeId, online: true }),
  )
  await expect(page.getByTestId('hilos-daemon-entry-points')).toContainText(
    'Browsers',
  )
  await expect(page.getByTestId('hilos-daemon-entry-command-port')).toHaveText(
    source.commandPort === null ? '—' : `:${source.commandPort}`,
  )
  await expect(page.getByTestId('hilos-daemon-entry-http-port')).toHaveText(
    source.httpPort === null ? '—' : `:${source.httpPort}`,
  )
  await expect(page.getByTestId('hilos-daemon-entry-metrics')).toHaveAttribute(
    'href',
    `${DAEMON_ROOT_PATH}/${source.nodeId}/http`,
  )
  return source
}

/** Section table naming, keyboard search/cell operation and textual placement state. */
export async function expectDaemonSectionAccessibility(
  expect: Expect,
  page: Page,
  clustered = false,
): Promise<void> {
  await expectDaemonSectionHead(
    expect,
    page,
    'hilos_daemon_placement',
    clustered,
  )
  const table = page.getByRole('table', { name: 'Daemon', exact: true })
  await expect(table).toBeVisible()
  const headingId = await page
    .getByTestId('hilos-page-head-title')
    .getAttribute('id')
  expect(headingId).toBeTruthy()
  await expect(table).toHaveAttribute('aria-labelledby', headingId!)
  const search = page.getByTestId('hilos-table-search')
  await expect(search).toHaveRole('searchbox')
  await expect(search).toHaveAccessibleName('Search by agent id…')
  await search.focus()
  await expect(search).toBeFocused()
  const cell = shownByTestId(page, DAEMON_PLACEMENT_CELL)
  await expect(cell).toHaveRole('button')
  await expect(cell).toHaveAccessibleName(
    `${DAEMON_PLACEMENT_TYPE} on standalone: 1 instances`,
  )
  await cell.focus()
  await expect(cell).toBeFocused()
  await cell.press('Enter')
  await expect(cell).toHaveAttribute('aria-expanded', 'true')
  await expect(
    shownByTestId(page, 'hilos-daemon-placement-selection'),
  ).toContainText(DAEMON_PLACEMENT_TYPE)
  await cell.press('Space')
  await expect(cell).toHaveAttribute('aria-expanded', 'false')
  await expect(
    page.getByTestId('hilos-daemon-placement-selection'),
  ).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-daemon-placement-unplaced')
      .locator('..')
      .getByRole('paragraph'),
  ).toHaveText('All placed')
  await typeInto(search, DAEMON_PLACEMENT_TYPE)
  await expect(
    table.locator(
      'tbody > tr[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
    ),
  ).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(cell).toHaveAttribute('aria-expanded', 'false')
  await search.fill('')
  await expect
    .poll(() =>
      table
        .locator(
          'tbody > tr[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
        )
        .count(),
    )
    .toBeGreaterThan(1)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** Search one observed instance, keeping whole-daemon totals and node columns. */
export async function searchDaemonPlacement(
  expect: Expect,
  page: Page,
  windows: Awaited<ReturnType<typeof watchTableSearch>>,
) {
  const cell = shownByTestId(page, DAEMON_PLACEMENT_CELL)
  await cell.focus()
  await cell.press('Enter')
  const selection = page
    .getByRole('table')
    .getByTestId('hilos-daemon-placement-selection')
  await expect(selection).toContainText(DAEMON_PLACEMENT_TYPE)
  const ids = (await selection.locator('code').allTextContents()).slice(2)
  expect(ids).toEqual([DAEMON_PLACEMENT_TYPE])
  const totals = await Promise.all(
    DAEMON_PLACEMENT_TOTALS.map((total) =>
      page.getByTestId(`hilos-daemon-placement-${total}`).innerText(),
    ),
  )
  const columns = await page
    .getByRole('table')
    .getByRole('columnheader')
    .allTextContents()
  const search = page.getByTestId('hilos-table-search')
  const searched = await windows.mark()
  await typeInto(search, ids[0]!)
  await windows.renderedSettled(expect, searched, { search: ids[0]! }, [
    DAEMON_PLACEMENT_TYPE,
  ])
  await expect(
    page
      .getByRole('table')
      .locator(
        'tbody > tr[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
      ),
  ).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(selection).toHaveCount(0)
  await cell.focus()
  await cell.press('Enter')
  await expect(selection).toContainText(ids[0]!)
  await expectUnfilteredTotals(expect, page, totals)
  expect(
    await page.getByRole('table').getByRole('columnheader').allTextContents(),
  ).toEqual(columns)
  return ids[0]!
}

/** New page ownership starts with an empty search and no retained cell. */
export async function expectDaemonPlacementReset(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectDaemonPage(expect, page, 'hilos_daemon_placement')
  await expectDaemonSectionHead(expect, page, 'hilos_daemon_placement')
  await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
  await expect(
    page.getByTestId('hilos-daemon-placement-selection'),
  ).toHaveCount(0)
  await expect(shownByTestId(page, DAEMON_PLACEMENT_CELL)).toHaveAttribute(
    'aria-expanded',
    'false',
  )
}

/**
 * Retain native table/row/search/selection while a real connection fact changes.
 * The Vue carrier additionally observes its controller and applied epoch through
 * watchDaemonSource/watchTableContinuity; this check works for every SDK.
 */
export async function watchDaemonPlacementLive(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  expectedConnections: number,
) {
  const connections = (frame: SocketFrame) =>
    readDaemonSectionOverview(frame)?.nodes.find(
      (node) => node.nodeId === 'standalone',
    )?.standing?.connections
  const latest = () =>
    log.packets
      .flatMap((packet) => packet.frames)
      .filter((frame) => {
        const wire = deliveryObject(frame.wire)
        return (
          wire.type === 'page_response' &&
          deliveryObject(wire.data).page === 'hilos_daemon_placement'
        )
      })
      .at(-1)!
  // Reload and previous tests can leave a last report from before sockets closed.
  // Establish the real source baseline before the independent visitor opens.
  await expect
    .poll(() => connections(latest()), { timeout: DAEMON_SOURCE_TIMEOUT_MS })
    .toBe(expectedConnections)
  const handles = await Promise.all(
    [
      'hilos-viewport-table',
      `hilos-table-row-${DAEMON_PLACEMENT_TYPE}`,
      'hilos-table-search',
      DAEMON_PLACEMENT_CELL,
      'hilos-daemon-placement-selection',
    ].map((id) => shownByTestId(page, id).elementHandle()),
  )
  expect(handles.every((handle) => handle !== null)).toBe(true)
  const document = await watchClientDocument(page)
  const start = log.packets.length
  const sent = log.sent.length
  return {
    async changed(): Promise<void> {
      await expect
        .poll(
          () =>
            log.packets
              .slice(start)
              .flatMap((packet) => packet.frames)
              .some((frame) => {
                const wire = deliveryObject(frame.wire)
                if (
                  wire.type !== 'page_response' ||
                  deliveryObject(wire.data).page !== 'hilos_daemon_placement'
                )
                  return false
                return connections(frame) === expectedConnections + 1
              }),
          { timeout: DAEMON_SOURCE_TIMEOUT_MS },
        )
        .toBe(true)
      // Observe retention after socket dispatch and the SDK's render have run.
      await nextClientPaint(page)
      const frames = log.packets.slice(start).flatMap((packet) => packet.frames)
      for (const frame of frames) {
        const wire = deliveryObject(frame.wire)
        const data = deliveryObject(wire.data)
        if (data.page !== 'hilos_daemon_placement') continue
        expect(frame.failure).toBeUndefined()
        expect([
          'table_window',
          'page_resubscribe',
          'subscription_page_error',
        ]).not.toContain(wire.type)
        if (wire.type === 'page_response') {
          expect(data).not.toHaveProperty('requestId')
          expect(deliveryObject(data.payload)).not.toHaveProperty('windows')
        }
      }
      expect(
        log.sent
          .slice(sent)
          .map((frame) => deliveryObject(frame.wire))
          .filter((wire) =>
            [
              'page_subscribe',
              'page_update_subscription',
              'table_viewport',
            ].includes(String(wire.type)),
          ),
      ).toEqual([])
      await document.unchanged()
      for (const handle of handles)
        expect(await handle!.evaluate((node) => node.isConnected)).toBe(true)
      await expect(page.getByTestId('hilos-table-search')).toHaveValue(
        DAEMON_PLACEMENT_TYPE,
      )
      await expect(shownByTestId(page, DAEMON_PLACEMENT_CELL)).toHaveAttribute(
        'aria-expanded',
        'true',
      )
      await expect(shownByTestId(page, DAEMON_PLACEMENT_CELL)).toBeFocused()
      await expect(
        shownByTestId(page, 'hilos-daemon-placement-selection'),
      ).toContainText(DAEMON_PLACEMENT_TYPE)
    },
    async dispose(): Promise<void> {
      for (const handle of handles) await handle?.dispose()
      await document.dispose()
    },
  }
}

/** All Placement traffic belongs to its page, and Overview never carries its window. */
export function expectDaemonPlacementOwnership(
  expect: Expect,
  log: DeliveryFrameLog,
): void {
  for (const frame of log.sent) {
    const wire = deliveryObject(frame.wire)
    if (wire.tableKey === 'hilosDaemonPlacement')
      expect(wire.page).toBe('hilos_daemon_placement')
  }
  for (const frame of log.packets.flatMap((packet) => packet.frames)) {
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    if (data.tableKey === 'hilosDaemonPlacement')
      expect(data.page).toBe('hilos_daemon_placement')
    if (wire.type === 'page_response' && data.page === 'hilos_daemon')
      expect(
        deliveryObject(deliveryObject(data.payload).windows),
      ).not.toHaveProperty('hilosDaemonPlacement')
  }
}

/** Root uses the same live source tuple and leaves all Placement windows to its tab. */
export async function expectDaemonRootEntryPoints(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
) {
  const replies = log.packets
    .flatMap((packet) => packet.frames)
    .filter((frame) => {
      const wire = deliveryObject(frame.wire)
      return (
        wire.type === 'page_response' &&
        deliveryObject(wire.data).page === 'hilos_daemon'
      )
    })
  expect(replies.length).toBeGreaterThan(0)
  expectDaemonPlacementOwnership(expect, log)
  return expectDaemonEntryPoints(expect, page, replies.at(-1)!)
}
