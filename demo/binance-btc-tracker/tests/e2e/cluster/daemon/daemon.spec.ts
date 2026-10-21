import { expect, test, type Page } from '@playwright/test'

import {
  DAEMON_ROOT_PATH,
  DAEMON_ROOT_CRUMB,
  expectClusterOverview,
  expectClusterDaemonNodePage,
  expectClusterDaemonCronSchedule,
  expectDaemonWorkerAgent,
  expectClusterLeaderPlacement,
  expectClusterEnvironmentMismatch,
  shownByTestId,
  watchDaemonCronFrames,
  watchDaemonMismatchFrames,
  createSocketFrameReader,
  type SocketFrame,
} from '../../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import {
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  clusterInspect,
  newMasterPage,
} from '../../helpers/cluster.js'
import {
  daemonClusterEnvironment,
  markDaemonNodeLogs,
  requestLeaderKill,
} from '../../helpers/daemonCluster.js'
import {
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
} from '../../helpers/page.js'

const LEADER_AGENTS = ['hilos_index', 'hilos_daemon']
const RESTART_AGENT = 'hilos_log_store'

// A host fault cannot be replayed against the same stand.
test.describe.configure({ retries: 0 })

test('cluster daemon: foreign node pages, remote restart, environment mismatch and leader loss', async ({
  browser,
}) => {
  // Remote reports, a real restart and recovery on four original tabs exceed one UI round trip.
  test.slow()
  const remote = daemonClusterEnvironment()
  // Both existing followers keep their contexts. Only the one that stays follower
  // after the election counts as the source of the recovery proof.
  const candidates: Array<{
    nodeId: string
    pages: ReturnType<typeof observeRecovery>[]
    privacy: ReturnType<typeof watchDaemonMismatchFrames>
    privacyMark: number
  }> = []
  try {
    const followers = CLUSTER_MASTERS.filter((node) => node !== CLUSTER_LEADER)
    const before = await clusterInspect(followers[0])
    expect(before.leaderId).toBe(CLUSTER_LEADER)
    expect(before.term).not.toBeNull()
    if (before.term === null)
      throw new Error('Initial master has no consensus term')
    await test.info().attach('daemon-inspect-before', {
      body: JSON.stringify(before),
      contentType: 'application/json',
    })
    const initialPicture = {
      masters: CLUSTER_MASTERS,
      slaves: CLUSTER_SLAVES,
      leaderId: CLUSTER_LEADER,
      term: before.term,
      silentNodeIds: [],
    }
    // Initial fan-out includes the existing five-second node report coalescing window.
    const reports = expect.configure({ timeout: test.info().timeout })
    for (const nodeId of followers) {
      const first = await newMasterPage(browser, nodeId)
      const pages = [observeRecovery(first)]
      const mismatch = await first.context().newPage()
      const privacy = watchDaemonMismatchFrames(mismatch)
      candidates.push({ nodeId, pages, privacy, privacyMark: 0 })
      await grantAdminToSelf(first)
      await gotoPage(first, DAEMON_ROOT_PATH, PAGE_READY)
      await expectClusterOverview(reports, first, initialPicture)
      if (nodeId === followers[0]) {
        const nodePage = await first.context().newPage()
        const cron = watchDaemonCronFrames(nodePage)
        try {
          await gotoPage(nodePage, DAEMON_ROOT_PATH, PAGE_READY)
          await expectClusterOverview(reports, nodePage, initialPicture)
          await nodePage
            .getByTestId(`hilos-daemon-node-${remote.nodeId}`)
            .click()
          await expect(
            nodePage.getByTestId('hilos-daemon-inspector'),
          ).toHaveAttribute('data-node-id', remote.nodeId)
          for (const [child, path] of [
            ['workers', 'workers'],
            ['agents', 'agents'],
            ['cron', 'cron'],
            ['http_server', 'http'],
            ['env', 'env'],
          ]) {
            const pageKey = `hilos_daemon_${child}`
            const link = nodePage.getByTestId(`hilos-admin-child-${pageKey}`)
            await expect(link).toHaveAttribute(
              'href',
              `${DAEMON_ROOT_PATH}/${remote.nodeId}/${path}`,
            )
            await link.click()
            await expectClusterDaemonNodePage(reports, nodePage, {
              pageKey,
              nodeId: remote.nodeId,
            })
            if (child === 'workers') {
              await expectDaemonWorkerAgent(reports, nodePage, RESTART_AGENT)
            } else if (child === 'agents') {
              const search = nodePage.getByTestId('hilos-table-search')
              await search.fill('')
              await search.pressSequentially(RESTART_AGENT, { delay: 10 })
              const row = shownByTestId(
                nodePage,
                `hilos-table-row-${RESTART_AGENT}`,
              )
              await expect(row).toBeVisible()
              await shownByTestId(
                nodePage,
                `hilos-daemon-agent-restart-${RESTART_AGENT}`,
              ).click()
              await expect(
                nodePage.getByTestId('hilos-daemon-restart-agent'),
              ).toHaveText(RESTART_AGENT)
              await expect(
                nodePage.getByTestId('hilos-daemon-restart-node'),
              ).toHaveText(remote.nodeId)
              await expect(
                nodePage.getByTestId('hilos-daemon-restart-cli'),
              ).toHaveText(
                `php cli.php agent:restart ${RESTART_AGENT} --node=${remote.nodeId}`,
              )
              const confirm = nodePage.getByTestId(
                'hilos-daemon-restart-confirm',
              )
              await confirm.scrollIntoViewIfNeeded()
              await expect(confirm).toBeVisible()
              await expect(confirm).toBeEnabled()
              await confirm.focus()
              const journals = markDaemonNodeLogs()
              try {
                await confirm.click()
                await expect(nodePage.getByTestId('modal')).toHaveCount(0)
                const stopped = `Agent '${RESTART_AGENT}' stopped on worker #`
                const started = `Agent '${RESTART_AGENT}' started on worker #`
                await reports
                  .poll(() => {
                    const tail = journals.tails()[remote.nodeId]
                    const stop = tail.indexOf(stopped)
                    return (
                      stop >= 0 &&
                      tail.indexOf(started, stop + stopped.length) > stop
                    )
                  })
                  .toBe(true)
                await expect(row).toBeVisible()
                await reports(
                  nodePage.getByTestId(
                    `hilos-daemon-agent-restarting-${RESTART_AGENT}`,
                  ),
                ).toHaveCount(0)
              } finally {
                try {
                  const tails = journals.tails()
                  await test.info().attach('daemon-remote-restart-journals', {
                    body: JSON.stringify(tails),
                    contentType: 'application/json',
                  })
                  for (const [node, tail] of Object.entries(tails)) {
                    if (node !== remote.nodeId) {
                      expect(tail).not.toContain(
                        `Agent '${RESTART_AGENT}' stopped on worker #`,
                      )
                      expect(tail).not.toContain(
                        `Agent '${RESTART_AGENT}' started on worker #`,
                      )
                    }
                  }
                } finally {
                  journals.close()
                }
              }
            } else if (child === 'cron') {
              await expectClusterDaemonCronSchedule(
                reports,
                nodePage,
                cron.frames,
                { agentId: RESTART_AGENT, ruleName: 'log-rotation' },
              )
            } else if (child === 'http_server') {
              // This stand binds HTTP_STATUS_HOST/PORT on every member. The heading
              // and node-addressed route above identify whose listener is reported.
              await expect(
                nodePage.getByTestId('hilos-daemon-http-address'),
              ).toHaveText('0.0.0.0:8090')
              await expect(
                shownByTestId(nodePage, 'hilos-table-row-GET/%2Fstatus'),
              ).toBeVisible()
            } else if (child === 'env') {
              const attention = nodePage.getByTestId(
                'hilos-table-filter-attention',
              )
              await expect(attention).not.toBeChecked()
              await attention.focus()
              await attention.press('Space')
              await expect(attention).toBeChecked()
              await attention.focus()
              await attention.press('Space')
              await expect(attention).not.toBeChecked()
              const search = nodePage.getByTestId('hilos-table-search')
              await search.fill('')
              await search.pressSequentially(remote.envKey, { delay: 10 })
              const value = shownByTestId(
                nodePage,
                `hilos-table-row-${remote.envKey}`,
              )
              await expect(value).toContainText(remote.envValue)
              await expect(value).toContainText('Launch environment')
              await search.fill('')
              await search.pressSequentially('DB_PASSWORD', { delay: 10 })
              await expect(
                shownByTestId(nodePage, 'hilos-table-row-DB_PASSWORD'),
              ).toContainText(/Set · \d+ chars/)
              await expect(nodePage.locator('body')).not.toContainText(
                'hilos_pass',
              )
            }
            await nodePage.getByTestId(DAEMON_ROOT_CRUMB).click()
            await expectClusterOverview(reports, nodePage, initialPicture)
            await nodePage
              .getByTestId(`hilos-daemon-node-${remote.nodeId}`)
              .click()
          }
        } finally {
          cron.stop()
          try {
            await test.info().attach('daemon-cron-reports', {
              body: JSON.stringify(cron.frames),
              contentType: 'application/json',
            })
          } finally {
            await nodePage.close()
          }
        }
      }
      const placement = observeRecovery(await first.context().newPage())
      pages.push(
        placement,
        observeRecovery(mismatch),
        observeRecovery(await first.context().newPage()),
      )
      await gotoPage(placement.page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectClusterOverview(reports, placement.page, initialPicture)
      await placement.page.getByTestId('hilos-daemon-placement-tab').click()
      await gotoPage(mismatch, `${DAEMON_ROOT_PATH}/env-mismatch`, PAGE_READY)
      await expectClusterEnvironmentMismatch(reports, mismatch, {
        key: remote.envKey,
        remoteNodeId: remote.nodeId,
      })
      await gotoPage(pages[3].page, '/hilos', PAGE_READY)
      await expect(
        pages[3].page.getByTestId('dashboard-card-hilos_daemon'),
      ).toBeVisible()
      await expectClusterLeaderPlacement(reports, placement.page, {
        agentTypes: LEADER_AGENTS,
        leaderId: CLUSTER_LEADER,
      })
      await expect(mismatch.locator('body')).not.toContainText(remote.envValue)
      expect(
        privacy.frames.some((frame) => frame.type === 'page_response'),
      ).toBe(true)
      expect(
        mismatchRemoteCells(privacy.frames, remote.envKey, remote.nodeId),
      ).toContainEqual({
        nodeId: remote.nodeId,
        state: 'known',
        type: 'string',
        source: 'process',
        digest: expect.stringMatching(/^[0-9a-f]{16}$/),
      })
      for (const frame of privacy.frames)
        expect(frame.raw).not.toContain(remote.envValue)
      candidates[candidates.length - 1].privacyMark = privacy.frames.length
      for (const observation of pages) {
        await markDocument(observation.page)
        observation.mark()
      }
    }

    const result = await requestLeaderKill()
    await test.info().attach('daemon-host-election', {
      body: JSON.stringify(result),
      contentType: 'application/json',
    })
    expect(result.term).toBeGreaterThan(before.term)
    const survivor = candidates.find(
      (candidate) => candidate.nodeId !== result.leaderId,
    )
    expect(survivor).toBeDefined()
    if (survivor === undefined) throw new Error('No surviving source follower')
    const [diagram, placement, mismatch, dashboard] = survivor.pages
    await expectClusterOverview(reports, diagram.page, {
      ...initialPicture,
      leaderId: result.leaderId,
      term: result.term,
      silentNodeIds: [CLUSTER_LEADER],
    })
    await expectClusterLeaderPlacement(reports, placement.page, {
      agentTypes: LEADER_AGENTS,
      leaderId: result.leaderId,
      silentNodeId: CLUSTER_LEADER,
    })
    await expectClusterEnvironmentMismatch(reports, mismatch.page, {
      key: remote.envKey,
      remoteNodeId: remote.nodeId,
      silentNodeId: CLUSTER_LEADER,
    })
    await expect(
      dashboard.page.getByTestId('dashboard-card-hilos_daemon'),
    ).toBeVisible()
    for (const [index, observation] of survivor.pages.entries()) {
      await expectPageReady(observation.page)
      await expect(observation.page.getByTestId('page-error')).toHaveCount(0)
      await expectDocumentUnchanged(observation.page)
      await reports
        .poll(() => observation.sinceMark().received.length)
        .toBeGreaterThan(0)
      const pageKey =
        index < 2
          ? 'hilos_daemon'
          : index === 2
            ? 'hilos_daemon_env_mismatch'
            : 'hilos'
      await reports
        .poll(() => observation.sinceMark().answered)
        .toContain(pageKey)
      const evidence = observation.sinceMark()
      expect(evidence.sockets).toBe(0)
      expect(evidence.closed).toBe(0)
      expect(evidence.received).toContainEqual({
        page:
          index < 2
            ? 'hilos_daemon'
            : index === 2
              ? 'hilos_daemon_env_mismatch'
              : 'hilos',
        params: {},
      })
      expect(evidence.sent).toContain('page_subscribe')
      expect(evidence.sent).not.toContain('action')
      expect(evidence.subscribed).toContainEqual(
        expect.objectContaining({
          page: pageKey,
          params: {},
        }),
      )
      if (index === 1 || index === 2) {
        expect(evidence.subscribed).toContainEqual(
          expect.objectContaining({
            page: pageKey,
            tableWindows: expect.any(Object),
          }),
        )
      }
      await test.info().attach(`daemon-recovery-${survivor.nodeId}-${index}`, {
        body: JSON.stringify(evidence),
        contentType: 'application/json',
      })
    }
    expect(survivor.privacy.frames.length).toBeGreaterThan(survivor.privacyMark)
    await expect(mismatch.page.locator('body')).not.toContainText(
      remote.envValue,
    )
    for (const candidate of candidates) {
      for (const frame of candidate.privacy.frames)
        expect(frame.raw).not.toContain(remote.envValue)
    }
    expect(
      mismatchRemoteCells(
        survivor.privacy.frames.slice(survivor.privacyMark),
        remote.envKey,
        remote.nodeId,
      ),
    ).toContainEqual({
      nodeId: remote.nodeId,
      state: 'known',
      type: 'string',
      source: 'process',
      digest: expect.stringMatching(/^[0-9a-f]{16}$/),
    })
    const after = await clusterInspect(survivor.nodeId)
    expect(after.leaderId).toBe(result.leaderId)
    expect(after.leaderId).not.toBe(survivor.nodeId)
    expect(after.term).toBe(result.term)
    await test.info().attach('daemon-inspect-after', {
      body: JSON.stringify({ source: survivor.nodeId, ...after }),
      contentType: 'application/json',
    })
  } finally {
    for (const candidate of candidates) {
      candidate.privacy.stop()
      await test.info().attach(`daemon-mismatch-privacy-${candidate.nodeId}`, {
        body: JSON.stringify({
          beforeKill: candidate.privacyMark,
          frames: candidate.privacy.frames,
        }),
        contentType: 'application/json',
      })
      await candidate.pages[0].page.context().close()
    }
  }
})

/** Observe the real source frame and the browser's reply, without driving either side. */
function observeRecovery(page: Page) {
  const frames: SocketFrame[] = []
  const received: Array<{ page: string; params: Record<string, unknown> }> = []
  const sent: string[] = []
  const subscribed: Array<{
    page: string
    params: Record<string, unknown>
    tableWindows?: Record<string, unknown>
  }> = []
  const answered: string[] = []
  let sockets = 0
  let closed = 0
  let mark = {
    frames: 0,
    received: 0,
    sent: 0,
    subscribed: 0,
    answered: 0,
    sockets: 0,
    closed: 0,
  }
  page.on('websocket', (socket) => {
    sockets += 1
    const read = createSocketFrameReader(sockets)
    socket.on('close', () => {
      closed += 1
    })
    socket.on('framereceived', ({ payload }) => {
      for (const observation of read(payload)) {
        frames.push(observation)
        const frame = observation.wire as
          | {
              type: string
              data: { page: string; params: Record<string, unknown> }
            }
          | undefined
          | null
        if (frame?.type === 'page_resubscribe') received.push(frame.data)
        if (frame?.type === 'page_response') answered.push(frame.data.page)
      }
    })
    socket.on('framesent', ({ payload }) => {
      if (typeof payload !== 'string' || !payload.startsWith('{')) return
      const { type, ...data } = JSON.parse(payload) as {
        type: string
      } & (typeof subscribed)[number]
      sent.push(type)
      if (type === 'page_subscribe') subscribed.push(data)
    })
  })
  return {
    page,
    mark() {
      mark = {
        frames: frames.length,
        received: received.length,
        sent: sent.length,
        subscribed: subscribed.length,
        answered: answered.length,
        sockets,
        closed,
      }
    },
    sinceMark() {
      return {
        frames: frames.slice(mark.frames),
        received: received.slice(mark.received),
        sent: sent.slice(mark.sent),
        subscribed: subscribed.slice(mark.subscribed),
        answered: answered.slice(mark.answered),
        sockets: sockets - mark.sockets,
        closed: closed - mark.closed,
      }
    },
  }
}

/** Read the real comparison row from either full page answers or window replies. */
function mismatchRemoteCells(
  frames: ReturnType<typeof watchDaemonMismatchFrames>['frames'],
  key: string,
  nodeId: string,
): unknown[] {
  interface Row {
    rowKey: string
    slots: { key?: { cells?: Array<{ nodeId: string }> } }
  }
  return frames.flatMap(({ wire }) => {
    const { data } = wire as {
      data: {
        rows?: Row[]
        payload?: { windows?: Record<string, { rows?: Row[] }> }
      }
    }
    const rows = [
      ...(data.rows ?? []),
      ...Object.values(data.payload?.windows ?? {}).flatMap(
        (window) => window.rows ?? [],
      ),
    ]
    return rows
      .filter((row) => row.rowKey === key)
      .flatMap((row) =>
        (row.slots.key?.cells ?? []).filter((cell) => cell.nodeId === nodeId),
      )
  })
}
