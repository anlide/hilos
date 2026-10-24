import type { Expect, Page } from '@playwright/test'
import { shownByTestId } from './table.js'
import { fitsInScrollport } from './geometry.js'
import { watchSocketFrames } from './socket.js'
import type {
  HilosDaemonNodeHeading,
  HilosDaemonNodeOverviewData,
} from '../core/src/admin/daemon/index.js'

/**
 * Daemon section checks all three kits must meet alike. Tests, their order and
 * navigation stay in each demo's spec.
 */
export const DAEMON_ROOT_PATH = '/hilos/daemon'
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

/** Root-only picture: links, processes, database and the local placement switch. */
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
  await expect(page.getByTestId('hilos-daemon-placement-tab')).toBeEnabled()
  await expect(page.getByTestId('hilos-daemon-placement-tab')).toHaveAttribute(
    'aria-pressed',
    'false',
  )
}

/** Shared entity head and real links on all six addressed Node pages. */
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
  }: { key: string; remoteNodeId: string; silentNodeId?: string },
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
