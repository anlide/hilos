import { writeFileSync } from 'node:fs'

import { expect, test as base } from '../../test.js'
import {
  cleanupFixtures,
  clusterPlacementNode,
  expectClusterLeaderUnchanged,
  expectClusterSingleAgents,
  gotoPage,
  inspectClusterNode,
  readClusterBrowserStand,
  readClusterCarry,
  readClusterNodeJournal,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  expectWarehouseTotals,
  markCatalogWindow,
  openCatalogAdmin,
  productStockRow,
  setStockUnits,
} from '../catalog.js'

// spec-owner: demo — the shop's stock library and seeded warehouse totals.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
const stand = readClusterBrowserStand()
const totalsAfterLive = {
  1: { productCount: 19, unitCount: 546 },
  2: { productCount: 16, unitCount: 361 },
  3: { productCount: 12, unitCount: 181 },
}

test('stock survives the worker loss and a second edit reaches the other master', async ({
  browser,
  cleanup,
}, info) => {
  for (const [node, path] of Object.entries(stand.nodeLogs)) {
    cleanup.defer(async () => {
      // Hold the bytes before attachment. The carrier can rename the file at any time.
      let body: Buffer | undefined
      await expect(() => {
        body = readClusterNodeJournal(path)
      }).toPass({ timeout: Number(process.env.CLUSTER_FAILOVER_TIMEOUT_MS) })
      await info.attach(`${node}-daemon.log`, {
        body,
        contentType: 'text/plain',
      })
    })
  }
  const { previousStockHost, productId, warehouseId, expectedWarehouseTotals } =
    readClusterCarry()
  expect(previousStockHost).toBe(process.env.CLUSTER_STOPPED)
  expect(['s1', 's2']).toContain(previousStockHost)
  expect(productId).toBe(17)
  expect(warehouseId).toBe(3)
  expect(expectedWarehouseTotals).toEqual(totalsAfterLive)
  const survivor = previousStockHost === 's1' ? 's2' : 's1'
  const timeout = Number(process.env.CLUSTER_FAILOVER_TIMEOUT_MS)
  expect(timeout).toBeGreaterThan(0)
  test.setTimeout(info.timeout + timeout)

  const leader = await inspectClusterNode(stand, stand.leader)
  const follower = await inspectClusterNode(stand, stand.follower)
  await info.attach('master-inspect.json', {
    body: JSON.stringify({
      [stand.leader]: leader,
      [stand.follower]: follower,
    }),
    contentType: 'application/json',
  })
  await test.step('both masters have quorum and the surviving worker hosts stock', async () => {
    expect([...stand.masters].sort()).toEqual(['m1', 'm2'])
    expect(leader).toMatchObject({
      localNodeId: stand.leader,
      consensusRole: 'leader',
      hasQuorum: true,
    })
    expect(follower).toMatchObject({
      localNodeId: stand.follower,
      consensusRole: 'follower',
      hasQuorum: true,
      term: leader.term,
      leaderId: stand.leader,
    })
    // A killed worker can leave a start without a matching stop in its journal.
    const nodeLogs = Object.fromEntries(
      Object.entries(stand.nodeLogs).filter(
        ([node]) => node !== previousStockHost,
      ),
    )
    await expectClusterSingleAgents(
      expect.configure({ timeout }),
      { ...stand, nodeLogs },
      ['stock_library'],
    )
    expect(await clusterPlacementNode(expect, stand, 'stock_library')).toBe(
      survivor,
    )
  })

  const observer = await openCatalogAdmin(
    browser,
    stand,
    stand.follower,
    cleanup,
    {
      info,
      name: 'warehouses.png',
    },
  )
  await gotoPage(expect, observer.page, '/hilos/app/warehouses')
  for (const [id, totals] of Object.entries(totalsAfterLive)) {
    await expectWarehouseTotals(observer.page, Number(id), totals)
  }
  const author = await openCatalogAdmin(browser, stand, stand.leader, cleanup, {
    info,
    name: 'stock.png',
  })
  expect(author.userId).not.toBe(observer.userId)
  await gotoPage(expect, author.page, `/hilos/app/product/${productId}/stock`)
  const row = productStockRow(author.page, Number(warehouseId))
  await expect(row.getByRole('cell', { name: '7', exact: true })).toBeVisible()

  await test.step('Set stock updates the original warehouse window on the follower', async () => {
    const unchanged = await markCatalogWindow(observer)
    await setStockUnits(author, Number(warehouseId), 8)
    await expectWarehouseTotals(observer.page, 3, {
      productCount: 12,
      unitCount: 182,
    })
    await expectWarehouseTotals(observer.page, 1, totalsAfterLive[1])
    await expectWarehouseTotals(observer.page, 2, totalsAfterLive[2])
    await unchanged()
    await expectClusterLeaderUnchanged(expect, stand, leader)
  })
  const proof = process.env.CLUSTER_AFTER_LOSS_PROOF_FILE
  if (proof) writeFileSync(proof, JSON.stringify({ verified: true }), 'utf8')
})
