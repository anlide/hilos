import { test, expect } from '@playwright/test'

import {
  armSocketDrop,
  dropSocket,
  expectCommunicationsChannelHead,
  expectLogsPageHead,
  shownByTestId,
  watchSocketFrames,
  type SocketFrame,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { FOLLOWED_STREAM } from '../helpers/logs.js'

/** Wire key of the framework users table, the one table of the `/hilos/users` page. */
const HILOS_USERS_TABLE = 'hilosUsers'

// Admin tree navigation e2e: the framework dashboard lists the Hilos admin
// sections, and every section / sub-page / deep link resolves over the live
// socket with no document reload. Each `/hilos` page renders through the
// framework HilosAdminPage shell (breadcrumb + children resolved from the core
// admin tree). Built sections and their children exercise both the dashboard
// menu and per-page admin routing; unbuilt pages are covered by chat's
// unbuilt-page.spec.
test('navigates the admin tree with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads

  // Gear -> dashboard, which lists the sections as cards.
  await page.getByTestId('nav-admin').click()
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  await expect(page.getByTestId('dashboard-card-hilos_logs')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')

  // Dashboard card -> the Logs owner and its six page links.
  await page.getByTestId('dashboard-card-hilos_logs').click()
  await expectLogsPageHead(expect, page, 'hilos_logs')
  expect(new URL(page.url()).pathname).toBe('/hilos/logs')
  await expect(page.getByTestId('hilos-page-tab-hilos_logs_keys')).toBeVisible()

  // Every tab is its own page; Overview is the way back to the section summary.
  await page.getByTestId('hilos-page-tab-hilos_logs_keys').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_keys')
  expect(new URL(page.url()).pathname).toBe('/hilos/logs/keys')
  await expect(page.getByTestId('hilos-table-title')).toHaveText('Log streams')
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(page.getByTestId('hilos-table-filter-class')).toBeVisible()
  await expect(page.getByTestId('hilos-table-count')).toBeVisible()
  await expect(page.getByTestId('hilos-table-cards')).toHaveCount(1)
  await page.getByTestId('hilos-page-tab-hilos_logs').click()
  await expectLogsPageHead(expect, page, 'hilos_logs')
  expect(new URL(page.url()).pathname).toBe('/hilos/logs')

  await page.getByTestId('hilos-page-tab-hilos_logs_workers').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_workers')
  expect(new URL(page.url()).pathname).toBe('/hilos/logs/workers')
  await expect(page.getByTestId('hilos-table-title')).toHaveText(
    'Worker streams',
  )
  await expect(page.getByTestId('hilos-table-search')).toBeVisible()
  await expect(page.getByTestId('hilos-table-filter-type')).toBeVisible()
  await expect(page.getByTestId('hilos-table-count')).toBeVisible()
  await expect(page.getByTestId('hilos-table-cards')).toHaveCount(1)
  await page.getByTestId('hilos-page-tab-hilos_logs').click()
  await expectLogsPageHead(expect, page, 'hilos_logs')
  expect(new URL(page.url()).pathname).toBe('/hilos/logs')

  await page.getByTestId('hilos-page-tab-hilos_logs_rotations').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_rotations')
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await shownByTestId(page, 'hilos-table-filter-state').click()
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations\/due$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_rotations')

  await page.getByTestId('hilos-page-tab-hilos_logs_settings').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_settings')
  await expect(page).toHaveURL(/\/hilos\/logs\/settings$/)
  await page.getByTestId('hilos-page-tab-hilos_logs_view').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_view')
  const stream = page.getByTestId('hilos-log-stream')
  await expect(
    stream.locator(`option[value="${FOLLOWED_STREAM}"]`),
  ).toHaveCount(1)
  await stream.selectOption(FOLLOWED_STREAM)
  const viewerPath = `/hilos/logs/view/standalone/live/${FOLLOWED_STREAM}`
  await expect(page).toHaveURL(new URL(viewerPath, page.url()).href)
  await expectLogsPageHead(expect, page, 'hilos_logs_view')
  await page.getByTestId('hilos-page-tab-hilos_logs').click()
  await expectLogsPageHead(expect, page, 'hilos_logs')

  // The whole tour stayed in one live document on one socket.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)

  // Cold entry, F5 and history use the same owner without extending its crumbs.
  await gotoPage(expect, page, viewerPath, PAGE_READY)
  await expectLogsPageHead(expect, page, 'hilos_logs_view')
  await page.reload()
  await expectPageReady(expect, page)
  await expectLogsPageHead(expect, page, 'hilos_logs_view')
  await page.getByTestId('hilos-page-tab-hilos_logs_rotations').click()
  await expectLogsPageHead(expect, page, 'hilos_logs_rotations')
  await page.goBack()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(new URL(viewerPath, page.url()).href)
  await expectLogsPageHead(expect, page, 'hilos_logs_view')
  await page.goForward()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/logs\/rotations$/)
  await expectLogsPageHead(expect, page, 'hilos_logs_rotations')
})

// A built parametrized admin page resolves on cold load through the framework
// shell, and its breadcrumb preserves the channel on the way to its parent.
test('cold-loads a parametrized admin page through the framework shell', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/communications/email/deliveries')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expectCommunicationsChannelHead(expect, page, 'deliveries')

  // The current entity breadcrumb is Email; Overview keeps the parametrized address.
  const currentBreadcrumb = page
    .getByTestId('hilos-breadcrumb')
    .locator('[aria-current="page"]')
  await expect(currentBreadcrumb).toHaveText('Email')
  await expect(currentBreadcrumb.locator('a')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-page-tab-hilos_communications_channel'),
  ).toHaveAttribute('href', '/hilos/communications/email')
  const communications = page.getByTestId(
    'hilos-breadcrumb-hilos_communications',
  )
  await expect(communications).toHaveText('Communications')
  await expect(communications).toHaveAttribute('href', '/hilos/communications')
  await communications.click()
  await expect(page.getByTestId('hilos-page-head')).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
    'Communications',
  )
  await expect(page.locator('h1')).toHaveCount(1)
  expect(new URL(page.url()).pathname).toBe('/hilos/communications')
})

// Identity-race e2e (HIL-599): an admin page whose socket drops comes back with
// fresh rows and no false refusal. The reconnect is the whole point — the page
// re-subscribes the instant the socket reports `connected`, reporting the window
// each of its tables is holding, and that is before the new connection's identity
// has crossed the RT sync into the worker serving /hilos/users. Judged against
// that missing answer, the frame used to be refused in silence or answered 401,
// so a signed-in admin watched stale rows or was told to sign in again. The server
// now holds such a frame until the identity lands, and what proves it is the
// answer arriving on the new socket rather than nothing at all.
test('re-serves an admin page after a dropped socket, with no false refusal', async ({
  page,
}) => {
  await armSocketDrop(page)
  const frames: SocketFrame[] = []
  type Reply = {
    type?: string
    data?: {
      page?: string
      httpCode?: number
      payload?: { windows?: Record<string, unknown> }
    }
  }
  const socketFrames = watchSocketFrames(page, (frame) => frames.push(frame))

  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
  const framesBeforeDrop = frames.length
  const socketsBeforeDrop = socketFrames.sockets()

  // Kill the socket and let the client notice on its own. Nothing in the product
  // is touched: the connection object is not on `window`, so the drop reaches it
  // through the constructor the page itself used (see dropSocket). The
  // proof of the drop is the NEXT socket rather than a glimpse of the
  // disconnected label, which a fast reconnect can pass through unseen.
  await dropSocket(page)
  await expect
    .poll(socketFrames.sockets, { timeout: 15_000 })
    .toBeGreaterThan(socketsBeforeDrop)
  await expect(page.getByTestId('conn-state')).toHaveText('connected', {
    timeout: 15_000,
  })

  // The window that comes back is the proof: since HIL-642 it rides the answer to
  // the re-subscribe instead of a frame of its own, so what the poll waits for is a
  // `page_response` carrying the users table in its `windows` section. That answers
  // a subscribe sent inside the race window, instead of being dropped by guards
  // reading an identity that had not arrived.
  await expect
    .poll(
      () =>
        frames
          .slice(framesBeforeDrop)
          .map(({ wire }) => wire as Reply | undefined | null)
          .some(
            (frame) =>
              frame?.type === 'page_response' &&
              frame.data?.page === 'hilos_users' &&
              frame.data.payload?.windows?.[HILOS_USERS_TABLE] !== undefined,
          ),
      { timeout: 15_000 },
    )
    .toBe(true)

  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // And nobody was told to sign in again on either socket.
  expect(
    frames
      .map(({ wire }) => wire as Reply | undefined | null)
      .filter(
        (frame) =>
          frame?.type === 'subscription_page_error' &&
          frame.data?.httpCode === 401,
      ),
  ).toEqual([])
  expect(frames.filter(({ failure }) => failure !== undefined)).toEqual([])
  socketFrames.stop()
  await test.info().attach('admin-reconnect-frames', {
    body: JSON.stringify(frames),
    contentType: 'application/json',
  })
})
