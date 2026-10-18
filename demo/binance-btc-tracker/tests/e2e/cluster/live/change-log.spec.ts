import { expect, test, type Page } from '@playwright/test'

import {
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogFeedRow,
  expectChangeLogReceiptOpen,
  expectChangeLogReceiptWriter,
  expectChangeLogValues,
  shownByTestId,
} from '../../../../../../framework/frontend/e2e/index.js'
import { setAdmin, signUpAdmin } from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
  onMaster,
  placementNode,
  type ClusterView,
} from '../../helpers/cluster.js'
import { gotoPage } from '../../helpers/page.js'
import { clickSubmit, signUpPerson, typeInto } from '../../helpers/session.js'

// FIXME(F-016, 2026-10-18): parked on the owner's word (HOTFIX), without a diagnosis.
// Red in 2 of the 6 full runs since 1268 (1271, 1277, both on seven lanes) and passed only
// on its retry in 1274; green alone on the same sha each time (1272, 1275, 1278). Every
// failure is a browser sign-up after the daemon phase lost the leader and recreated s1: the
// code is accepted, the registration never completes. On 1274 and 1277 the auth throttle
// also went silent for 8-9 s and refused the first registration. Node journals:
// runs/<n>/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on nova-de.
test.fixme('cluster change log: one remote block receipt reads the same through every master', async ({
  browser,
}) => {
  // Two registrations, a remote writer and three fresh reads outlive the base cap.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const adminPage = await newMasterPage(browser, CLUSTER_FOLLOWER)
  let personPage: Page | undefined
  let beforeBlock: ClusterView | undefined
  let afterBlock: ClusterView | undefined
  try {
    const adminId = await signUpAdmin(adminPage)
    const actorLabel = await adminPage.getByTestId('self-user').innerText()
    personPage = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const person = await signUpPerson(personPage)
    // Address the lazy owner without changing this normal account's admin flag (P-612).
    await setAdmin(person.userId, false)
    const writerId = `hilos_user:${person.userId}`

    await gotoPage(adminPage, `/hilos/user/${person.userId}`)
    // hilos_index has LEADER placement and no POLICY placement row.
    const pageHost = await clusterInspect(CLUSTER_LEADER)
    await expectLeaderUnmoved(pageHost)
    const pageHostLeaderId = pageHost.leaderId
    // Placements are the leader's view; a follower always reports an empty list.
    await expect
      .poll(async () => {
        beforeBlock = await clusterInspect(CLUSTER_LEADER)
        return beforeBlock.placements.some(
          (row) => row.agentId === writerId && row.state === 'started',
        )
      })
      .toBe(true)
    const writerNode = await placementNode(writerId)
    expect(CLUSTER_SLAVES).toContain(writerNode)
    expect(writerNode).not.toBe(pageHostLeaderId)

    await clickSubmit(adminPage.getByTestId('hilos-user-block-open'))
    await expect(adminPage.getByTestId('modal')).toBeVisible()
    await clickSubmit(adminPage.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(adminPage.getByTestId('modal')).toBeHidden()

    await expect
      .poll(async () => {
        afterBlock = await clusterInspect(CLUSTER_LEADER)
        return afterBlock.placements.some(
          (row) => row.agentId === writerId && row.state === 'started',
        )
      })
      .toBe(true)
    const settledWriterNode = await placementNode(writerId)
    expect(CLUSTER_SLAVES).toContain(settledWriterNode)
    expect(settledWriterNode).not.toBe(pageHostLeaderId)
    await dismissToasts(adminPage)

    const receipt = await readBlockReceipt(
      adminPage,
      adminId,
      actorLabel,
      person.userId,
    )
    for (const node of CLUSTER_MASTERS) {
      await onMaster(adminPage, node)
      await adminPage.reload()
      await expect(adminPage.getByTestId('conn-state')).toHaveText('connected')
      expect(
        await readBlockReceipt(adminPage, adminId, actorLabel, person.userId),
      ).toEqual(receipt)
    }
  } catch (error) {
    const failureInspect = await clusterInspect(CLUSTER_LEADER).catch(
      (inspectionError: unknown) => ({ error: String(inspectionError) }),
    )
    await test.info().attach('change-log-cluster-inspect', {
      body: JSON.stringify(
        { before, beforeBlock, afterBlock, failureInspect },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    throw error
  } finally {
    try {
      await Promise.all([
        adminPage.context().close(),
        personPage?.context().close(),
      ])
    } finally {
      await expectLeaderUnmoved(before)
    }
  }
})

/** Read one block receipt afresh through the current master's socket. */
async function readBlockReceipt(
  page: Page,
  adminId: number,
  actorLabel: string,
  userId: number,
): Promise<{ receiptId: string; entryId: string | null; headerText: string }> {
  await gotoPage(page, CHANGE_LOG_PATH)
  await chooseChangeLogFeedTable(page, 'hilos_user')
  await typeInto(page.getByTestId('hilos-table-search'), `#${adminId}`)
  const { rowKey, receiptId } = await expectChangeLogFeedRow(
    expect,
    page,
    `hilos_user #${userId} · updated`,
    CHANGE_LOG_BLOCK_ACTION,
  )
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  const headerText = await expectChangeLogReceiptWriter(
    expect,
    page,
    actorLabel,
    CHANGE_LOG_BLOCK_ACTION,
    `hilos_user:${userId}`,
  )
  const entry = page.getByTestId(/^hilos-change-log-entry-[0-9]+$/)
  await expect(entry).toHaveCount(1)
  await expectChangeLogValues(expect, page, 'block', '0', '1')

  return { receiptId, entryId: await entry.getAttribute('data-id'), headerText }
}
