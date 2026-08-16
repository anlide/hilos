import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

/** Name of the page-side hook {@link armSocketDrop} installs for {@link dropSocket}. */
const DROP_SOCKET_HOOK = '__hilosE2eDropSocket'

// Admin tree navigation e2e: the framework dashboard lists the Hilos admin
// sections, and every section / sub-page / deep link resolves over the live
// socket with no document reload. Each `/hilos` page renders through the
// framework HilosAdminPage shell (breadcrumb + children resolved from the core
// admin tree) — an un-implemented page via the framework default view
// (hilosAdminViews), a real one via its own override module — so this proves
// both the dashboard menu and the per-page admin routing.
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
  await expect(page.getByTestId('dashboard-card-hilos_daemon')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')

  // Dashboard card -> a top-level section page with its sub-navigation.
  await page.getByTestId('dashboard-card-hilos_daemon').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')
  await expect(
    page.getByTestId('hilos-admin-child-hilos_daemon_workers'),
  ).toBeVisible()

  // Section -> a sub-page, then back up through the breadcrumb.
  await page.getByTestId('hilos-admin-child-hilos_daemon_workers').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Workers')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon/workers')
  await page.getByTestId('hilos-breadcrumb').getByText('Daemon').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Daemon')
  expect(new URL(page.url()).pathname).toBe('/hilos/daemon')

  // The whole tour stayed in one live document on one socket.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

// A parametrized admin page resolves on cold load through the framework shell:
// the Billing leaf is still an un-implemented page, so it renders via the
// framework default view (hilosAdminViews) — the route param is captured and the
// leaf renders with the framework breadcrumb built from the core admin tree
// (HILOS_ADMIN_PAGES), proving a deep link works without a per-project stub file.
test('cold-loads a parametrized admin page through the framework shell', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/billing/stripe/payments')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Payments')

  // The framework breadcrumb walks back to the provider and the billing hub,
  // keeping the providerId from the current route.
  const breadcrumb = page.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb.getByText('Billing')).toBeVisible()
  await breadcrumb.getByText('Billing', { exact: true }).click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Billing')
  expect(new URL(page.url()).pathname).toBe('/hilos/billing')
})

// The e2e needs one thing the browser will not give it: a socket that dies while
// the page stays put. Playwright's offline emulation was the obvious way and does
// not work — Chromium blocks new requests but leaves an established WebSocket
// running, so the client never notices and `conn-state` stays `connected`
// (measured: three runs, 15 s each, never a transition). The connection object is
// deliberately not exposed on `window`, so the remaining seam is the one the page
// itself goes through: wrap the constructor before the app loads, keep the sockets
// it makes, and close them on demand. The product is untouched — only the drop is
// simulated, and everything after it is the real client's own reconnect.
async function armSocketDrop(page: Page): Promise<void> {
  await page.addInitScript((hook: string) => {
    const sockets: WebSocket[] = []
    const NativeWebSocket = window.WebSocket
    class TrackedWebSocket extends NativeWebSocket {
      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols)
        sockets.push(this)
      }
    }
    window.WebSocket = TrackedWebSocket
    Object.defineProperty(window, hook, {
      value: () => {
        for (const socket of sockets.splice(0)) {
          socket.close()
        }
      },
    })
  }, DROP_SOCKET_HOOK)
}

/** Closes every socket the page has opened, the way a dropped network would. */
function dropSocket(page: Page): Promise<void> {
  return page.evaluate((hook) => {
    ;(window as unknown as Record<string, () => void>)[hook]()
  }, DROP_SOCKET_HOOK)
}

// Identity-race e2e (HIL-599): an admin page whose socket drops comes back with
// fresh rows and no false refusal. The reconnect is the whole point — the table
// asks for its window the instant the socket reports `connected`, which is
// before the new connection's identity has crossed the RT sync into the worker
// serving /hilos/users. Judged against that missing answer, the request used to
// be refused in silence (the window) or answered 401 (the subscription), so a
// signed-in admin watched stale rows or was told to sign in again. The server
// now holds such a frame until the identity lands, and what proves it is the
// answer arriving on the new socket rather than nothing at all.
test('re-serves an admin page after a dropped socket, with no false refusal', async ({
  page,
}) => {
  await armSocketDrop(page)
  const frames: Array<{
    type?: string
    data?: { page?: string; tableKey?: string; httpCode?: number }
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
  // through the constructor the page itself used (see dropSocket above). The
  // proof of the drop is the NEXT socket rather than a glimpse of the
  // disconnected label, which a fast reconnect can pass through unseen.
  await dropSocket(page)
  await expect.poll(() => sockets, { timeout: 15_000 }).toBeGreaterThan(
    socketsBeforeDrop,
  )
  await expect(page.getByTestId('conn-state')).toHaveText('connected', {
    timeout: 15_000,
  })

  // The window that comes back is the proof: it was requested in the race window
  // and answered anyway, instead of being dropped by guards reading an identity
  // that had not arrived.
  await expect
    .poll(
      () =>
        frames
          .slice(framesBeforeDrop)
          .some(
            (frame) =>
              frame.type === 'table_window' && frame.data?.page === 'hilos_users',
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
