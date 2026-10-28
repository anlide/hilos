import { expect, test } from '@playwright/test'

import {
  DAEMON_PLACEMENT_TYPE,
  DAEMON_ROOT_CRUMB,
  DAEMON_ROOT_PATH,
  DAEMON_PLACEMENT_PATH,
  expectDaemonSectionHead,
  expectDaemonPlacementAnswer,
  expectDaemonPlacementSourceReady,
  expectDaemonPlacementPending,
  expectDaemonPlacementRequests,
  expectDaemonRootEntryPoints,
  expectDaemonPlacementOwnership,
  expectDaemonSectionAccessibility,
  searchDaemonPlacement,
  expectDaemonPlacementReset,
  watchDaemonPlacementLive,
  watchTableSearch,
  watchDeliveryFrames,
  holdPageReply,
  expectStandaloneMismatch,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  watchDaemonNodeFrames,
  watchDaemonWebsocketsFrames,
  expectStandaloneWebsockets,
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
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

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
  await grantAdminToSelf(expect, page)

  await gotoPage(expect, page, '/hilos', PAGE_READY)
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
  await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
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
    const placementTab = page.getByTestId(
      'hilos-page-tab-hilos_daemon_placement',
    )
    await placementTab.click()
    await expect(placementTab).toHaveAttribute('aria-current', 'page')
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
    await page.getByTestId('hilos-page-tab-hilos_daemon').click()
    await expect(
      page.getByTestId('hilos-page-tab-hilos_daemon'),
    ).toHaveAttribute('aria-current', 'page')
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
  test(`keeps every addressed Node page free of log links after reload and history as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
    context,
  }) => {
    // Each tab opens its own live subscription, followed by a full reload.
    test.slow()
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(expect, page)
    const reports = watchDaemonNodeFrames(page)
    await gotoPage(expect, page, DAEMON_NODE_PATH, PAGE_READY)
    await expectStandaloneNodeOverview(expect, page, { showLogLinks: false })
    const beforeLive = reports.frames.length
    const visitor = await context.newPage()
    try {
      await gotoPage(expect, visitor, '/', PAGE_READY)
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

for (const viewer of [false, true]) {
  test(`cold Placement answers once, reloads and resets on history while live data preserves search as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
    context,
    browser,
    baseURL,
  }) => {
    // Held cold reply, reload, a real connection report and history are separate round trips.
    test.slow()
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(expect, page)
    // The source may be null until its first full picture; prepare that fact
    // in the setup tab, leaving the cold document's subscription untouched.
    await gotoPage(expect, page, DAEMON_PLACEMENT_PATH, PAGE_READY)
    await expectDaemonPlacementSourceReady(expect, page)
    const cold = await context.newPage()
    const log = watchDeliveryFrames(cold)
    const windows = await watchTableSearch(cold, {
      page: 'hilos_daemon_placement',
      tableKey: 'hilosDaemonPlacement',
    })
    const held = await holdPageReply(cold, 'hilos_daemon_placement')
    const navigation = gotoPage(expect, cold, DAEMON_PLACEMENT_PATH, PAGE_READY)
    const completed = navigation.catch((error: unknown) => error)
    const frames = () =>
      log.packets
        .flatMap((packet) => packet.frames)
        .filter((frame) => frame.socket === log.sockets())
    try {
      await held.received
      await expectDaemonPlacementPending(expect, cold)
      held.release()
      await navigation
      const initial = await expectDaemonPlacementAnswer(expect, cold, frames())
      expect(initial.entryPoints?.nodeId).toBe('standalone')
      await expectDaemonPlacementRequests(expect, log)
      if (viewer)
        await expect(cold.getByTestId('view-mode-banner')).toBeVisible()
      await cold.reload()
      await expectDaemonPage(expect, cold, 'hilos_daemon_placement')
      await expectDaemonPlacementAnswer(expect, cold, frames())
      await expectDaemonPlacementRequests(expect, log)
      await expectDaemonSectionAccessibility(expect, cold)
      await expect(
        cold.getByTestId(/^hilos-daemon-(node|worker|agent)-log/),
      ).toHaveCount(0)
      await searchDaemonPlacement(expect, cold, windows)
      const live = await watchDaemonPlacementLive(expect, cold, log, 2)
      const visitor = await browser.newPage({
        baseURL,
        ignoreHTTPSErrors: true,
      })
      try {
        await gotoPage(expect, visitor, '/', PAGE_READY)
        await live.changed()
      } finally {
        await visitor.context().close()
        await live.dispose()
      }
      await cold.getByTestId('hilos-page-tab-hilos_daemon').press('Enter')
      await expectDaemonPage(expect, cold, 'hilos_daemon')
      await expectDaemonSectionHead(expect, cold, 'hilos_daemon')
      await expect(cold.getByTestId('hilos-daemon-placement-view')).toHaveCount(
        0,
      )
      const source = await expectDaemonRootEntryPoints(expect, cold, log)
      expect(source.nodeId).toBe('standalone')
      await cold.goBack()
      await expectDaemonPlacementReset(expect, cold)
      await cold.goForward()
      await expectDaemonPage(expect, cold, 'hilos_daemon')
      await expectDaemonSectionHead(expect, cold, 'hilos_daemon')
      await cold
        .getByTestId('hilos-page-tab-hilos_daemon_placement')
        .press('Enter')
      await expectDaemonPlacementReset(expect, cold)
      // The source's metrics link is a real addressed HTTP page.
      await cold.getByTestId('hilos-page-tab-hilos_daemon').press('Enter')
      await expectDaemonPage(expect, cold, 'hilos_daemon')
      await cold.getByTestId('hilos-daemon-entry-metrics').press('Enter')
      await expectDaemonPage(expect, cold, 'hilos_daemon_http_server')
      await expect(cold).toHaveURL(`${DAEMON_ROOT_PATH}/${source.nodeId}/http`)
      await expect(cold.getByTestId('hilos-daemon-http-address')).toHaveText(
        new RegExp(`:${source.httpPort}\\s*$`),
      )
      await gotoPage(expect, cold, '/hilos/daemon/env-mismatch', PAGE_READY)
      await expectStandaloneMismatch(expect, cold)
      await cold
        .getByTestId('hilos-page-tab-hilos_daemon_placement')
        .press('Enter')
      await expectDaemonPlacementReset(expect, cold)
      expectDaemonPlacementOwnership(expect, log)
    } finally {
      held.dispose()
      await completed
      await test.info().attach('daemon-section-cold-history-live', {
        body: JSON.stringify({
          packets: log.packets,
          sent: log.sent,
          search: await windows.read(),
        }),
        contentType: 'application/json',
      })
      log.stop()
      await cold.close()
    }
  })
}

for (const viewer of [false, true]) {
  test(`WebSockets smoke keeps Ecommerce log links absent as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
  }) => {
    test.slow() // Registration and direct/reloaded page subscriptions are real round trips.
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(expect, page)
    const reports = watchDaemonWebsocketsFrames(page)
    try {
      await gotoPage(expect, page, `${DAEMON_NODE_PATH}/websockets`, PAGE_READY)
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        showLogLinks: false,
      })
      reports.frames.length = 0
      await page.reload()
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        showLogLinks: false,
      })
      await expect(
        page.getByTestId(/^hilos-daemon-(node|worker|agent)-log/),
      ).toHaveCount(0)
      await page.getByTestId('hilos-page-tab-hilos_daemon_http_server').click()
      await expectDaemonPage(expect, page, 'hilos_daemon_http_server')
      await page.getByTestId('hilos-page-tab-hilos_daemon_websockets').click()
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        showLogLinks: false,
      })
    } finally {
      reports.stop()
    }
  })
}
