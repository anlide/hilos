import type { Page } from '@playwright/test'

import { expect, test as base } from '../../test.js'
import { moneyFieldLabel } from '../../../../frontend/src/money/money.js'
import { activeClusterAgents } from '../../../../../../framework/frontend/e2e/cluster.js'
import {
  PAGE_READY,
  cleanupFixtures,
  expectClusterLeaderUnchanged,
  expectPageReady,
  gotoPage,
  inspectClusterNode,
  newClusterMasterPage,
  readClusterBrowserStand,
  shownByTestId,
  typeInto,
  watchClusterMasterUpgrades,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  markCatalogWindow,
  openCatalogAdmin,
  saveCatalogEdit,
  watchCatalogPage,
} from '../catalog.js'

// spec-owner: demo — the shop product's live price, viewers and worker placement.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
const stand = readClusterBrowserStand()
const slug = 'morning-meadow'
const shopPath = `/product/${slug}`
const overviewPath = '/hilos/product/1'
const viewers = (page: Page) => shownByTestId(page, 'product-viewer-count')

test('a storefront across two masters keeps its price and viewers live while products span both workers', async ({
  browser,
  cleanup,
}) => {
  const admin = await openCatalogAdmin(browser, stand, stand.leader, cleanup)
  await gotoPage(expect, admin.page, overviewPath, PAGE_READY)
  await expect(admin.page.getByRole('heading', { level: 1 })).toHaveText(
    'Morning meadow',
  )
  await expect(viewers(admin.page)).toHaveText('Nobody is looking at it now')
  const overviewUnchanged = await markCatalogWindow(admin)

  const guest = await newClusterMasterPage(browser, stand, stand.follower)
  cleanup.defer(() => guest.context().close())
  const storefront = await watchCatalogPage(
    guest,
    stand,
    stand.follower,
    cleanup,
  )
  await gotoPage(expect, guest, shopPath, PAGE_READY)
  const price = guest.getByTestId('shop-product-price')
  await expect(price).toHaveText('₴1,850')
  await expect(viewers(admin.page)).toHaveText('1 person is looking at it now')
  await expect(viewers(guest)).toHaveText('Nobody else is looking at it now')
  const storefrontUnchanged = await markCatalogWindow(storefront)

  await test.step('deliver the saved price to the open storefront', async () => {
    const edit = admin.page.getByRole('dialog', { name: 'Edit product' })
    const field = edit.getByRole('textbox', { name: moneyFieldLabel('Price') })
    const open = admin.page.getByTestId('admin-product-edit-open')
    const savePrice = async (value: string) => {
      await typeInto(field, value)
      await saveCatalogEdit(
        admin,
        edit.getByTestId('admin-product-edit-submit'),
        'product_update',
      )
      await expect(edit).toBeHidden()
    }
    await open.click()
    const original = await field.inputValue()
    cleanup.defer(async () => {
      await gotoPage(expect, admin.page, overviewPath, PAGE_READY)
      await open.click()
      if ((await field.inputValue()) !== original) await savePrice(original)
      await expect(price).toHaveText('₴1,850')
    })
    await savePrice('2 100.50')
    await expect(price).toHaveText('₴2,100.50')
    await storefrontUnchanged()
  })

  await test.step('count a second guest joining on the leader and then leaving', async () => {
    const second = await newClusterMasterPage(browser, stand, stand.leader)
    cleanup.defer(() => second.context().close())
    const upgrades = await watchClusterMasterUpgrades(
      expect,
      second,
      stand,
      stand.leader,
    )
    try {
      await gotoPage(expect, second, shopPath, PAGE_READY)
      await upgrades.assert()
      await expect(viewers(guest)).toHaveText('1 other is looking at it now')
      await expect(viewers(admin.page)).toHaveText(
        '2 people are looking at it now',
      )
      await storefrontUnchanged()
      await overviewUnchanged()
    } finally {
      await upgrades.stop()
    }
    await second.close()
    await expect(viewers(guest)).toHaveText('Nobody else is looking at it now')
    await expect(viewers(admin.page)).toHaveText(
      '1 person is looking at it now',
    )
    await storefrontUnchanged()
    await overviewUnchanged()
  })

  await test.step('keep distinct products subscribed until both workers hold one', async () => {
    const active = [`product:${slug}`]
    const productsSpanWorkers = async () => {
      const view = await inspectClusterNode(stand, stand.leader)
      await expectClusterLeaderUnchanged(expect, stand, view)
      let copies
      try {
        copies = Object.entries(stand.nodeLogs).flatMap(([node, path]) =>
          activeClusterAgents(path, active).map((agentId) => ({
            node,
            agentId,
          })),
        )
      } catch (error) {
        return String(error)
      }
      const hosts = new Set<string>()
      for (const agentId of active) {
        const placements = view.placements.filter((p) => p.agentId === agentId)
        const live = copies.filter((copy) => copy.agentId === agentId)
        if (
          placements.length !== 1 ||
          placements[0].state !== 'started' ||
          live.length !== 1 ||
          live[0].node !== placements[0].nodeId
        ) {
          return { agentId, placements, live }
        }
        hosts.add(live[0].node)
      }
      return hosts.size === 2 && hosts.has('s1') && hosts.has('s2')
    }
    const { placements } = await inspectClusterNode(stand, stand.leader)
    const occupied = (node: string) =>
      placements.filter(
        (p) =>
          p.nodeId === node && (p.state === 'placing' || p.state === 'started'),
      ).length
    // Both workers have 64 slots. Two more products after covering the gap span a tie.
    const additional = Math.abs(occupied('s1') - occupied('s2')) + 2
    const tabs: Page[] = []
    try {
      for (let id = 2; id < 2 + additional; id++) {
        const tab = await admin.page.context().newPage()
        tabs.push(tab)
        await gotoPage(expect, tab, `/hilos/product/${id}`, PAGE_READY)
        await tab.getByRole('link', { name: 'See in the shop' }).click()
        await expect(tab).toHaveURL((url) =>
          /^\/product\/[a-z0-9-]+$/.test(url.pathname),
        )
        await expectPageReady(expect, tab)
        const agentId = `product:${new URL(tab.url()).pathname.slice('/product/'.length)}`
        active.push(agentId)
        if ((await productsSpanWorkers()) === true) return
      }
      await expect.poll(productsSpanWorkers).toBe(true)
    } finally {
      await Promise.all(tabs.map((tab) => tab.close()))
    }
  })
})
