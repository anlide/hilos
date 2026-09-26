import { test, expect } from '@playwright/test'

import {
  armSocketDrop,
  dropSocket,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

/** Wire key of the framework users table, the one table of the `/hilos/users` page. */
const HILOS_USERS_TABLE = 'hilosUsers'

// Admin tree navigation e2e: the framework dashboard lists the Hilos admin
// sections, and every section / sub-page / deep link resolves over the live
// socket with no document reload. Each `/hilos` page renders through the
// framework HilosAdminPage shell (breadcrumb + children resolved from the core
// admin tree). Built sections and their children exercise both the dashboard
// menu and per-page admin routing; unbuilt pages are covered by unbuilt-page.spec.
test('navigates the admin tree with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(page)
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads

  // Gear -> dashboard, which lists the sections as cards.
  await page.getByTestId('nav-admin').click()
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  await expect(page.getByTestId('dashboard-card-hilos_security')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')

  // Dashboard card -> a top-level section page with its sub-navigation.
  await page.getByTestId('dashboard-card-hilos_security').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Security Center')
  expect(new URL(page.url()).pathname).toBe('/hilos/security')
  await expect(
    page.getByTestId('hilos-admin-child-hilos_security_oauth'),
  ).toBeVisible()

  // Section -> a sub-page, then back up through the breadcrumb.
  await page.getByTestId('hilos-admin-child-hilos_security_oauth').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('OAuth providers')
  expect(new URL(page.url()).pathname).toBe('/hilos/security/oauth')
  await page.getByTestId('hilos-breadcrumb-hilos_security').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Security Center')
  expect(new URL(page.url()).pathname).toBe('/hilos/security')

  // The whole tour stayed in one live document on one socket.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

// A built parametrized admin page resolves on cold load through the framework
// shell, and its breadcrumb preserves the channel on the way to its parent.
test('cold-loads a parametrized admin page through the framework shell', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/communications/email/deliveries')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Deliveries')

  // The framework breadcrumb keeps channelId in the channel's own address.
  const channel = page.getByTestId('hilos-breadcrumb-hilos_communications_channel')
  await expect(channel).toHaveAttribute('href', '/hilos/communications/email')
  const communications = page.getByTestId('hilos-breadcrumb-hilos_communications')
  await expect(communications).toHaveText('Communications')
  await communications.click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Communications')
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
  const frames: Array<{
    type?: string
    data?: {
      page?: string
      httpCode?: number
      payload?: { windows?: Record<string, unknown> }
    }
  }> = []
  let sockets = 0
  page.on('websocket', (ws) => {
    sockets += 1
    ws.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') {
        return
      }
      try {
        frames.push(JSON.parse(frame.payload))
      } catch {
        // Not one of ours; the socket also carries the keepalive text ping.
      }
    })
  })

  await signUpAdmin(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
  const framesBeforeDrop = frames.length
  const socketsBeforeDrop = sockets

  // Kill the socket and let the client notice on its own. Nothing in the product
  // is touched: the connection object is not on `window`, so the drop reaches it
  // through the constructor the page itself used (see dropSocket). The
  // proof of the drop is the NEXT socket rather than a glimpse of the
  // disconnected label, which a fast reconnect can pass through unseen.
  await dropSocket(page)
  await expect.poll(() => sockets, { timeout: 15_000 }).toBeGreaterThan(
    socketsBeforeDrop,
  )
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
          .some(
            (frame) =>
              frame.type === 'page_response' &&
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
    frames.filter(
      (frame) =>
        frame.type === 'subscription_page_error' && frame.data?.httpCode === 401,
    ),
  ).toEqual([])
})
