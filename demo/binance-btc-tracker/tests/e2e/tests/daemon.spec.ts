import { expect, test, type Page } from '@playwright/test'
import { EventEmitter } from 'node:events'

import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
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
  watchDeliveryFrames,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectDaemonNodeHead,
  expectDaemonNodeFits,
  watchPageErrorFrames,
  expectDaemonNodeTableName,
  expectStandaloneNodeOverview,
  holdPageReply,
  countSentFrames,
  watchDaemonNodeFrames,
  watchDaemonWebsocketsFrames,
  readDaemonWebsocketsCount,
  expectStandaloneWebsockets,
  watchHeight,
  expectDaemonNodeAnswer,
  expectDaemonNodeLive,
  DAEMON_WORKERS_PATH,
  DAEMON_WORKERS_CHILD,
  DAEMON_ROOT_CRUMB,
  DAEMON_PLACEMENT_TYPE,
  DAEMON_PLACEMENT_LONG_TYPE,
  DAEMON_PLACEMENT_CELL,
  DAEMON_PLACEMENT_TOTALS,
  RESTART_MARK_TIMEOUT_MS,
  expectDaemonPage,
  expectStandaloneOverview,
  expectStandalonePlacement,
  expectUnfilteredTotals,
  expectStandaloneWorkers,
  expectStandaloneCron,
  readDaemonCronMoment,
  expectDaemonCronMoments,
  watchDaemonCronDisplay,
  forwardSessionTimeZone,
  deliveryObject,
  expectStandaloneHttp,
  expectStandaloneEnvironment,
  expectStandaloneEnvOrphans,
  expectViewerEnvOrphans,
  expectStandaloneMismatch,
  ROWS,
  fitsInScrollport,
  shownByTestId,
  watchTableSearch,
  watchTableModalFocus,
  watchTableContinuity,
  watchDaemonSource,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
  armViewerSubmit,
  expectViewerRestartRefused,
  RESTART_AGENT,
  readRestartWorker,
  withStoppedRestartWorker,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

// The live standalone Daemon section: navigation, placement, node reports,
// keyboard access, themes, viewer privacy and the shared restart mark.
// Vue alone submits a forged viewer restart request to check the server.
// Kit unit tests cover Cron idle/empty and cluster mismatches (P-572);
// cluster coverage lives in ../cluster/daemon/daemon.spec.ts.
const DATABASE_HOST = 'binance-btc-tracker-mysql-test'

// Runner doubles exercise correlation and cleanup. The real browser and server
// still prove the private socket hook and view-mode refusal in the restart test.
test.describe('forged viewer submit transport', () => {
  const requestId = 'e2e-correlated-submit'
  const request = JSON.stringify({
    type: 'action',
    action: 'forged',
    requestId,
  })

  function observerPeer(send: (frame: string) => void) {
    const socket = new EventEmitter()
    const events = Object.assign(new EventEmitter(), {
      async addInitScript() {},
      async evaluate(callback: unknown, frame: string) {
        void callback
        send(frame)
      },
    })
    return { socket, events, page: events as unknown as Page }
  }

  for (const delivery of ['bare', 'batch'] as const) {
    for (const correlation of ['envelope', 'data'] as const) {
      test(`${delivery} forged reply correlates by ${correlation} and retains neighbors and evidence`, async () => {
        const sent: unknown[] = []
        const reply = {
          type: 'action_error',
          data: {
            action: 'forged',
            errorCode: 'view_mode',
            reason: 'Refused.',
            ...(correlation === 'data' ? { requestId } : {}),
          },
          ...(correlation === 'envelope' ? { requestId } : {}),
          outcome: 'fail',
          time: 42,
        }
        const before = {
          type: 'e2e_neighbor',
          data: {},
          requestId: 'someone-else',
        }
        const after = { type: 'e2e_tail', data: { sequence: 1 } }
        const late = { type: 'e2e_tail', data: { sequence: 2 } }
        const inners = [before, null, reply, after]
        const raw =
          delivery === 'batch'
            ? JSON.stringify({ type: 'signal_batch', data: { frames: inners } })
            : JSON.stringify(reply)
        const peer = observerPeer((message) => {
          sent.push(JSON.parse(message))
          const receive = (payload: string | Buffer) =>
            peer.socket.emit('framereceived', { payload })
          receive('{"type":')
          receive(Buffer.from([0, 255]))
          if (delivery === 'bare') receive(JSON.stringify(before))
          receive(raw)
          if (delivery === 'bare') receive(JSON.stringify(after))
          receive(JSON.stringify(late))
        })
        const viewer = await armViewerSubmit(peer.page)
        peer.events.emit('websocket', peer.socket)
        try {
          const answer = await viewer.submit(request, requestId)
          expect(answer.wire).toEqual(reply)
          expect(answer.raw).toBe(raw)
          expect(answer.failure).toBeUndefined()
          expect(sent).toEqual([
            { type: 'action', action: 'forged', requestId },
          ])
          expect(viewer.frames.map(({ wire }) => wire)).toEqual([
            undefined,
            undefined,
            ...(delivery === 'batch' ? inners : [before, reply, after]),
            late,
          ])
          expect(viewer.frames[0]).toMatchObject({
            socket: 1,
            message: 1,
            raw: '{"type":',
            failure: { kind: 'malformed-json' },
          })
          expect(viewer.frames[1]).toMatchObject({
            socket: 1,
            message: 2,
            raw: undefined,
            failure: { kind: 'non-text-frame' },
          })
          if (delivery === 'batch') {
            expect(
              viewer.frames
                .slice(2, 6)
                .map(({ socket, message, raw }) => ({ socket, message, raw })),
            ).toEqual(inners.map(() => ({ socket: 1, message: 3, raw })))
            expect(viewer.frames[3].failure?.kind).toBe('invalid-envelope')
          }
          viewer.dispose()
          expect(peer.events.listenerCount('websocket')).toBe(0)
          expect(peer.events.listenerCount('close')).toBe(0)
          expect(peer.socket.listenerCount('framereceived')).toBe(0)
          expect(peer.socket.listenerCount('close')).toBe(0)
          await expect(viewer.submit(request, requestId)).rejects.toThrow(
            'disposed',
          )
        } finally {
          viewer.dispose()
        }
      })
    }
  }

  for (const ending of ['close', 'dispose', 'timeout'] as const) {
    test(`a forged submit settles on ${ending} and releases its pending waiter`, async () => {
      let received = 0
      const peer = observerPeer(() => {
        received++
        if (ending === 'close') peer.socket.emit('close')
        if (received > 1)
          peer.socket.emit('framereceived', {
            payload: JSON.stringify({
              type: 'action_error',
              data: { action: 'forged', reason: 'Late.' },
              requestId,
            }),
          })
      })
      const viewer = await armViewerSubmit(peer.page)
      peer.events.emit('websocket', peer.socket)
      try {
        const answer = viewer.submit(request, requestId)
        const rejected = expect(answer).rejects.toThrow(
          ending === 'close'
            ? 'socket closed'
            : ending === 'dispose'
              ? 'disposed'
              : 'did not answer',
        )
        expect(received).toBe(1)
        if (ending === 'dispose') viewer.dispose()
        await rejected
        if (ending === 'timeout') {
          await expect(
            viewer.submit(request, requestId),
          ).resolves.toMatchObject({
            wire: { requestId, type: 'action_error' },
          })
          expect(received).toBe(2)
        }
      } finally {
        viewer.dispose()
      }
      expect(peer.events.listenerCount('websocket')).toBe(0)
      expect(peer.socket.listenerCount('framereceived')).toBe(0)
    })
  }
})

test.describe('daemon overview', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('opens the real root from the dashboard, cold loads it and returns from Workers and the node log', async ({
    page,
  }) => {
    // Registration and four page subscriptions are real daemon round trips.
    test.slow()
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos', PAGE_READY)
    const card = page.getByTestId('dashboard-card-hilos_daemon')
    await expect(card).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await card.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    // The applied index from the live primary DB, kept once for the stand SQL check.
    await test.info().attach('daemon-primary-migration', {
      body: await page
        .getByTestId('hilos-daemon-database-migration')
        .innerText(),
      contentType: 'text/plain',
    })

    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    const node = page.getByTestId('hilos-daemon-node-standalone')
    await node.focus()
    await node.press('Enter')
    await expect(page).toHaveURL(new RegExp(`${DAEMON_NODE_PATH}$`))
    await expectStandaloneNodeOverview(expect, page)

    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_WORKERS_PATH}$`))
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    const crumb = page.getByTestId(DAEMON_ROOT_CRUMB)
    await expect(crumb).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await crumb.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(expect, page, DATABASE_HOST)

    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page)
    await page.getByTestId('hilos-daemon-node-log').click()
    await expectDaemonPage(expect, page, 'hilos_logs_view')
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    const streams = page.getByTestId('hilos-log-stream')
    // Nonempty catalog options prove the selected node's live viewer is ready.
    // Its existing policy leaves the stream for the operator to choose.
    await expect(
      streams.locator('option[value]:not([value=""])'),
    ).not.toHaveCount(0)
    await expect(streams).toHaveValue('')
    await expect(page).toHaveURL(/\/hilos\/logs\/view\/standalone\/live$/)
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_NODE_PATH}$`))
    await expectStandaloneNodeOverview(expect, page)
  })

  test('keeps the complete technical root available in admin view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(page.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'view',
    )
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
  })

  for (const viewer of [false, true]) {
    test(`searches real placement ids and preserves local selection as ${viewer ? 'a view-mode reader' : 'an admin'}`, async ({
      page,
      browser,
      baseURL,
    }) => {
      // Two real connection round trips traverse the node/collector/mirror chain.
      test.slow()
      const windows = await watchTableSearch(page, {
        page: 'hilos_daemon_placement',
        tableKey: 'hilosDaemonPlacement',
      })
      await page.setViewportSize({ width: 1440, height: 812 })
      if (viewer) {
        await setAdminViewMode(true)
      } else {
        await grantAdminToSelf(expect, page)
      }
      await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await expectStandalonePlacement(expect, page)
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      }
      const totals = await Promise.all(
        DAEMON_PLACEMENT_TOTALS.map((total) =>
          page.getByTestId(`hilos-daemon-placement-${total}`).innerText(),
        ),
      )
      const cell = shownByTestId(page, DAEMON_PLACEMENT_CELL)
      const table = page.getByRole('table', { name: 'Daemon' })
      await expect(table).toBeVisible()
      const selection = table.getByTestId('hilos-daemon-placement-selection')
      const row = table.getByTestId(`hilos-table-row-${DAEMON_PLACEMENT_TYPE}`)
      const detail = table.getByTestId(
        `hilos-table-row-detail-${DAEMON_PLACEMENT_TYPE}`,
      )
      const expand = shownByTestId(
        page,
        `hilos-table-expand-${DAEMON_PLACEMENT_TYPE}`,
      )
      await expect(page.getByTestId(/^hilos-table-expand-/)).toHaveCount(0)
      const source = await watchDaemonSource(windows, page)
      const focus = await watchTableModalFocus(cell)
      const continuity: Awaited<ReturnType<typeof watchTableContinuity>>[] = []
      const sources: Awaited<ReturnType<typeof watchDaemonSource>>[] = []
      sources.push(source)
      const proof: unknown[] = []
      const initialKeys = await windows.rowKeys()
      let actor: Page | undefined
      let ids: string[]
      try {
        await source.singleConnection(expect)
        await cell.focus()
        await cell.press('Enter')
        await expect(cell).toHaveAttribute('aria-expanded', 'true')
        await expect(selection).toBeVisible()
        await expect(
          detail.getByTestId('hilos-daemon-placement-selection'),
        ).toBeVisible()
        expect(
          await row.evaluate((element) =>
            element.nextElementSibling?.getAttribute('data-id'),
          ),
        ).toBe(`hilos-table-row-detail-${DAEMON_PLACEMENT_TYPE}`)
        await expect(expand).toHaveAttribute('aria-expanded', 'true')
        ids = (await selection.locator('code').allTextContents()).slice(2)
        expect(ids).toEqual([DAEMON_PLACEMENT_TYPE])
        await expect(selection.locator('a, button')).toHaveCount(1)
        await expand.click()
        await expect(
          page.getByTestId('hilos-daemon-placement-selection'),
        ).toHaveCount(0)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await expect(expand).toHaveAttribute('aria-expanded', 'false')
        await expand.focus()
        await expand.press('Enter')
        await expect(selection).toContainText(ids[0])
        const collapse = shownByTestId(page, 'hilos-daemon-placement-collapse')
        await collapse.focus()
        await collapse.press('Space')
        await expect(selection).toHaveCount(0)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await cell.focus()
        await cell.press('Space')
        await expect(selection).toContainText(ids[0])
        await expect(cell).toBeFocused()
        const pressed = await watchTableContinuity(cell, [
          'hilos-daemon-placement-selection',
        ])
        continuity.push(pressed)
        const opened = await windows.mark()
        actor = await browser.newPage({
          baseURL,
          ignoreHTTPSErrors: true,
          viewport: { width: 1440, height: 812 },
        })
        await gotoPage(expect, actor, '/', PAGE_READY)
        await expect(actor.getByTestId('conn-state')).toHaveText('connected')
        await source.connectionOpened(expect, opened)
        const closed = await windows.mark()
        await actor.close()
        await source.connectionClosed(expect, closed)
        proof.push(await pressed.preserved(expect))
        await expect(selection).toContainText(ids[0])
        await expect(cell).toHaveAttribute('aria-expanded', 'true')
        await expect(cell).toBeFocused()

        const otherType = DAEMON_PLACEMENT_LONG_TYPE
        await shownByTestId(
          page,
          `hilos-daemon-placement-cell-${otherType}-standalone`,
        ).click()
        await expect(detail).toHaveCount(0)
        await expect(
          table
            .getByTestId(`hilos-table-row-detail-${otherType}`)
            .getByTestId('hilos-daemon-placement-selection'),
        ).toContainText(otherType)
        await expect(selection).toHaveCount(1)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await cell.click()
        await expect(
          table.getByTestId(`hilos-table-row-detail-${otherType}`),
        ).toHaveCount(0)
        await expect(
          detail.getByTestId('hilos-daemon-placement-selection'),
        ).toContainText(ids[0])

        const search = page.getByTestId('hilos-table-search')
        const searched = await windows.mark()
        await typeInto(search, ids[0])
        await windows.settled(expect, searched, ids[0], [DAEMON_PLACEMENT_TYPE])
        proof.push(await pressed.fresh(expect))
        await expect(table.locator(ROWS)).toHaveCount(1)
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(selection).toHaveCount(0)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await cell.click()
        await expect(selection).toContainText(ids[0])
        await expectUnfilteredTotals(expect, page, totals)

        // A real data-only update after an exactly correlated search cannot reset
        // its epoch, acknowledge another ask, or replace its retained occurrence.
        const searchedControl = await watchTableContinuity(cell, [
          'hilos-daemon-placement-selection',
        ])
        continuity.push(searchedControl)
        const searchSource = await watchDaemonSource(windows, page)
        sources.push(searchSource)
        const searchOpened = await windows.mark()
        actor = await browser.newPage({
          baseURL,
          ignoreHTTPSErrors: true,
          viewport: { width: 1440, height: 812 },
        })
        await gotoPage(expect, actor, '/', PAGE_READY)
        await expect(actor.getByTestId('conn-state')).toHaveText('connected')
        await searchSource.connectionOpened(expect, searchOpened)
        proof.push(
          await windows.settled(expect, searched, ids[0], [
            DAEMON_PLACEMENT_TYPE,
          ]),
        )
        const searchClosed = await windows.mark()
        await actor.close()
        await searchSource.connectionClosed(expect, searchClosed)
        proof.push(await searchedControl.preserved(expect))
        await expect(cell).toBeFocused()
        await expect(selection).toContainText(ids[0])
        if (viewer)
          await expect(page.getByTestId('view-mode-banner')).toBeVisible()

        await page.getByTestId('hilos-page-tab-hilos_daemon').click()
        await expectStandaloneOverview(expect, page, DATABASE_HOST)
        await expect(selection).toBeHidden()
        await expectStandalonePlacement(expect, page)
        await expect(search).toHaveValue('')
        await expect(selection).toHaveCount(0)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await cell.click()
        await expect(selection).toContainText(ids[0])
        await expect(cell).toHaveAttribute('aria-expanded', 'true')
        await cell.click()
        await expect(
          page.getByTestId('hilos-daemon-placement-selection'),
        ).toHaveCount(0)
        await expect(cell).toHaveAttribute('aria-expanded', 'false')
        await cell.click()
        await expect(selection).toBeVisible()

        const missing = await windows.mark()
        await typeInto(search, 'no-such-placement-instance')
        await windows.settled(expect, missing, 'no-such-placement-instance', [])
        await expect(
          shownByTestId(page, 'hilos-table-no-matches'),
        ).toBeVisible()
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(selection).toHaveCount(0)
        await expectUnfilteredTotals(expect, page, totals)
        const reset = await windows.mark()
        await shownByTestId(page, 'hilos-table-no-matches-reset').click()
        await windows.settled(expect, reset, '', initialKeys)
        await expect(search).toHaveValue('')
        await expect(cell).toBeVisible()
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(selection).toHaveCount(0)
      } finally {
        await test.info().attach('placement-interaction', {
          body: JSON.stringify(
            {
              frames: await windows.read(),
              target: await focus.read(),
              history: await focus.history(),
              sources: sources.map((source) => source.read()),
              continuity: await Promise.all(
                continuity.map((watch) => watch.read()),
              ),
              proof,
            },
            null,
            2,
          ),
          contentType: 'application/json',
        })
        await test.info().attach('placement-source-screenshot', {
          body: await page.screenshot(),
          contentType: 'image/png',
        })
        for (const watch of continuity) await watch.dispose()
        await focus.dispose()
        if (actor && !actor.isClosed()) await actor.close()
      }
    })
  }

  test('keeps the live root and every child page readable', async ({
    page,
  }) => {
    // It follows every child and its breadcrumb over live subscriptions.
    test.slow()
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await page.setViewportSize({ width: 1440, height: 812 })
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    const colors = await page.locator('html').evaluate((element) => {
      const style = getComputedStyle(element)
      const rgb = (name: string) =>
        `rgb(${style
          .getPropertyValue(name)
          .trim()
          .split(',')
          .map((part) => part.trim())
          .join(', ')})`
      return {
        body: rgb('--bs-body-bg-rgb'),
        text: rgb('--bs-body-color-rgb'),
        tertiary: rgb('--bs-tertiary-bg-rgb'),
      }
    })
    await expect(page.getByTestId('hilos-daemon-node-standalone')).toHaveCSS(
      'background-color',
      colors.body,
    )
    await expect(page.getByTestId('hilos-daemon-node-standalone')).toHaveCSS(
      'color',
      colors.text,
    )
    await expect(page.getByTestId('hilos-daemon-database')).toHaveCSS(
      'background-color',
      colors.tertiary,
    )
    for (const target of [
      page.getByTestId('hilos-daemon-node-standalone'),
      page.getByTestId('hilos-daemon-database-address'),
      page.getByTestId('hilos-daemon-database-migration'),
      page.getByTestId('hilos-page-tab-hilos_daemon_placement'),
      ...(await page.getByTestId(/^hilos-daemon-worker-standalone-/).all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
      expect(
        await target.evaluate(
          (element) => element.scrollWidth - element.clientWidth,
        ),
      ).toBe(0)
    }
    await expectStandalonePlacement(expect, page)
    await expect(
      shownByTestId(page, `hilos-table-row-${DAEMON_PLACEMENT_TYPE}`),
    ).toBeVisible()
    const longType = DAEMON_PLACEMENT_LONG_TYPE
    const cell = shownByTestId(
      page,
      `hilos-daemon-placement-cell-${longType}-standalone`,
    )
    await cell.click()
    const selection = shownByTestId(page, 'hilos-daemon-placement-selection')
    const detail = shownByTestId(page, `hilos-table-row-detail-${longType}`)
    await expect(
      detail.getByTestId('hilos-daemon-placement-selection'),
    ).toBeVisible()
    expect(
      await page
        .getByTestId(`hilos-table-row-${longType}`)
        .evaluate((element) =>
          element.nextElementSibling?.getAttribute('data-id'),
        ),
    ).toBe(`hilos-table-row-detail-${longType}`)
    await expect(
      shownByTestId(page, 'hilos-daemon-placement-selection'),
    ).toHaveCount(1)
    await expect(selection.locator('code')).toHaveText([
      longType,
      'standalone',
      longType,
    ])
    for (const target of [
      page.getByTestId('hilos-table-search'),
      cell,
      selection,
      shownByTestId(page, 'hilos-daemon-placement-collapse'),
      ...(await selection.locator('code').all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
    }
    // Exercise a long current id, without injecting a fake roster.
    for (const id of await selection.locator('code').all()) {
      await expect(id).toHaveCSS('overflow-wrap', 'break-word')
    }
    await test.info().attach('placement', {
      body: await page.getByTestId('hilos-daemon-placement-view').screenshot(),
      contentType: 'image/png',
    })
    await shownByTestId(page, 'hilos-daemon-placement-collapse').click()
    await page.getByTestId('hilos-page-tab-hilos_daemon').click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page)
    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'standalone',
    )
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page)
    await page.getByTestId('hilos-page-tab-hilos_daemon_agents').click()
    await expectDaemonPage(expect, page, 'hilos_daemon_agents')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'standalone',
    )
    await expect(
      shownByTestId(
        page,
        `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
      ),
    ).toBeEnabled()
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    for (const child of ['cron', 'http_server', 'env']) {
      const key = `hilos_daemon_${child}`
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page)
      await page.getByTestId(`hilos-page-tab-${key}`).click()
      await expectDaemonPage(expect, page, key)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') await expectStandaloneHttp(expect, page)
      if (child === 'env') await expectStandaloneEnvironment(expect, page)
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    }
  })
})

test.describe('Cron search and ordering', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`searches real rules and cycles both orders as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      const windows = await watchTableSearch(page, {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
      })
      const proof: unknown[] = []
      try {
        await setAdminViewMode(viewer)
        if (!viewer) await grantAdminToSelf(expect, page)
        await gotoPage(expect, page, `${DAEMON_NODE_PATH}/cron`, PAGE_READY)
        await expectStandaloneCron(expect, page)
        if (viewer) {
          await expect(page.getByTestId('view-mode-banner')).toBeVisible()
          await expect(page.getByTestId('nav-admin')).toHaveAttribute(
            'data-access',
            'view',
          )
        }

        const search = shownByTestId(page, 'hilos-table-search')
        await expect(search).toHaveAttribute(
          'placeholder',
          'Find by rule or agent',
        )
        await expect(search).toHaveAccessibleName('Find by rule or agent')
        const initial = await windows.read()
        const initialKeys = await windows.rowKeys()
        const initialRules = initialKeys.map(
          (key) => readDaemonCronMoment(initial.frames, key)!.rule,
        )
        const target = readDaemonCronMoment(
          initial.frames,
          'hilos_sessions_library/hilos_sessions_expire',
        )!.rule
        expect(target.name).toEqual(expect.any(String))
        expect(target.agentId).toEqual(expect.any(String))
        const name = String(target.name)
        const agentId = String(target.agentId)
        expect(name).not.toContain(agentId)
        expect(initial.application).toHaveLength(1)
        expect(initial.application[0]!.descriptor).toMatchObject({
          filter: { node: 'standalone' },
          sort: null,
          pageIndex: null,
          anchor: null,
          anchorDirection: 'after',
        })
        for (const field of ['name', 'nextRunAt']) {
          const button = page.getByTestId(`hilos-table-sort-${field}`)
          await expect(button).toBeVisible()
          await expect(button.locator('..')).toHaveAttribute(
            'aria-sort',
            'none',
          )
        }

        const missing = 'no-such-cron-rule-or-agent'
        const steps: {
          search: string
          control: string
          field?: 'name' | 'nextRunAt'
          direction?: 'asc' | 'desc'
          key?: 'Enter' | 'Space'
        }[] = [
          { search: name, control: 'hilos-table-search' },
          { search: '', control: 'hilos-table-search-clear' },
          { search: agentId, control: 'hilos-table-search' },
          ...(['name', 'nextRunAt'] as const).flatMap((field) => [
            // Rule is sorted inside the agent search; Next run over the whole set.
            ...(field === 'nextRunAt'
              ? [{ search: '', control: 'hilos-table-search-clear' }]
              : []),
            ...(['asc', 'desc', undefined] as const).map(
              (direction, index) => ({
                search: field === 'name' ? agentId : '',
                control: `hilos-table-sort-${field}`,
                field,
                direction,
                key: (['Enter', 'Space', undefined] as const)[index],
              }),
            ),
          ]),
          { search: missing, control: 'hilos-table-search' },
          { search: '', control: 'hilos-table-no-matches-reset' },
        ]

        for (const step of steps) {
          const mark = await windows.mark()
          const control = shownByTestId(page, step.control)
          if (step.control === 'hilos-table-search') {
            await typeInto(control, step.search)
          } else {
            await control.focus()
            await expect(control).toBeFocused()
            if (step.key) await control.press(step.key)
            else await control.click()
          }
          const filter = {
            node: 'standalone',
            ...(step.search === '' ? {} : { search: step.search }),
          }
          const sort = step.direction
            ? [{ field: step.field, direction: step.direction }]
            : null
          let sent: (typeof initial.frames)[number] | undefined
          let reply: (typeof initial.frames)[number] | undefined
          await expect
            .poll(async () => {
              const state = await windows.read()
              sent = state.frames
                .filter(
                  (frame) =>
                    frame.direction === 'sent' &&
                    frame.index >= mark.cursor &&
                    frame.wire.type === 'table_viewport' &&
                    JSON.stringify(frame.wire.filter) ===
                      JSON.stringify(filter) &&
                    JSON.stringify(frame.wire.sort ?? null) ===
                      JSON.stringify(sort),
                )
                .at(-1)
              reply = state.frames.find(
                (frame) =>
                  sent !== undefined &&
                  frame.direction === 'received' &&
                  frame.socket === sent.socket &&
                  frame.index > sent.index &&
                  frame.wire.type === 'table_window' &&
                  deliveryObject(frame.wire.data).requestId ===
                    sent.wire.requestId,
              )
              return deliveryObject(reply?.wire.data).rows
            })
            .toEqual(expect.any(Array))

          const rows = deliveryObject(reply!.wire.data).rows as unknown[]
          const rules = rows.map((row) =>
            deliveryObject(deliveryObject(deliveryObject(row).slots).rule),
          )
          const keys = rows.map((row) => String(deliveryObject(row).rowKey))
          proof.push(await windows.settled(expect, mark, step.search, keys))
          const applied = (await windows.read()).application[0]!
          expect(sent!.wire).toMatchObject({
            page: 'hilos_daemon_cron',
            tableKey: 'hilosDaemonCron',
            filter,
            anchor: null,
            anchorDirection: 'after',
          })
          expect(sent!.wire.sort ?? null).toEqual(sort)
          expect(applied.descriptor).toMatchObject({
            filter,
            sort,
            pageIndex: null,
            anchor: null,
            anchorDirection: 'after',
          })
          expect(applied.filter).toEqual(filter)
          expect(applied.appliedRequestId).toBe(sent!.wire.requestId)
          expect(rules.map((rule) => rule.rowKey)).toEqual(keys)
          if (step.key) await expect(control).toBeFocused()

          const matching = initialRules.filter((rule) =>
            [rule.name, rule.agentId].some(
              (value) =>
                typeof value === 'string' && value.includes(step.search),
            ),
          )
          expect([...keys].sort()).toEqual(
            matching.map((rule) => String(rule.rowKey)).sort(),
          )
          if (step.direction) {
            const field = step.field!
            const ordered = [...rules].sort((left, right) => {
              const a = left[field]
              const b = right[field]
              if (a === null && b !== null) return 1
              if (b === null && a !== null) return -1
              const comparison =
                a === b
                  ? 0
                  : field === 'nextRunAt'
                    ? Number(a) - Number(b)
                    : String(a) < String(b)
                      ? -1
                      : 1
              const byKey = String(left.rowKey) < String(right.rowKey) ? -1 : 1
              return (step.direction === 'asc' ? 1 : -1) * (comparison || byKey)
            })
            expect(keys).toEqual(ordered.map((rule) => rule.rowKey))
            expect(
              new Set(rules.map((rule) => rule[field])).size,
            ).toBeGreaterThan(1)
          } else {
            expect(keys).toEqual(matching.map((rule) => rule.rowKey))
          }
          for (const field of ['name', 'nextRunAt']) {
            await expect(
              page.getByTestId(`hilos-table-sort-${field}`).locator('..'),
            ).toHaveAttribute(
              'aria-sort',
              step.field === field && step.direction
                ? step.direction === 'asc'
                  ? 'ascending'
                  : 'descending'
                : 'none',
            )
          }
          if (step.search === missing) {
            await expect(
              shownByTestId(page, 'hilos-table-no-matches'),
            ).toContainText('Nothing found')
            await expect(
              shownByTestId(page, 'hilos-table-no-matches-terms'),
            ).toContainText(missing)
          } else {
            await expect(
              page.getByTestId('hilos-table-no-matches'),
            ).toHaveCount(0)
          }
        }
        await expect(search).toHaveValue('')
        await expectStandaloneCron(expect, page)
      } finally {
        await test.info().attach('cron-search-order-frames', {
          body: JSON.stringify(
            { frames: await windows.read(), proof },
            null,
            2,
          ),
          contentType: 'application/json',
        })
      }
    })
  }
})

test.describe('Cron reader time zone', () => {
  test.use({ timezoneId: 'America/Los_Angeles', locale: 'en-US' })

  test('reads real millisecond runs in own zones and Automatic while the project stays Warsaw', async ({
    page,
  }) => {
    // One real minute firing can take 60 s. This is a clock wait, not a fixed sleep.
    test.slow()
    const FIRING_TIMEOUT_MS = 70_000
    const browserZone = 'America/Los_Angeles'
    const projectZone = 'Europe/Warsaw'
    const expireKey = 'hilos_sessions_library/hilos_sessions_expire'
    const minuteKey = 'hilos_sessions_library/hilos_account_deletion_sweep'
    const windows = await watchTableSearch(page, {
      page: 'hilos_daemon_cron',
      tableKey: 'hilosDaemonCron',
    })
    // Only currentUser.timeZone is projected. Cron/welcome frames below are the
    // unmodified daemon evidence; backend own-zone persistence belongs to HIL-1593.
    const socket = await forwardSessionTimeZone(page, browserZone)
    try {
      const userId = await grantAdminToSelf(expect, page)
      const welcomes = () =>
        socket.frames.filter(
          (frame) => deliveryObject(frame.wire).type === 'handshake',
        )
      await expect.poll(() => welcomes().length).toBeGreaterThan(0)
      for (const frame of welcomes()) {
        expect(frame.failure).toBeUndefined()
        expect(
          deliveryObject(deliveryObject(frame.wire).data).projectTimeZone,
          'Binance tests/.env must declare HILOS_PROJECT_TIME_ZONE=Europe/Warsaw',
        ).toBe(projectZone)
      }
      expect(
        await page.evaluate(() => ({
          timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          locale: navigator.language,
        })),
      ).toEqual({ timeZone: browserZone, locale: 'en-US' })
      expect(browserZone).not.toBe(projectZone)
      await gotoPage(expect, page, `${DAEMON_NODE_PATH}/cron`, PAGE_READY)
      await expectStandaloneCron(expect, page)
      await expect
        .poll(() => readDaemonCronMoment(socket.frames, minuteKey))
        .toBeDefined()
      const before = readDaemonCronMoment(socket.frames, minuteKey)!.rule
        .lastRunAt
      // A fresh real firing also leaves a whole minute for the live projection checks.
      await expect
        .poll(
          () => {
            const last = readDaemonCronMoment(socket.frames, minuteKey)?.rule
              .lastRunAt
            return (
              typeof last === 'number' &&
              (before === null || last > Number(before))
            )
          },
          { timeout: FIRING_TIMEOUT_MS },
        )
        .toBe(true)
      const display = await watchDaemonCronDisplay(expect, page, minuteKey)
      try {
        const requests = (state: Awaited<ReturnType<typeof windows.read>>) =>
          state.frames.filter((frame) => frame.direction === 'sent')
        const baseline = await windows.read()
        expect(baseline.application).toHaveLength(1)
        expect(requests(baseline).length).toBeGreaterThan(0)
        const connections = socket.sockets()
        const expire = readDaemonCronMoment(socket.frames, expireKey)!.rule
        const minute = readDaemonCronMoment(socket.frames, minuteKey)!.rule
        expect(minute.lastRunAt).toEqual(expect.any(Number))
        let initial:
          Awaited<ReturnType<typeof expectDaemonCronMoments>> | undefined
        for (const own of [browserZone, 'Asia/Tokyo', null]) {
          if (own !== browserZone) socket.replay(own)
          const zone = own ?? browserZone
          const times = await expectDaemonCronMoments(
            expect,
            page,
            socket.frames,
            minuteKey,
            zone,
          )
          await expectDaemonCronMoments(
            expect,
            page,
            socket.frames,
            expireKey,
            zone,
          )
          if (own === browserZone) initial = times
          else if (own === null) expect(times).toEqual(initial)
          else expect(times).not.toEqual(initial)
          expect(socket.sessions.at(-1)).toMatchObject({
            data: { entities: { currentUser: { id: userId, timeZone: own } } },
          })
          await expect(
            page.getByTestId('hilos-daemon-cron-project-time-zone'),
          ).toHaveText(projectZone)
          await expect(
            page.getByTestId('hilos-daemon-cron-time-zone'),
          ).toHaveText(
            `Expressions are evaluated in the project's time zone — ${projectZone}. Run times are shown in your time zone.`,
          )
          expect(readDaemonCronMoment(socket.frames, expireKey)!.rule).toEqual(
            expire,
          )
          expect(readDaemonCronMoment(socket.frames, minuteKey)!.rule).toEqual(
            minute,
          )
          const current = await windows.read()
          expect(requests(current)).toEqual(requests(baseline))
          expect(current.application).toEqual(baseline.application)
          expect(socket.sockets()).toBe(connections)
          await display.unchanged()
        }
      } finally {
        await display.dispose()
      }
    } finally {
      socket.dispose()
      await test.info().attach('cron-original-server-frames', {
        body: JSON.stringify(
          socket.frames.filter(({ wire }) => {
            const frame = deliveryObject(wire)
            return (
              frame.type === 'handshake' ||
              deliveryObject(frame.data).page === 'hilos_daemon_cron'
            )
          }),
          null,
          2,
        ),
        contentType: 'application/json',
      })
    }
  })
})

test.describe('daemon node pages', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`cold loads the addressed Node, reloads and follows every tab with history as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
      context,
    }) => {
      // Cold reply hold, reload and every addressed tab's table subscription.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      const cold = await context.newPage()
      const held = await holdPageReply(cold, 'hilos_daemon_node')
      const reports = watchDaemonNodeFrames(cold)
      const refusals = watchPageErrorFrames(cold)
      const subscribes = countSentFrames(cold, '"type":"page_subscribe"')
      const actions = countSentFrames(cold, '"type":"action"')
      try {
        const ready = gotoPage(expect, cold, DAEMON_NODE_PATH, PAGE_READY)
        await held.received
        await expect(cold.getByTestId('hilos-page-skeleton')).toBeVisible()
        await expect(cold.getByTestId('hilos-page-head')).toHaveCount(0)
        await expect(cold.getByTestId('hilos-breadcrumb')).toHaveCount(0)
        held.release()
        await ready
        await expectStandaloneNodeOverview(expect, cold)
        await expectDaemonNodeAnswer(expect, cold, reports.frames)
        await expectDaemonNodeFits(expect, cold)
        expect(subscribes()).toBe(1)
        expect(actions()).toBe(0)
        const beforeLive = reports.frames.length
        const visitor = await context.newPage()
        try {
          await gotoPage(expect, visitor, '/', PAGE_READY)
          await expectDaemonNodeLive(expect, cold, reports.frames, beforeLive)
          await expectStandaloneNodeOverview(expect, cold)
          expect(subscribes()).toBe(1)
          expect(actions()).toBe(0)
        } finally {
          await visitor.close()
        }
        if (viewer)
          await expect(cold.getByTestId('view-mode-banner')).toBeVisible()
        await cold.reload()
        await expectStandaloneNodeOverview(expect, cold)
        for (const tab of DAEMON_NODE_TABS.slice(1)) {
          const link = cold.getByTestId(`hilos-page-tab-${tab.key}`)
          await link.focus()
          await expect(link).toBeFocused()
          await link.press('Enter')
          await expect(cold).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
          await expectDaemonNodeHead(expect, cold, tab.key)
          if (
            tab.key !== 'hilos_daemon_env' &&
            tab.key !== 'hilos_daemon_websockets'
          )
            await expectDaemonNodeTableName(expect, cold)
          if (tab.key === 'hilos_daemon_agents') {
            const restart = shownByTestId(
              cold,
              `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
            )
            if (viewer) await expect(restart).toBeDisabled()
            else await expect(restart).toBeEnabled()
          }
          await cold.getByTestId('hilos-page-tab-hilos_daemon_node').click()
          await expectStandaloneNodeOverview(expect, cold)
        }
        await cold.getByTestId('hilos-daemon-environment-link').click()
        await expectDaemonNodeHead(expect, cold, 'hilos_daemon_env')
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold)
        await cold.goForward()
        await expectDaemonNodeHead(expect, cold, 'hilos_daemon_env')
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold)
        await cold.getByTestId('hilos-daemon-node-log').click()
        await expectDaemonPage(expect, cold, 'hilos_logs_view')
        await expect(cold).toHaveURL('/hilos/logs/view/standalone/live')
        await expect(cold.getByTestId('hilos-log-source')).toHaveValue('live')
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold)
        await gotoPage(expect, cold, '/hilos/daemon/unknown-node')
        await expect
          .poll(() => refusals.at(-1)?.data)
          .toMatchObject({
            page: 'hilos_daemon_node',
            httpCode: 404,
            errorCode: 'not_found',
          })
        await expect(cold.getByTestId('page-error')).toHaveAttribute(
          'data-error-code',
          '404',
        )
        await expect(cold.getByTestId('hilos-page-head')).toHaveCount(0)
        await expect(cold.getByTestId('hilos-page-tabs')).toHaveCount(0)
        await expect(
          cold.getByTestId('hilos-daemon-node-overview'),
        ).toHaveCount(0)
        await expect(cold.getByTestId('page-error')).not.toContainText(
          'standalone',
        )
      } finally {
        held.dispose()
        reports.stop()
        await cold.close()
      }
    })
  }

  for (const viewer of [false, true]) {
    test(`reads Workers and Cron with named tables and keyboard links as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
      browser,
      baseURL,
    }) => {
      // Three live pages and the worker log.
      test.slow()
      const windows = await watchTableSearch(page, {
        page: 'hilos_daemon_workers',
        tableKey: 'hilosDaemonWorkers',
      })
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      await page.setViewportSize({ width: 1440, height: 812 })
      await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page)
      await page.getByTestId(DAEMON_WORKERS_CHILD).focus()
      await page.getByTestId(DAEMON_WORKERS_CHILD).press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'standalone',
      )
      const surface = page.getByRole('table')
      await expect(surface).toHaveAccessibleName('standalone')
      const expand = shownByTestId(page, /^hilos-table-expand-/).first()
      await expect(expand).toBeVisible()
      const focus = await watchTableModalFocus(expand)
      const source = await watchDaemonSource(windows, page)
      let continuity:
        Awaited<ReturnType<typeof watchTableContinuity>> | undefined
      let actor: Page | undefined
      let proof: unknown
      try {
        await expand.focus()
        await expect(expand).toBeFocused()
        await expand.press('Enter')
        await expect(expand).toHaveAttribute('aria-expanded', 'true')
        await expect(
          shownByTestId(page, /^hilos-daemon-worker-agents-/),
        ).toBeVisible()
        await expect(expand).toBeFocused()
        continuity = await watchTableContinuity(expand, [
          'hilos-daemon-worker-agents-',
        ])
        const changed = await windows.mark()
        // A separate admin starts its real indexed person owner. The target
        // viewer keeps its own session and its view-mode access verdict.
        actor = await browser.newPage({
          baseURL,
          ignoreHTTPSErrors: true,
          viewport: { width: 1440, height: 812 },
        })
        const userId = await grantAdminToSelf(expect, actor)
        // Minting alone need not address an idle person owner. The existing
        // grant command addresses exactly this new owner, even at admin=true.
        await setAdmin(userId, true)
        await source.workerAdded(expect, changed, userId)
        proof = await continuity.preserved(expect)
        await expect(expand).toBeFocused()
        await expect(expand).toHaveAttribute('aria-expanded', 'true')
        await expect(
          shownByTestId(page, /^hilos-daemon-worker-agents-/),
        ).toBeVisible()
        if (viewer) {
          await expect(page.getByTestId('view-mode-banner')).toBeVisible()
          await expect(page.getByTestId('nav-admin')).toHaveAttribute(
            'data-access',
            'view',
          )
        }
      } finally {
        await test.info().attach(`workers-interaction`, {
          body: JSON.stringify(
            {
              frames: await windows.read(),
              target: await focus.read(),
              history: await focus.history(),
              source: source.read(),
              continuity: await continuity?.read(),
              proof,
            },
            null,
            2,
          ),
          contentType: 'application/json',
        })
        await test.info().attach('workers-source-screenshot', {
          body: await page.screenshot(),
          contentType: 'image/png',
        })
        await continuity?.dispose()
        await focus.dispose()
        if (actor && !actor.isClosed()) await actor.close()
      }

      const log = shownByTestId(page, /^hilos-daemon-worker-log-/).first()
      await expect(log).toHaveAttribute(
        'href',
        /\/hilos\/logs\/view\/standalone\/live\/worker-/,
      )
      await log.click()
      await expectDaemonPage(expect, page, 'hilos_logs_view')
      await expect(page.getByTestId('hilos-log-stream')).not.toHaveValue('')
      await page.goBack()
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page)
      const cron = page.getByTestId('hilos-page-tab-hilos_daemon_cron')
      await expect(cron).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/cron',
      )
      await cron.focus()
      await cron.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_cron')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'standalone',
      )
      const schedule = page.getByRole('table')
      await expect(schedule).toHaveAccessibleName('standalone')
      await expectStandaloneCron(expect, page)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      const reports = watchDaemonWebsocketsFrames(page)
      try {
        await gotoPage(
          expect,
          page,
          '/hilos/daemon/standalone/websockets',
          PAGE_READY,
        )
        await expectStandaloneWebsockets(expect, page, reports.frames)
      } finally {
        reports.stop()
      }
    })
  }

  for (const viewer of [false, true]) {
    test(`reads Agents and HTTP with keyboard navigation as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      // It follows real page subscriptions and an agent's live log.
      test.slow()
      const windows = await watchTableSearch(page, {
        page: 'hilos_daemon_agents',
        tableKey: 'hilosDaemonAgents',
      })
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      await page.setViewportSize({ width: 1440, height: 812 })
      await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page)
      const agents = page.getByTestId('hilos-page-tab-hilos_daemon_agents')
      await expect(agents).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/agents',
      )
      await agents.focus()
      await agents.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_agents')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'standalone',
      )
      const surface = page.getByRole('table')
      await expect(surface).toHaveAccessibleName('standalone')
      await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
      const search = page.getByTestId('hilos-table-search')
      await expect(search).toHaveAccessibleName('Search by agent id…')
      const mark = await windows.mark()
      await typeInto(search, RESTART_AGENT)
      try {
        await windows.settled(expect, mark, RESTART_AGENT, [RESTART_AGENT])
      } finally {
        await test.info().attach(`agents-search-frames`, {
          body: JSON.stringify(await windows.read(), null, 2),
          contentType: 'application/json',
        })
      }
      const row = shownByTestId(page, `hilos-table-row-${RESTART_AGENT}`)
      await expect(row).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(row).toContainText('settings')
      await expect(row).toContainText('Stays up')
      const restart = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
        await expect(restart).toBeDisabled()
        await expect(restart).toHaveAttribute(
          'aria-describedby',
          /(^| )hilos-view-mode-strip-text( |$)/,
        )
      } else {
        await expect(restart).toBeEnabled()
        const focus = await watchTableModalFocus(restart)
        await restart.focus()
        await restart.press('Enter')
        const modal = page.getByTestId('modal')
        await expect(modal).toBeFocused()
        await expect(modal).toHaveAccessibleName('Restart agent')
        await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
          `php cli.php agent:restart ${RESTART_AGENT}`,
        )
        await expect(
          page.getByTestId('hilos-daemon-restart-policy'),
        ).toHaveCount(0)
        for (const target of [
          modal,
          page.getByTestId('hilos-daemon-restart-cli'),
          page.getByTestId('hilos-daemon-restart-cancel'),
          page.getByTestId('hilos-daemon-restart-confirm'),
        ]) {
          await target.scrollIntoViewIfNeeded()
          await fitsInScrollport(target)
        }
        await test.info().attach('restart', {
          body: await modal.screenshot(),
          contentType: 'image/png',
        })
        await modal.press('Enter')
        await expect(modal).toBeVisible()
        const beforeClose = await focus.read()
        await test.info().attach(`restart-before-close`, {
          body: JSON.stringify(
            { beforeClose, windows: await windows.read() },
            null,
            2,
          ),
          contentType: 'application/json',
        })
        expect(beforeClose.openerConnected).toBe(true)
        expect(beforeClose.sameTrigger).toBe(true)
        await modal.press('Escape')
        await expect(modal).toHaveCount(0)
        await expect(restart).toBeFocused()
        await test.info().attach(`restart-focus`, {
          body: JSON.stringify(
            {
              beforeClose,
              afterClose: await focus.read(),
              windows: await windows.read(),
            },
            null,
            2,
          ),
          contentType: 'application/json',
        })
        await focus.dispose()
      }
      const log = shownByTestId(page, `hilos-daemon-agent-log-${RESTART_AGENT}`)
      await expect(log).toHaveAttribute(
        'href',
        /\/hilos\/logs\/view\/standalone\/live\/agent-/,
      )
      const href = await log.getAttribute('href')
      expect(href).not.toBeNull()
      const stream = decodeURIComponent(
        new URL(href ?? '', page.url()).pathname.split('/').at(-1) ?? '',
      )
      await log.focus()
      await log.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_logs_view')
      await expect(page.getByTestId('hilos-log-stream')).toHaveValue(stream)
      await page.goBack()
      await expectDaemonPage(expect, page, 'hilos_daemon_agents')
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page)
      const http = page.getByTestId('hilos-page-tab-hilos_daemon_http_server')
      await expect(http).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/http',
      )
      await http.focus()
      await http.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_http_server')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'standalone',
      )
      const routes = page.getByRole('table')
      await expect(routes).toHaveAccessibleName('standalone')
      await expectStandaloneHttp(expect, page)
      await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
      for (const target of [
        page.getByTestId('hilos-daemon-http-address'),
        page.getByTestId('hilos-daemon-http-period'),
      ]) {
        await target.scrollIntoViewIfNeeded()
        await fitsInScrollport(target)
      }
      await test.info().attach(`http-${viewer}`, {
        body: await page.getByTestId('hilos-admin-page').screenshot(),
        contentType: 'image/png',
      })
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    })
  }
})

test.describe('restarting an agent', () => {
  test.afterEach(() => setAdminViewMode(false))

  // The master's 13-second caller window must be judged on the first attempt.
  test.describe.configure({ retries: 0 })

  test('restarts the settings library while two viewers and a new tab see the shared mark', async ({
    page,
    context,
  }) => {
    await grantAdminToSelf(expect, page)
    const worker = await readRestartWorker(expect, page)
    await gotoPage(expect, page, '/hilos/daemon/standalone/agents', PAGE_READY)
    const observer = await context.newPage()
    const newcomer = await context.newPage()
    try {
      await gotoPage(
        expect,
        observer,
        '/hilos/daemon/standalone/agents',
        PAGE_READY,
      )
      const button = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      await button.scrollIntoViewIfNeeded()
      await expect(button).toBeVisible()
      await expect(button).toBeEnabled()
      await button.focus()
      await button.click()
      await expect(page.getByTestId('modal')).toBeFocused()
      await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
        `php cli.php agent:restart ${RESTART_AGENT}`,
      )
      await withStoppedRestartWorker(worker, async () => {
        const confirm = page.getByTestId('hilos-daemon-restart-confirm')
        await confirm.scrollIntoViewIfNeeded()
        await expect(confirm).toBeVisible()
        await expect(confirm).toBeEnabled()
        await confirm.focus()
        await confirm.click()
        const mark = `hilos-daemon-agent-restarting-${RESTART_AGENT}`
        await expect(shownByTestId(page, mark)).toHaveText('Restarting', {
          timeout: RESTART_MARK_TIMEOUT_MS,
        })
        await expect(shownByTestId(observer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            observer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
        await gotoPage(
          expect,
          newcomer,
          '/hilos/daemon/standalone/agents',
          PAGE_READY,
        )
        await expect(shownByTestId(newcomer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            newcomer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
      })
      await expect(page.getByTestId('modal')).toHaveCount(0)
      for (const viewer of [page, observer, newcomer]) {
        await expect(
          viewer.getByTestId(`hilos-daemon-agent-restarting-${RESTART_AGENT}`),
        ).toHaveCount(0, { timeout: RESTART_MARK_TIMEOUT_MS })
        await expect(
          shownByTestId(viewer, `hilos-daemon-agent-restart-${RESTART_AGENT}`),
        ).toBeEnabled()
        await expect(
          viewer.getByTestId(`hilos-table-row-${RESTART_AGENT}`),
        ).toBeVisible()
      }
      await expect(
        page.getByTestId('hilos-toast-success').filter({
          hasText: `Agent ${RESTART_AGENT} restarted on node standalone.`,
        }),
      ).toBeVisible()
    } finally {
      await observer.close()
      await newcomer.close()
    }
  })

  test('a view-mode viewer cannot open restart or submit a forged request', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    const viewer = await armViewerSubmit(page)
    try {
      await gotoPage(
        expect,
        page,
        '/hilos/daemon/standalone/agents',
        PAGE_READY,
      )
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      await expectViewerRestartRefused(expect, page, RESTART_AGENT, viewer)
      await expect(page.getByTestId('modal')).toHaveCount(0)
    } finally {
      viewer.dispose()
      await test.info().attach('viewer-forged-submit-frames', {
        body: JSON.stringify(viewer.frames),
        contentType: 'application/json',
      })
    }
  })
})

test.describe('daemon environment', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('reads environment values, masks secrets and filters attention', async ({
    page,
  }) => {
    // Registration and several server-window changes are real round trips.
    test.slow()
    const windows = await watchTableSearch(page, {
      page: 'hilos_daemon_env',
      tableKey: 'hilosDaemonEnvKeys',
    })
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page)
    const link = page.getByTestId('hilos-daemon-environment-link')
    await link.scrollIntoViewIfNeeded()
    await link.focus()
    await link.press('Enter')
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env$/)
    await page.setViewportSize({ width: 1440, height: 812 })
    await expectStandaloneEnvironment(expect, page)
    const count = await page.getByTestId('hilos-daemon-env-keys').innerText()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'standalone',
    )
    await expect(
      page.getByRole('table', { name: 'Catalog keys' }),
    ).toBeVisible()
    await expect(page.getByRole('table')).toHaveCount(1)
    await expect(
      page.getByRole('list', { name: 'Reading ladder' }),
    ).toBeVisible()
    const search = page.getByTestId('hilos-table-search')
    await expect(search).toHaveAccessibleName('Key or value')
    const searchFor = async (key: string): Promise<void> => {
      const mark = await windows.mark()
      try {
        await typeInto(search, key)
        await windows.settled(expect, mark, key, [key])
      } finally {
        await test.info().attach(`environment-search-${key}`, {
          body: JSON.stringify(await windows.read(), null, 2),
          contentType: 'application/json',
        })
      }
    }
    await searchFor('APP_ENV')
    const app = shownByTestId(page, 'hilos-table-row-APP_ENV')
    await expect(app).toContainText('test')
    await expect(app).toContainText('Launch environment')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    const attention = page.getByTestId('hilos-table-filter-attention')
    await expect(attention).toHaveAccessibleName('Needs attention only')
    await expect(attention).not.toBeChecked()
    const attentionOn = await windows.mark()
    await attention.focus()
    await attention.press('Space')
    await expect(attention).toBeChecked()
    try {
      await windows.settled(expect, attentionOn, 'APP_ENV', [])
    } finally {
      await test.info().attach(`environment-attention-on`, {
        body: JSON.stringify(await windows.read(), null, 2),
        contentType: 'application/json',
      })
    }
    await expect(
      shownByTestId(page, 'hilos-daemon-env-keys-empty-attention'),
    ).toHaveText('No key needs attention.')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.getByTestId('hilos-daemon-env-keys')).toHaveText(count)
    const attentionOff = await windows.mark()
    await attention.focus()
    await attention.press('Space')
    await expect(attention).not.toBeChecked()
    try {
      await windows.settled(expect, attentionOff, 'APP_ENV', ['APP_ENV'])
    } finally {
      await test.info().attach(`environment-attention-off`, {
        body: JSON.stringify(await windows.read(), null, 2),
        contentType: 'application/json',
      })
    }
    await expect(app).toContainText('test')
    await searchFor('DB_PASSWORD')
    const secret = shownByTestId(page, 'hilos-table-row-DB_PASSWORD')
    await expect(secret).toContainText(/Set · \d+ chars/)
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText('hilos_pass')
    const target = await watchTableModalFocus(secret)
    try {
      expect(await target.read()).toMatchObject({
        openerConnected: true,
        sameTrigger: true,
      })
      await secret.scrollIntoViewIfNeeded()
      await fitsInScrollport(secret)
      expect(await target.read()).toMatchObject({
        openerConnected: true,
        sameTrigger: true,
      })
    } finally {
      await test.info().attach(`environment-target`, {
        body: JSON.stringify(
          { target: await target.read(), frames: await windows.read() },
          null,
          2,
        ),
        contentType: 'application/json',
      })
      await target.dispose()
    }
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    await expectStandaloneEnvOrphans(expect, page)
    await page.reload()
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env\/orphans$/)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_daemon_env_orphans'),
    ).toHaveAttribute('aria-current', 'page')
    await expect(page.getByRole('table')).toHaveCount(1)
    await page.goBack()
    await expectStandaloneNodeOverview(expect, page)
    await page.goForward()
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env\/orphans$/)
    await gotoPage(
      expect,
      page,
      '/hilos/daemon/standalone/env/orphans',
      PAGE_READY,
    )
    await expectDaemonPage(expect, page, 'hilos_daemon_env_orphans')
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(page.getByRole('table')).toHaveCount(1)
    // A cold mount binds the catalog window with all keys selected.
    await gotoPage(expect, page, '/hilos/daemon/standalone/env', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
    await expectStandaloneEnvironment(expect, page)
  })

  test('a viewer reads Cron, HTTP, environment, orphans and comparison with values hidden', async ({
    page,
  }) => {
    // Five live child subscriptions and the environment's filtered windows.
    test.slow()
    await setAdminViewMode(true)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(expect, page, DATABASE_HOST)
    for (const child of [
      'cron',
      'http_server',
      'env',
      'env_orphans',
      'env_mismatch',
    ]) {
      const key = `hilos_daemon_${child}`
      await gotoPage(
        expect,
        page,
        child === 'env_mismatch'
          ? '/hilos/daemon/env-mismatch'
          : DAEMON_NODE_PATH,
        PAGE_READY,
      )
      if (child !== 'env_mismatch')
        await expectStandaloneNodeOverview(expect, page)
      const link = page.getByTestId(`hilos-page-tab-${key}`)
      if (child !== 'env_mismatch') {
        await link.focus()
        await link.press('Enter')
      }
      await expectDaemonPage(expect, page, key)
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      await expect(page.getByTestId('view-mode-banner')).toHaveText(
        'View mode · You can look around, but not change anything.',
      )
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') {
        await expectStandaloneHttp(expect, page)
        await expect(
          page.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
        ).toHaveCount(0)
        await expect(page.getByTestId('page-error')).toHaveCount(0)
      }
      if (child === 'env') {
        await expectStandaloneEnvironment(expect, page)
        const search = page.getByTestId('hilos-table-search')
        for (const key of ['DB_HOST', 'DB_PASSWORD']) {
          await typeInto(search, key)
          const row = shownByTestId(page, `hilos-table-row-${key}`)
          await expect(row).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toHaveText('Hidden')
          await expect(row).not.toContainText(/Set ·|chars|hilos_pass/)
          await expect(row).not.toContainText(DATABASE_HOST)
          await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        }
        await expect(page.locator('body')).not.toContainText('hilos_pass')
        await expect(page.getByTestId('page-error')).toHaveCount(0)
        await typeInto(search, DATABASE_HOST)
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(shownByTestId(page, /^hilos-table-row-/)).toHaveCount(0)
      }
      if (child === 'env_orphans') await expectViewerEnvOrphans(expect, page)
      if (child === 'env_mismatch') await expectStandaloneMismatch(expect, page)
      const returnLink = page.getByTestId(
        child === 'env_mismatch'
          ? 'hilos-page-tab-hilos_daemon'
          : DAEMON_ROOT_CRUMB,
      )
      await expect(returnLink).toHaveAttribute('href', DAEMON_ROOT_PATH)
      await returnLink.click()
      await expectStandaloneOverview(expect, page, DATABASE_HOST)
    }
  })

  test('cold loads the standalone comparison without table windows', async ({
    page,
  }) => {
    await grantAdminToSelf(expect, page)
    const windows: string[] = []
    page.on('websocket', (socket) =>
      socket.on('framesent', ({ payload }) => {
        const frame = JSON.parse(String(payload)) as {
          type?: string
          data?: { page?: string }
        }
        if (
          frame.type === 'table_viewport' &&
          frame.data?.page === 'hilos_daemon_env_mismatch'
        )
          windows.push(String(payload))
      }),
    )
    await gotoPage(expect, page, '/hilos/daemon/env-mismatch', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env_mismatch')
    await expectStandaloneMismatch(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    expect(windows).toEqual([])
  })
})

test.describe('Daemon section cold navigation', () => {
  test.afterEach(() => setAdminViewMode(false))

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
      const navigation = gotoPage(
        expect,
        cold,
        DAEMON_PLACEMENT_PATH,
        PAGE_READY,
      )
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
        const initial = await expectDaemonPlacementAnswer(
          expect,
          cold,
          frames(),
        )
        expect(initial.entryPoints?.nodeId).toBe('standalone')
        await expectDaemonPlacementRequests(expect, log)
        if (viewer)
          await expect(cold.getByTestId('view-mode-banner')).toBeVisible()
        await cold.reload()
        await expectDaemonPage(expect, cold, 'hilos_daemon_placement')
        await expectDaemonPlacementAnswer(expect, cold, frames())
        await expectDaemonPlacementRequests(expect, log)
        await expectDaemonSectionAccessibility(expect, cold)
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
        await expect(
          cold.getByTestId('hilos-daemon-placement-view'),
        ).toHaveCount(0)
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
        await expect(cold).toHaveURL(
          `${DAEMON_ROOT_PATH}/${source.nodeId}/http`,
        )
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
})

for (const viewer of [false, true]) {
  test(`WebSockets direct entry, F5 and live count with a second browser as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
    browser,
  }) => {
    // Preparation, reload, route recovery and two source changes each cross the real daemon.
    test.slow()
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(expect, page)
    const reports = watchDaemonWebsocketsFrames(page)
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const visitorContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    try {
      const requests = countSentFrames(page, '"type":"action"')
      const viewports = countSentFrames(page, '"type":"table_viewport"')
      await gotoPage(expect, page, `${DAEMON_NODE_PATH}/websockets`, PAGE_READY)
      await expectStandaloneWebsockets(expect, page, reports.frames)
      expect(requests()).toBe(0)
      expect(viewports()).toBe(0)
      if (viewer)
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      reports.frames.length = 0
      await page.reload()
      await expectStandaloneWebsockets(expect, page, reports.frames)
      await page.getByTestId('hilos-page-tab-hilos_daemon_cron').click()
      await expectDaemonPage(expect, page, 'hilos_daemon_cron')
      const link = page.getByTestId('hilos-page-tab-hilos_daemon_websockets')
      await expect(link).toHaveAttribute(
        'href',
        `${DAEMON_NODE_PATH}/websockets`,
      )
      await link.focus()
      await expect(link).toBeFocused()
      reports.frames.length = 0
      await link.press('Enter')
      // This serial standalone scenario has only this page; the visitor context
      // is still empty. Let the node report the closed navigation sockets before
      // taking a baseline from its sampled picture.
      await expect
        .poll(() => readDaemonWebsocketsCount(reports.frames), {
          timeout: 10_000,
        })
        .toBe(1)
      const baseline = await expectStandaloneWebsockets(
        expect,
        page,
        reports.frames,
      )
      const room = await watchHeight(
        page.getByTestId('hilos-daemon-websockets-tile'),
      )
      const beforeOpen = reports.frames.length
      const visitor = await visitorContext.newPage()
      await gotoPage(expect, visitor, '/', PAGE_READY)
      await expect
        .poll(
          () => readDaemonWebsocketsCount(reports.frames.slice(beforeOpen)),
          { timeout: 10_000 },
        )
        .toBeGreaterThan(baseline)
      const opened = await expectStandaloneWebsockets(
        expect,
        page,
        reports.frames,
      )
      expect(
        reports.frames.slice(beforeOpen).some((frame) => {
          const wire = frame.wire as {
            data: { payload: Record<string, unknown> }
          }
          return (
            Object.keys(wire.data.payload).length === 1 &&
            'data' in wire.data.payload
          )
        }),
      ).toBe(true)
      await room.unchanged()
      const beforeClose = reports.frames.length
      await visitor.close()
      await expect
        .poll(
          () => {
            const count = readDaemonWebsocketsCount(
              reports.frames.slice(beforeClose),
            )
            return count !== null && count < opened
          },
          { timeout: 10_000 },
        )
        .toBe(true)
      await expectStandaloneWebsockets(expect, page, reports.frames)
      await room.unchanged()
      await gotoPage(expect, page, '/hilos/daemon/unknown-node')
      await expect(page.getByTestId('page-error')).toHaveAttribute(
        'data-error-code',
        '404',
      )
      await expect(
        page.getByTestId('hilos-daemon-websockets-tile'),
      ).toHaveCount(0)
      reports.frames.length = 0
      await page.goBack()
      await expectStandaloneWebsockets(expect, page, reports.frames)
    } finally {
      await test.info().attach('daemon-websockets-frames', {
        body: JSON.stringify(reports.frames),
        contentType: 'application/json',
      })
      reports.stop()
      await visitorContext.close()
      await setAdminViewMode(false)
    }
  })
}
