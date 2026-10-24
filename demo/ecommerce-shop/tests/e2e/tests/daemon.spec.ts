import { expect, test } from '@playwright/test'

import {
  DAEMON_PLACEMENT_TYPE,
  DAEMON_ROOT_CRUMB,
  DAEMON_ROOT_PATH,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  watchDaemonNodeFrames,
  expectDaemonNodeLive,
  expectDaemonNodeHead,
  expectStandaloneNodeOverview,
  DAEMON_WORKERS_CHILD,
  expectClusterDaemonNodePage,
  expectClusterOverview,
  expectDaemonPage,
  expectStandaloneEnvironment,
  expectStandaloneOverview,
  expectStandaloneWorkers,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

// spec-owner: demo — proves ecommerce-shop mounts the Daemon section without logs.

// Short smoke test of the Daemon section in ecommerce-shop:
// mounts without logs, proves card navigation, root cold load,
// and workers, agents, and environment for the chosen nodeId.
// Accepts DAEMON_SMOKE_NODES for standalone vs cluster execution.
const DATABASE_HOST = 'ecommerce-shop-mysql-test'

test.afterEach(() => setAdminViewMode(false))

const SMOKE_NODES = process.env.DAEMON_SMOKE_NODES
  ? process.env.DAEMON_SMOKE_NODES.split(',')
      .map((nodeId) => nodeId.trim())
      .filter(Boolean)
  : null

test('mounts the Daemon section, navigates from the card and cold loads without log links', async ({
  page,
}) => {
  test.slow()
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos', PAGE_READY)
  const card = page.getByTestId('dashboard-card-hilos_daemon')
  await expect(card).toHaveAttribute('href', DAEMON_ROOT_PATH)
  await card.click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

  if (SMOKE_NODES) {
    const masters = SMOKE_NODES.filter((id) => id.startsWith('m'))
    const slaves = SMOKE_NODES.filter((id) => !id.startsWith('m'))
    const leaderId = masters[0] ?? SMOKE_NODES[0]
    await expectClusterOverview(expect, page, {
      masters,
      slaves,
      leaderId,
      term: 1,
      silentNodeIds: [],
    })
  } else {
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
  }

  // Cold load of the Daemon section root.
  await gotoPage(page, DAEMON_ROOT_PATH, PAGE_READY)
  if (SMOKE_NODES) {
    const masters = SMOKE_NODES.filter((id) => id.startsWith('m'))
    const slaves = SMOKE_NODES.filter((id) => !id.startsWith('m'))
    const leaderId = masters[0] ?? SMOKE_NODES[0]
    await expectClusterOverview(expect, page, {
      masters,
      slaves,
      leaderId,
      term: 1,
      silentNodeIds: [],
    })

    // Placement checks: Node agent on each declared node, collector policy copy, and page agent.
    const placementTab = page.getByTestId('hilos-daemon-placement-tab')
    await placementTab.click()
    await expect(placementTab).toHaveAttribute('aria-pressed', 'true')
    for (const nodeId of SMOKE_NODES) {
      await expect(
        shownByTestId(
          page,
          `hilos-daemon-placement-cell-hilos_daemon_node-${nodeId}`,
        ),
      ).toHaveAccessibleName(`hilos_daemon_node on ${nodeId}: 1 instances`)
    }
    const collector = shownByTestId(
      page,
      'hilos-table-row-hilos_daemon_collector',
    ).or(shownByTestId(page, 'hilos-table-card-hilos_daemon_collector'))
    await expect(collector).toContainText('Policy-placed')
    const pageAgent = shownByTestId(page, 'hilos-table-row-hilos_daemon').or(
      shownByTestId(page, 'hilos-table-card-hilos_daemon'),
    )
    await expect(pageAgent).toBeVisible()
    await page.getByTestId('hilos-daemon-nodes-tab').click()
    await expect(page.getByTestId('hilos-daemon-nodes-tab')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  } else {
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
  }

  // Absence of the node log link on the root overview.
  await expect(page.getByTestId('hilos-daemon-node-log')).toHaveCount(0)

  // Choose the target nodeId (slave in cluster, standalone otherwise).
  const targetNodeId = SMOKE_NODES
    ? (SMOKE_NODES.find((id) => !id.startsWith('m')) ?? SMOKE_NODES[0])
    : 'standalone'

  const nodeButton = page.getByTestId(`hilos-daemon-node-${targetNodeId}`)
  await nodeButton.click()
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node', targetNodeId, {
    showLogLinks: false,
  })
  if (!SMOKE_NODES)
    await expectStandaloneNodeOverview(expect, page, { showLogLinks: false })

  // Workers
  const workersLink = page.getByTestId(DAEMON_WORKERS_CHILD)
  await expect(workersLink).toHaveAttribute(
    'href',
    `${DAEMON_ROOT_PATH}/${targetNodeId}/workers`,
  )
  await workersLink.click()
  await expect(page).toHaveURL(
    new RegExp(`${DAEMON_ROOT_PATH}/${targetNodeId}/workers$`),
  )
  await expectDaemonPage(expect, page, 'hilos_daemon_workers')
  if (SMOKE_NODES) {
    await expectClusterDaemonNodePage(expect, page, {
      pageKey: 'hilos_daemon_workers',
      nodeId: targetNodeId,
    })
  } else {
    await expectStandaloneWorkers(expect, page)
  }
  await expect(page.getByTestId(/^hilos-daemon-worker-log-/)).toHaveCount(0)
  await expectDaemonNodeHead(
    expect,
    page,
    'hilos_daemon_workers',
    targetNodeId,
    { showLogLinks: false },
  )

  await page.getByTestId(DAEMON_ROOT_CRUMB).click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

  // Agents
  await page.getByTestId(`hilos-daemon-node-${targetNodeId}`).click()
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node', targetNodeId, {
    showLogLinks: false,
  })
  const agentsLink = page.getByTestId('hilos-page-tab-hilos_daemon_agents')
  await expect(agentsLink).toHaveAttribute(
    'href',
    `${DAEMON_ROOT_PATH}/${targetNodeId}/agents`,
  )
  await agentsLink.click()
  await expect(page).toHaveURL(
    new RegExp(`${DAEMON_ROOT_PATH}/${targetNodeId}/agents$`),
  )
  await expectDaemonPage(expect, page, 'hilos_daemon_agents')
  if (SMOKE_NODES) {
    await expectClusterDaemonNodePage(expect, page, {
      pageKey: 'hilos_daemon_agents',
      nodeId: targetNodeId,
    })
  } else {
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'standalone',
    )
    await expect(
      shownByTestId(page, /^hilos-table-(row|card)-/),
    ).not.toHaveCount(0)
    await expect(
      shownByTestId(
        page,
        `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
      ),
    ).toBeEnabled()
  }
  await expect(page.getByTestId(/^hilos-daemon-agent-log-/)).toHaveCount(0)
  await expectDaemonNodeHead(
    expect,
    page,
    'hilos_daemon_agents',
    targetNodeId,
    { showLogLinks: false },
  )

  await page.getByTestId(DAEMON_ROOT_CRUMB).click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

  // Environment
  await page.getByTestId(`hilos-daemon-node-${targetNodeId}`).click()
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node', targetNodeId, {
    showLogLinks: false,
  })
  const envLink = page.getByTestId('hilos-page-tab-hilos_daemon_env')
  await expect(envLink).toHaveAttribute(
    'href',
    `${DAEMON_ROOT_PATH}/${targetNodeId}/env`,
  )
  await envLink.click()
  await expect(page).toHaveURL(
    new RegExp(`${DAEMON_ROOT_PATH}/${targetNodeId}/env$`),
  )
  await expectDaemonPage(expect, page, 'hilos_daemon_env')
  if (SMOKE_NODES) {
    await expectClusterDaemonNodePage(expect, page, {
      pageKey: 'hilos_daemon_env',
      nodeId: targetNodeId,
    })
  } else {
    await expectStandaloneEnvironment(expect, page)
  }

  await page.getByTestId(DAEMON_ROOT_CRUMB).click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
})

for (const viewer of [false, true]) {
  test(`keeps all six addressed Node pages free of log links after reload and history as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
    context,
  }) => {
    // Each tab opens its own live subscription, followed by a full reload.
    test.slow()
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(page)
    const reports = watchDaemonNodeFrames(page)
    await gotoPage(page, DAEMON_NODE_PATH, PAGE_READY)
    await expectStandaloneNodeOverview(expect, page, { showLogLinks: false })
    const beforeLive = reports.frames.length
    const visitor = await context.newPage()
    try {
      await gotoPage(visitor, '/', PAGE_READY)
      await expectDaemonNodeLive(expect, page, reports.frames, beforeLive)
      await expectStandaloneNodeOverview(expect, page, { showLogLinks: false })
    } finally {
      await visitor.close()
    }
    for (const tab of DAEMON_NODE_TABS) {
      const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
      await link.focus()
      await link.press('Enter')
      await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
      await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
        showLogLinks: false,
      })
      await page.reload()
      await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
        showLogLinks: false,
      })
      await expect(
        page.getByTestId(/^hilos-daemon-(worker|agent)-log-/),
      ).toHaveCount(0)
      if (tab.key !== 'hilos_daemon_node') {
        await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
        await expectStandaloneNodeOverview(expect, page, {
          showLogLinks: false,
        })
        await page.goBack()
        await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
          showLogLinks: false,
        })
        await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
        await expectStandaloneNodeOverview(expect, page, {
          showLogLinks: false,
        })
      }
    }
  })
}
