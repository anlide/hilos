import { readFileSync } from 'node:fs'

import { expect, test, type Page } from '@playwright/test'

import {
  DAEMON_ROOT_PATH,
  DAEMON_ROOT_CRUMB,
  expectClusterOverview,
  expectClusterDaemonNodePage,
  expectClusterDaemonNodeOverview,
  expectDaemonNodeHead,
  expectDaemonPage,
  expectClusterDaemonCronSchedule,
  expectDaemonWorkerAgent,
  expectClusterLeaderPlacement,
  expectClusterEnvironmentMismatch,
  shownByTestId,
  watchDaemonCronFrames,
  watchDaemonMismatchFrames,
  watchDaemonNodeFrames,
  watchPageErrorFrames,
  watchSocketFrames,
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

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

// A host fault cannot be replayed against the same stand.
test.describe.configure({ retries: 0 })

// FIXME(F-023, 2026-10-23): environment mismatch missing after leader loss · чужой · попытка 1 · HIL-1641
test.fixme('cluster daemon: foreign node pages, remote restart, environment mismatch and leader loss', async ({
  browser,
}) => {
  // Remote reports, a real restart and recovery of the original tabs exceed one UI round trip.
  test.slow()
  const remote = daemonClusterEnvironment()
  // Both existing followers keep their contexts. Only the one that stays follower
  // after the election counts as the source of the recovery proof.
  const candidates: Array<{
    nodeId: string
    pages: ReturnType<typeof observeRecovery>[]
    overview: ReturnType<typeof watchDaemonNodeFrames>
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
      const leaderOverview = observeRecovery(await first.context().newPage())
      const overview = watchDaemonNodeFrames(leaderOverview.page)
      candidates.push({ nodeId, pages, overview, privacy, privacyMark: 0 })
      await grantAdminToSelf(first)
      await gotoPage(first, DAEMON_ROOT_PATH, PAGE_READY)
      await expectClusterOverview(reports, first, initialPicture)
      if (nodeId === followers[0]) {
        const nodePage = await first.context().newPage()
        const cron = watchDaemonCronFrames(nodePage)
        const overview = watchDaemonNodeFrames(nodePage)
        const refusals = watchPageErrorFrames(nodePage)
        const workerFrames: SocketFrame[] = []
        const workers = watchSocketFrames(nodePage, (frame) => {
          const wire = frame.wire as { data?: { page?: string } } | undefined
          if (frame.failure || wire?.data?.page === 'hilos_daemon_workers')
            workerFrames.push(frame)
        })
        try {
          await gotoPage(nodePage, DAEMON_ROOT_PATH, PAGE_READY)
          await expectClusterOverview(reports, nodePage, initialPicture)
          // A new worker in an unordered open window arrives as a count only.
          // Open Workers after this exact agent's occupied worker reaches the
          // real node picture, rather than expecting a background full window.
          const started = new RegExp(
            `Agent '${RESTART_AGENT}' started on worker #(\\d+)`,
            'g',
          )
          const agentStart = () =>
            [
              ...readFileSync(remote.nodeLogs[remote.nodeId], 'utf8').matchAll(
                started,
              ),
            ].at(-1)
          await reports.poll(() => agentStart()?.[1]).toMatch(/^\d+$/)
          const worker = nodePage.getByTestId(
            `hilos-daemon-worker-${remote.nodeId}-monopolistic-${agentStart()![1]}`,
          )
          await reports(worker).toHaveAttribute('data-occupancy', 'occupied')
          await test.info().attach('daemon-workers-ready-source', {
            body: JSON.stringify({
              nodeId: remote.nodeId,
              agentId: RESTART_AGENT,
              journal: agentStart()![0],
              worker: await worker.getAttribute('data-id'),
              occupancy: await worker.getAttribute('data-occupancy'),
            }),
            contentType: 'application/json',
          })
          const nodeLink = nodePage.getByTestId(
            `hilos-daemon-node-${remote.nodeId}`,
          )
          await nodeLink.focus()
          await nodeLink.press('Enter')
          await expectClusterDaemonNodeOverview(
            reports,
            nodePage,
            overview.frames,
            {
              nodeId: remote.nodeId,
              state: 'Data',
              role: 'slave',
            },
          )
          await nodePage.reload()
          await expectClusterDaemonNodeOverview(
            reports,
            nodePage,
            overview.frames,
            { nodeId: remote.nodeId, state: 'Data', role: 'slave' },
          )
          for (const [child, path] of [
            ['workers', 'workers'],
            ['agents', 'agents'],
            ['cron', 'cron'],
            ['http_server', 'http'],
            ['env', 'env'],
          ]) {
            const pageKey = `hilos_daemon_${child}`
            const link = nodePage.getByTestId(`hilos-page-tab-${pageKey}`)
            await expect(link).toHaveAttribute(
              'href',
              `${DAEMON_ROOT_PATH}/${remote.nodeId}/${path}`,
            )
            await link.click()
            await expect(nodePage).toHaveURL(
              `${DAEMON_ROOT_PATH}/${remote.nodeId}/${path}`,
            )
            await expectDaemonNodeHead(
              reports,
              nodePage,
              pageKey,
              remote.nodeId,
            )
            await expectClusterDaemonNodePage(reports, nodePage, {
              pageKey,
              nodeId: remote.nodeId,
            })
            await expect(
              nodePage.getByTestId('hilos-daemon-node-role'),
            ).toHaveText('Role: slave')
            if (child !== 'env')
              await expect(
                nodePage.getByTestId('hilos-daemon-node-state'),
              ).toHaveText('Data')
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
            await nodePage
              .getByTestId('hilos-page-tab-hilos_daemon_node')
              .click()
            await expectClusterDaemonNodeOverview(
              reports,
              nodePage,
              overview.frames,
              {
                nodeId: remote.nodeId,
                state: 'Data',
                role: 'slave',
              },
            )
          }
          await nodePage.getByTestId('hilos-daemon-node-log').click()
          await expectDaemonPage(reports, nodePage, 'hilos_logs_view')
          await expect(nodePage).toHaveURL(
            `/hilos/logs/view/${remote.nodeId}/live`,
          )
          await expect(nodePage.getByTestId('hilos-log-source')).toHaveValue(
            'live',
          )
          await nodePage.goBack()
          await expectClusterDaemonNodeOverview(
            reports,
            nodePage,
            overview.frames,
            {
              nodeId: remote.nodeId,
              state: 'Data',
              role: 'slave',
            },
          )
          await nodePage.getByTestId(DAEMON_ROOT_CRUMB).click()
          await expectClusterOverview(reports, nodePage, initialPicture)
          await gotoPage(nodePage, `${DAEMON_ROOT_PATH}/unknown-node`)
          await reports
            .poll(() => refusals.at(-1)?.data)
            .toMatchObject({
              page: 'hilos_daemon_node',
              httpCode: 404,
              errorCode: 'not_found',
            })
          await expect(nodePage.getByTestId('page-error')).toHaveAttribute(
            'data-error-code',
            '404',
          )
          await expect(nodePage.getByTestId('hilos-page-head')).toHaveCount(0)
          await expect(nodePage.getByTestId('hilos-page-tabs')).toHaveCount(0)
        } finally {
          cron.stop()
          overview.stop()
          workers.stop()
          try {
            for (const frame of overview.frames)
              expect(frame.raw).not.toContain(remote.envValue)
            await test.info().attach('daemon-foreign-node-overview', {
              body: JSON.stringify(overview.frames),
              contentType: 'application/json',
            })
            await test.info().attach('daemon-workers-reports', {
              body: JSON.stringify(workerFrames),
              contentType: 'application/json',
            })
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
        leaderOverview,
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
      await gotoPage(
        leaderOverview.page,
        `${DAEMON_ROOT_PATH}/${CLUSTER_LEADER}`,
        PAGE_READY,
      )
      await expectClusterDaemonNodeOverview(
        reports,
        leaderOverview.page,
        overview.frames,
        {
          nodeId: CLUSTER_LEADER,
          state: 'Leader',
          role: 'master',
        },
      )
      await gotoPage(
        pages[5].page,
        `${DAEMON_ROOT_PATH}/${remote.nodeId}/workers`,
        PAGE_READY,
      )
      await expectDaemonNodeHead(
        reports,
        pages[5].page,
        'hilos_daemon_workers',
        remote.nodeId,
      )
      await expectClusterDaemonNodePage(reports, pages[5].page, {
        pageKey: 'hilos_daemon_workers',
        nodeId: remote.nodeId,
      })
      await expectDaemonWorkerAgent(reports, pages[5].page, RESTART_AGENT)
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
    const [
      diagram,
      placement,
      mismatch,
      dashboard,
      leaderOverview,
      foreignWorkers,
    ] = survivor.pages
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
    await expectClusterDaemonNodeOverview(
      reports,
      leaderOverview.page,
      survivor.overview.frames,
      {
        nodeId: CLUSTER_LEADER,
        state: 'Silent',
        role: 'master',
      },
    )
    await expectDaemonNodeHead(
      reports,
      foreignWorkers.page,
      'hilos_daemon_workers',
      remote.nodeId,
    )
    await expectClusterDaemonNodePage(reports, foreignWorkers.page, {
      pageKey: 'hilos_daemon_workers',
      nodeId: remote.nodeId,
    })
    await expectDaemonWorkerAgent(reports, foreignWorkers.page, RESTART_AGENT)
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
      const { pageKey, params } = [
        { pageKey: 'hilos_daemon', params: {} },
        { pageKey: 'hilos_daemon', params: {} },
        { pageKey: 'hilos_daemon_env_mismatch', params: {} },
        { pageKey: 'hilos', params: {} },
        { pageKey: 'hilos_daemon_node', params: { nodeId: CLUSTER_LEADER } },
        { pageKey: 'hilos_daemon_workers', params: { nodeId: remote.nodeId } },
      ][index]
      await reports
        .poll(() => observation.sinceMark().answered)
        .toContain(pageKey)
      const evidence = observation.sinceMark()
      expect(evidence.sockets).toBe(0)
      expect(evidence.closed).toBe(0)
      expect(evidence.received).toContainEqual({
        page: pageKey,
        params,
      })
      expect(evidence.sent).toContain('page_subscribe')
      expect(evidence.sent).not.toContain('action')
      expect(evidence.subscribed).toContainEqual(
        expect.objectContaining({
          page: pageKey,
          params,
        }),
      )
      if (index === 1 || index === 2 || index === 5) {
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
    // A known silent member stays an ordinary addressed link, without a 404.
    await diagram.page
      .getByTestId(`hilos-daemon-node-${CLUSTER_LEADER}`)
      .click()
    await expectDaemonNodeHead(
      reports,
      diagram.page,
      'hilos_daemon_node',
      CLUSTER_LEADER,
    )
    await expect(diagram.page).toHaveURL(
      `${DAEMON_ROOT_PATH}/${CLUSTER_LEADER}`,
    )
    await expect(
      diagram.page.getByTestId('hilos-daemon-node-state'),
    ).toHaveText('Silent')
    await expect(
      diagram.page.getByTestId('hilos-daemon-report-notice'),
    ).toContainText('values from the last report')
    await expect(diagram.page.getByTestId('page-error')).toHaveCount(0)
  } catch (failure) {
    await test.info().attach('daemon-primary-failure', {
      body: JSON.stringify(
        failure instanceof Error
          ? {
              name: failure.name,
              message: failure.message,
              stack: failure.stack,
            }
          : { failure: String(failure) },
      ),
      contentType: 'application/json',
    })
    throw failure
  } finally {
    // Save every original follower's physical frames before closing any context.
    // A timed-out close must not hide the other follower's recovery evidence.
    for (const candidate of candidates) {
      candidate.overview.stop()
      candidate.privacy.stop()
      for (const frame of candidate.overview.frames)
        expect(frame.raw).not.toContain(remote.envValue)
      await test.info().attach(`daemon-node-overview-${candidate.nodeId}`, {
        body: JSON.stringify(candidate.overview.frames),
        contentType: 'application/json',
      })
      await test.info().attach(`daemon-mismatch-privacy-${candidate.nodeId}`, {
        body: JSON.stringify({
          beforeKill: candidate.privacyMark,
          frames: candidate.privacy.frames,
        }),
        contentType: 'application/json',
      })
      for (const [index, observation] of candidate.pages.entries()) {
        await test
          .info()
          .attach(`daemon-final-frames-${candidate.nodeId}-${index}`, {
            body: JSON.stringify(observation.sinceMark()),
            contentType: 'application/json',
          })
      }
    }
    for (const candidate of candidates) {
      for (const [index, observation] of candidate.pages.entries()) {
        const dom = await observation.page
          .content()
          .catch((failure: unknown) => String(failure))
        await test
          .info()
          .attach(`daemon-final-dom-${candidate.nodeId}-${index}`, {
            body: dom,
            contentType: 'text/html',
          })
      }
    }
    for (const candidate of candidates) {
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
