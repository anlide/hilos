import { expect, test } from '@playwright/test'

import {
  DAEMON_PLACEMENT_TYPE,
  DAEMON_ROOT_CRUMB,
  DAEMON_ROOT_PATH,
  DAEMON_WORKERS_CHILD,
  expectClusterDaemonNodePage,
  expectClusterOverview,
  expectDaemonPage,
  expectStandaloneEnvironment,
  expectStandaloneOverview,
  expectStandaloneWorkers,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

// spec-owner: demo — proves ecommerce-shop mounts the Daemon section without logs.

// Short smoke test of the Daemon section in ecommerce-shop:
// mounts without logs, proves card navigation, root cold load,
// and workers, agents, and environment for the chosen nodeId.
// Accepts DAEMON_SMOKE_NODES for standalone vs cluster execution.
const DATABASE_HOST = 'ecommerce-shop-mysql-test'

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
    await expectStandaloneOverview(expect, page, DATABASE_HOST, {
      showLogLinks: false,
    })
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
    await expectStandaloneOverview(expect, page, DATABASE_HOST, {
      showLogLinks: false,
    })
  }

  // Absence of the node log link on the root overview.
  await expect(page.getByTestId('hilos-daemon-node-log')).toHaveCount(0)

  // Choose the target nodeId (slave in cluster, standalone otherwise).
  const targetNodeId = SMOKE_NODES
    ? (SMOKE_NODES.find((id) => !id.startsWith('m')) ?? SMOKE_NODES[0])
    : 'standalone'

  const nodeButton = page.getByTestId(`hilos-daemon-node-${targetNodeId}`)
  await nodeButton.click()
  await expect(page.getByTestId('hilos-daemon-inspector')).toHaveAttribute(
    'data-node-id',
    targetNodeId,
  )

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

  await page.getByTestId(DAEMON_ROOT_CRUMB).click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

  // Agents
  if (SMOKE_NODES) {
    await page.getByTestId(`hilos-daemon-node-${targetNodeId}`).click()
  }
  const agentsLink = page.getByTestId('hilos-admin-child-hilos_daemon_agents')
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
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Agents')
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

  await page.getByTestId(DAEMON_ROOT_CRUMB).click()
  await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))

  // Environment
  if (SMOKE_NODES) {
    await page.getByTestId(`hilos-daemon-node-${targetNodeId}`).click()
  }
  const envLink = page.getByTestId('hilos-admin-child-hilos_daemon_env')
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
