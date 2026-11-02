import { expect, test as base, type Page } from '@playwright/test'

import {
  cleanupFixtures,
  DAEMON_ROOT_PATH,
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  readClusterBrowserStand,
  reportDaemonNodeUntil,
  shiftClusterNodeClock,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import { fireCronRule } from '../../../../../../framework/frontend/scripts/daemonMoments.mjs'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  clusterInspect,
  newMasterPage,
  nodeHost,
} from '../../helpers/cluster.js'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
test.use({ timezoneId: 'Asia/Kuala_Lumpur' })

const stand = readClusterBrowserStand()
const shiftedNode = CLUSTER_SLAVES[0]!
const clockCheckRule = 'daemon/hilos_cluster_clock_check'

/** Read current leadership rather than treating the harness's startup role as truth. */
async function currentLeader(): Promise<string> {
  const { leaderId } = await clusterInspect(CLUSTER_FOLLOWER)
  expect(leaderId, 'A recognized cluster leader').not.toBeNull()
  expect(CLUSTER_MASTERS).toContain(leaderId)
  return leaderId!
}

/** Read only the signed offset in the visible line, never its measured-at moment. */
async function clockOffset(page: Page): Promise<number> {
  const clock = page.getByTestId(`hilos-daemon-clock-${shiftedNode}`)
  expect(await clock.isVisible()).toBe(true)
  const text = (await clock.allTextContents()).join(' ')
  const match = /^Clock: ([+-]?\d+) ms\b/.exec(text)
  expect(match, `A measured clock offset: ${text}`).not.toBeNull()
  return Number(match![1])
}

/** A short check of delivered recovery, used by the scenario and its teardown. */
async function expectRecoveredClock(page: Page): Promise<void> {
  expect(Math.abs(await clockOffset(page))).toBeLessThanOrEqual(10)
  expect(
    await page
      .getByTestId(`hilos-daemon-clock-warning-${shiftedNode}`)
      .isVisible(),
  ).toBe(false)
}

test('daemon clock gap reaches a follower browser and recovers after the slave shift clears', async ({
  browser,
  cleanup,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  cleanup.defer(() => page.context().close())
  // The fixture's finally has its own teardown budget after a failed or timed-out
  // body, keeps the browser alive, and attempts context closure even if reset fails.
  cleanup.defer(async () => {
    expect(await shiftClusterNodeClock(stand, shiftedNode, 0)).toBe(0)
    const leaderId = await currentLeader()
    await fireCronRule(clockCheckRule, { host: nodeHost(leaderId) })
    const restored = await page.context().newPage()
    await gotoPage(expect, restored, DAEMON_ROOT_PATH, PAGE_READY)
    await reportDaemonNodeUntil(expect, () => expectRecoveredClock(restored), {
      host: nodeHost(leaderId),
    })
  })

  await grantAdminToSelf(expect, page)
  expect(
    await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
  ).toBe('Asia/Kuala_Lumpur')
  const leaderId = await currentLeader()
  expect(leaderId, 'The browser enters through another master').not.toBe(
    CLUSTER_FOLLOWER,
  )
  await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)

  await test.step('the slave offset and threshold warning reach Overview through the leader', async () => {
    expect(await shiftClusterNodeClock(stand, shiftedNode, 800)).toBe(800)
    await fireCronRule(clockCheckRule, { host: nodeHost(leaderId) })
    await reportDaemonNodeUntil(
      expect,
      async () => {
        const offset = await clockOffset(page)
        expect(offset).toBeGreaterThanOrEqual(790)
        expect(offset).toBeLessThanOrEqual(810)
        const warning = page.getByTestId(
          `hilos-daemon-clock-warning-${shiftedNode}`,
        )
        expect(await warning.isVisible()).toBe(true)
        expect((await warning.allTextContents()).join(' ')).toContain('300 ms')
        const reference = page.getByTestId(`hilos-daemon-clock-${leaderId}`)
        expect(await reference.isVisible()).toBe(true)
        expect(await reference.allTextContents()).toEqual(['Clock: reference'])
        for (const node of [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]) {
          if (node === leaderId || node === shiftedNode) continue
          expect(
            await page.getByTestId(`hilos-daemon-clock-${node}`).isVisible(),
          ).toBe(true)
          expect(
            await page
              .getByTestId(`hilos-daemon-clock-warning-${node}`)
              .isVisible(),
          ).toBe(false)
        }
      },
      { host: nodeHost(leaderId) },
    )
  })

  await test.step('a cold slave page carries the same warning in its head', async () => {
    await gotoPage(
      expect,
      page,
      `${DAEMON_ROOT_PATH}/${shiftedNode}`,
      PAGE_READY,
    )
    const warning = page.getByTestId('hilos-daemon-node-clock-warning')
    await expect(warning).toBeVisible()
    await expect(warning).toContainText('300 ms')
  })

  await test.step('clearing the shift removes the live head warning and restores the offset', async () => {
    expect(await shiftClusterNodeClock(stand, shiftedNode, 0)).toBe(0)
    await fireCronRule(clockCheckRule, { host: nodeHost(leaderId) })
    await reportDaemonNodeUntil(
      expect,
      async () => {
        expect(
          await page.getByTestId('hilos-daemon-node-clock-warning').isVisible(),
        ).toBe(false)
      },
      { host: nodeHost(leaderId) },
    )
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await reportDaemonNodeUntil(expect, () => expectRecoveredClock(page), {
      host: nodeHost(leaderId),
    })
  })
})
