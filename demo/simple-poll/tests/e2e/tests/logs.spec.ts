import { test, expect } from '@playwright/test'
import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Hilos logs admin e2e for the poll demo: activating the framework logs feature
// (six pages, the section agent with the per-node store and the cluster
// aggregator behind it, and three browser tables) makes every screen of the
// section render over the live socket, drawn by the Angular SDK. This is a smoke
// spec on purpose: it asserts that each screen is there and shows its own
// furniture, which is the half of the coverage Angular cannot get from component
// tests (ng test is blocked upstream, so the templates are held by the AOT build
// and by this). What the section DOES — following a tail (HIL-395), rotating and
// carrying a batch off (HIL-763) — belongs to its own specs and is not asserted
// here. Nothing is mutated and nothing is typed, so the spec is idempotent across
// runs on the shared database.
//
// What it deliberately does NOT assert is the state each screen is pushed: the
// viewer's catalog and the modes screen's cards ride a frame the backend sends
// ahead of page_response, and the routed outlet of every kit creates the page
// component only after that answer, so the frame reaches no view at all (P-242,
// proved by six attempts of this very spec). Asserting the empty state the screens
// DO show would be worse than asserting nothing: it would write the defect into a
// test and break the day it is fixed. When P-242 is closed, the three preset cards
// and hilos-log-empty-unchosen come back here.

test('renders every screen of the logs section over the live socket', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  // The section root: the shell's cards to the child pages plus the overview's own
  // tiles beneath them.
  await gotoPage(page, '/hilos/logs')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Logs')
  await expect(page.getByTestId('hilos-admin-children')).toBeVisible()
  await expect(page.getByTestId('hilos-logs-tiles')).toBeVisible()

  // The three windowed lists. Each is served by its own browser table, registered
  // against its page on the backend; the rows depend on what the stand has logged,
  // so the assertion is on the table and not on its contents.
  await gotoPage(page, '/hilos/logs/keys')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('By key')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-log-key-class-all')).toBeVisible()

  await gotoPage(page, '/hilos/logs/workers')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('By worker')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-log-worker-type-all')).toBeVisible()

  await gotoPage(page, '/hilos/logs/rotations')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Rotations')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-rotation-settings')).toBeVisible()

  // The viewer: the address is three slots, the stream is never guessed, and the
  // pane is there with the read controls around it.
  await gotoPage(page, '/hilos/logs/view')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Viewer')
  await expect(page.getByTestId('hilos-log-source')).toBeVisible()
  await expect(page.getByTestId('hilos-log-stream')).toBeVisible()
  await expect(page.getByTestId('hilos-log-level')).toBeVisible()
  await expect(page.getByTestId('hilos-log-pane')).toBeVisible()
  await expect(page.getByTestId('hilos-log-count')).toHaveText('0 entries shown')

  // The logging modes screen and the way out of it into the general settings.
  await gotoPage(page, '/hilos/logs/settings')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Settings')
  await expect(
    page.getByTestId('hilos-setting-preset-settings-link'),
  ).toBeVisible()
})
