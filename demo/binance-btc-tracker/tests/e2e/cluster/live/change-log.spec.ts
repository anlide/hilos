import { expect, test, type Page } from '@playwright/test'

import {
  createTestUser,
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_FEED_PATH,
  DAEMON_ROOT_PATH,
  chooseChangeLogFeedTable,
  deliveryObject,
  dismissToasts,
  expectChangeLogFeedRow,
  expectChangeLogReceiptWriter,
  expectChangeLogValues,
  expectClusterDaemonNodePage,
  expectDaemonWorkerAgent,
  expectFreshChangeLogReceiptWriter,
  readMonopolisticWorker,
  watchSocketFrames,
  type MonopolisticWorker,
  type SocketFrame,
  clickSubmit,
  typeInto,
  gotoPage,
  createTestAdmin,
} from '../../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../../framework/frontend/scripts/adminGrant.mjs'
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

interface ReceiptRead {
  master: string
  status: 'notRun' | 'reading' | 'passed'
  receipt?: { rowKey: string; receiptId: string }
  entryId?: string | null
  headerText?: string
}

// FIXME(F-016, 2026-10-18): parked on the owner's word (HOTFIX), without a diagnosis.
// Red in 2 of the 6 full runs since 1268 (1271, 1277, both on seven lanes) and passed only
// on its retry in 1274; green alone on the same sha each time (1272, 1275, 1278). Every
// failure is a browser sign-up after the daemon phase lost the leader and recreated s1: the
// code is accepted, the registration never completes. On 1274 and 1277 the auth throttle
// also went silent for 8-9 s and refused the first registration. Node journals:
// runs/<n>/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on the shared test box.
test(
  'cluster change log: one remote block receipt reads the same through every master',
  { tag: '@quarantine:F-016' },
  async ({ browser }) => {
    // Two registrations, a remote writer and three fresh reads outlive the base cap.
    test.slow()
    const before = await clusterInspect(CLUSTER_FOLLOWER)
    await expectLeaderUnmoved(before)
    const adminPage = await newMasterPage(browser, CLUSTER_FOLLOWER)
    let personPage: Page | undefined
    let beforeBlock: ClusterView | undefined
    let afterBlock: ClusterView | undefined
    const bootstrapFrames: SocketFrame[] = []
    const bootstrapObserver = watchSocketFrames(adminPage, (frame) => {
      const wire = frame.wire
      if (
        wire !== null &&
        typeof wire === 'object' &&
        'data' in wire &&
        wire.data !== null &&
        typeof wire.data === 'object' &&
        'page' in wire.data &&
        wire.data.page === 'hilos_daemon_workers'
      ) {
        bootstrapFrames.push(frame)
      }
    })
    const bootstrap: {
      nodeId: string
      reportKnown: boolean
      agentReported: boolean
      freshWorkersRead: boolean
      worker?: MonopolisticWorker
    } = {
      nodeId: CLUSTER_LEADER,
      reportKnown: false,
      agentReported: false,
      freshWorkersRead: false,
    }
    let writerId: string | undefined
    let writerNode: string | undefined
    let settledWriterNode: string | undefined
    let failure: string | undefined
    const firstRead: ReceiptRead = {
      master: CLUSTER_FOLLOWER,
      status: 'notRun',
    }
    const masterReads: ReceiptRead[] = CLUSTER_MASTERS.map((master) => ({
      master,
      status: 'notRun',
    }))
    try {
      const adminId = await createTestAdmin(expect, adminPage)
      const actorLabel = await adminPage.getByTestId('self-user').innerText()
      const workersPath = `${DAEMON_ROOT_PATH}/${CLUSTER_LEADER}/workers`
      await gotoPage(expect, adminPage, workersPath)
      await expect(adminPage).toHaveURL(workersPath)
      await expect(adminPage.getByTestId('hilos-page-head-title')).toHaveText(
        CLUSTER_LEADER,
      )
      const processReports = () =>
        bootstrapFrames
          .filter(
            (frame) => deliveryObject(frame.wire).type === 'page_response',
          )
          .map((frame) => {
            expect(frame.failure).toBeUndefined()
            return deliveryObject(
              deliveryObject(deliveryObject(frame.wire).data).payload,
            ).data
          })
      await expect
        .poll(() => processReports().at(-1))
        .toMatchObject({
          node: { clustered: true, state: 'leader', role: 'master' },
          processesReported: true,
        })
      bootstrap.reportKnown = true
      // An initially unknown roster has no previous window to return to.
      // Read Workers once afresh after its independent node report is known.
      if (deliveryObject(processReports()[0]).processesReported !== true) {
        await gotoPage(expect, adminPage, workersPath)
        bootstrap.freshWorkersRead = true
      }
      await expectClusterDaemonNodePage(expect, adminPage, {
        pageKey: 'hilos_daemon_workers',
        nodeId: CLUSTER_LEADER,
      })
      await expectDaemonWorkerAgent(expect, adminPage, 'hilos_change_log')
      bootstrap.agentReported = true
      bootstrap.worker = await readMonopolisticWorker(
        adminPage,
        'hilos_change_log',
        (page) => gotoPage(expect, page, workersPath),
        async (predicate) => {
          await expect.poll(predicate).toBe(true)
        },
      )

      personPage = await newMasterPage(browser, CLUSTER_FOLLOWER)
      const person = await createTestUser(expect, personPage)
      // Address the lazy owner without changing this normal account's admin flag (P-612).
      await setAdmin(person.userId, false)
      writerId = `hilos_user:${person.userId}`

      await gotoPage(expect, adminPage, `/hilos/user/${person.userId}`)
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
      expect(beforeBlock).toMatchObject({
        leaderId: before.leaderId,
        term: before.term,
      })
      writerNode = await placementNode(writerId)
      expect(CLUSTER_SLAVES).toContain(writerNode)
      expect(writerNode).not.toBe(pageHostLeaderId)

      await clickSubmit(expect, adminPage.getByTestId('hilos-user-block-open'))
      await expect(adminPage.getByTestId('modal')).toBeVisible()
      await clickSubmit(
        expect,
        adminPage.getByTestId('hilos-user-lifecycle-confirm'),
      )
      await expect(adminPage.getByTestId('modal')).toBeHidden()

      await expect
        .poll(async () => {
          afterBlock = await clusterInspect(CLUSTER_LEADER)
          return afterBlock.placements.some(
            (row) => row.agentId === writerId && row.state === 'started',
          )
        })
        .toBe(true)
      expect(afterBlock).toMatchObject({
        leaderId: before.leaderId,
        term: before.term,
      })
      settledWriterNode = await placementNode(writerId)
      expect(CLUSTER_SLAVES).toContain(settledWriterNode)
      expect(settledWriterNode).not.toBe(pageHostLeaderId)
      await dismissToasts(adminPage)

      await readBlockReceipt(
        adminPage,
        adminId,
        actorLabel,
        person.userId,
        firstRead,
      )
      for (const read of masterReads) {
        await onMaster(adminPage, read.master)
        await adminPage.reload()
        await expect(adminPage.getByTestId('conn-state')).toHaveText(
          'connected',
        )
        await readBlockReceipt(
          adminPage,
          adminId,
          actorLabel,
          person.userId,
          read,
          firstRead,
        )
      }
    } catch (error) {
      failure = String(error)
      throw error
    } finally {
      const finalInspect = await clusterInspect(CLUSTER_LEADER).catch(
        (inspectionError: unknown) => ({ error: String(inspectionError) }),
      )
      try {
        const lastReceiptDetail = await adminPage
          .getByTestId('hilos-change-log-detail')
          .allTextContents()
          .catch((error: unknown) => [String(error)])
        await test.info().attach('change-log-cluster-evidence', {
          body: JSON.stringify(
            {
              bootstrap,
              bootstrapFrames,
              before,
              beforeBlock,
              afterBlock,
              finalInspect,
              writerId,
              writerNode,
              settledWriterNode,
              firstRead,
              masterReads,
              lastReceiptDetail,
              failure,
            },
            null,
            2,
          ),
          contentType: 'application/json',
        })
      } finally {
        bootstrapObserver.stop()
        try {
          await Promise.all([
            adminPage.context().close(),
            personPage?.context().close(),
          ])
        } finally {
          expect(finalInspect).toMatchObject({
            leaderId: before.leaderId,
            term: before.term,
          })
          await expectLeaderUnmoved(before)
        }
      }
    }
  },
)

/** Read one block receipt afresh through the current master's socket. */
async function readBlockReceipt(
  page: Page,
  adminId: number,
  actorLabel: string,
  userId: number,
  read: ReceiptRead,
  pinned?: ReceiptRead,
): Promise<void> {
  read.status = 'reading'
  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  await chooseChangeLogFeedTable(page, 'hilos_user')
  await typeInto(page.getByTestId('hilos-table-search'), `#${adminId}`)
  read.receipt = await expectChangeLogFeedRow(
    expect,
    page,
    `hilos_user #${userId} · updated`,
    CHANGE_LOG_BLOCK_ACTION,
  )
  if (pinned !== undefined) expect(read.receipt).toEqual(pinned.receipt)
  const { rowKey, receiptId } = read.receipt
  await expectFreshChangeLogReceiptWriter(
    expect,
    page,
    rowKey,
    receiptId,
    `hilos_user:${userId}`,
  )
  read.headerText = await expectChangeLogReceiptWriter(
    expect,
    page,
    actorLabel,
    CHANGE_LOG_BLOCK_ACTION,
    `hilos_user:${userId}`,
  )
  const entry = page.getByTestId(/^hilos-change-log-entry-[0-9]+$/)
  await expect(entry).toHaveCount(1)
  read.entryId = await entry.getAttribute('data-id')
  await expectChangeLogValues(expect, page, 'block', '0', '1')
  if (pinned !== undefined) {
    expect(read.entryId).toBe(pinned.entryId)
    expect(read.headerText).toBe(pinned.headerText)
  }
  read.status = 'passed'
}
