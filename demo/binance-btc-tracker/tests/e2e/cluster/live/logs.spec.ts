import { expect, test } from '@playwright/test'

import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import {
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  CLUSTER_FOLLOWER,
  newMasterPage,
  nodeHost,
} from '../../helpers/cluster.js'
import {
  appendLogLines,
  FOLLOWED_STREAM,
  logMarker,
  TAIL_ARRIVAL_TIMEOUT_MS,
} from '../../helpers/logs.js'
import { gotoPage, PAGE_READY } from '../../helpers/page.js'

test('the logs overview names every member node', async ({ browser }) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(page)
    await gotoPage(page, '/hilos/logs', PAGE_READY)
    for (const node of [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]) {
      await expect(page.getByTestId(`hilos-logs-node-${node}`)).toBeVisible()
    }
  } finally {
    await page.context().close()
  }
})

test('a slave live file followed from a follower master receives a line appended on that slave', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    // The command round trip and remote artifact delivery outlive the base cap.
    test.slow()
    const slave = CLUSTER_SLAVES[0]
    await grantAdminToSelf(page)
    await gotoPage(
      page,
      `/hilos/logs/view/${slave}/live/${FOLLOWED_STREAM}`,
      PAGE_READY,
    )
    await expect(page.getByTestId('hilos-log-tail-badge')).toBeVisible()

    const marker = logMarker('cluster-follow')
    expect(await appendLogLines(marker, 1, nodeHost(slave))).toBe(1)
    await expect(
      page.getByTestId('hilos-log-entry').filter({ hasText: `${marker} #1` }),
    ).toBeVisible({
      timeout: TAIL_ARRIVAL_TIMEOUT_MS,
    })
  } finally {
    await page.context().close()
  }
})
