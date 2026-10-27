import { test, expect, type Page, type TestInfo } from '@playwright/test'

import {
  dismissToasts,
  expectDataFreeDeparture,
  expectTableRecordWrappingStress,
  expectTableRowOrder,
  screenshotTable,
  settleTableLayout,
  shownByTestId,
  watchFirstRowTop,
  watchTableAllocation,
  watchTableGeometry,
  type TableGeometryWatch,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  typeInto,
  gotoPage,
  signUpAdmin,
  expectTableTotal,
  goToLastPage,
  pageBackOnce,
  tableRowKeyByText,
  tableRowKeys,
  tableTotal,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// spec-owner: demo — chat's bots and their admin screen

// Bots admin e2e: /hilos/app/bots draws the bots table over the live socket, and
// the create / edit / delete dialogs round-trip through the backend
// (AdminBotsPage). What the file pins is where a bot turns up in a window after a
// write — the outcomes of the table mockup (mockups/components/table, "a foreign
// new record lands by its place, not by the edge of the list"):
//
// - a bot this tab created applies at once, at the place the sort gives it;
// - a bot another tab created at the tail of a window with room arrives on its own;
// - one created above the window is announced by the strip and moves nothing, and
//   Show asks for the window again at the place the reader stands;
// - one created inside the window is announced by the same strip, and Show brings
//   it in between the rows it belongs to;
// - one another tab renamed into the window is announced by that strip too, and so
//   is one renamed onto the tail of a last page with room — an edited row never
//   comes in on its own;
// - one renamed from above a full last page to past it turns Next on, and the
//   only bot past a page, renamed back above it, turns Next off;
// - a value another tab edited, leaving the row in its place, lands at once,
//   highlighted and with no gate.
//
// The table orders by name ascending, naturally and without case
// (BotsTable::defaultSort, InMemoryTableFilter::compareValues), ten rows a window
// (BotsTable::windowSize). The seed holds twenty bots, Alex … Lily on the first
// page and Marcus … Victor on the second: two FULL pages, so the seed alone has no
// last page with room, and a test that needs one makes it. Every name below is
// chosen against that order — `AAA …` before every seeded bot, `Dave …` between
// Dasha and David (inside the first window), `ZZ …` after all of them.
//
// Counts are read relative to where a test found them, never as a literal: in the
// container a failed attempt is retried on the SAME database, and a bot it left
// behind must not make the retry hopeless. For the same reason the number in an
// `AAA` name falls as time goes on, so the newest `AAA` bot sorts before one an
// earlier attempt left. Resetting the database is the run's job, not the spec's
// (docs/agents/testing.md, re-running tests).
//
// Bots are created with Active off. An active bot starts its agent, and the
// agent's runtime status is a second writer to the same row; an inactive one
// leaves the table moved by nothing but what the test does.
//
// The file runs serially: there is one bots table, and the tests reason about
// exact places and counts in it. In the container there is one worker anyway, but
// the config lets a host run spread tests over workers, where two of them would
// rewrite each other's windows.
//
// The page is an ADMIN-level surface (HIL-652), so every test takes the grant
// first. A second tab of the same browser context inherits the session cookie but
// opens a socket of its own, and the table tells its own writes from foreign ones
// by socket — so a second tab is a foreign writer.

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
test.describe.configure({ mode: 'serial' })

/** Rows in a window of the bots table (BotsTable::windowSize). */
const WINDOW = 10

/**
 * A name that sorts before every seeded bot and before any `AAA` bot an earlier
 * attempt left behind: the table compares the number naturally, and it falls as
 * time goes on.
 *
 * @param stamp The test's time stamp.
 * @returns The bot name.
 */
function nameBeforeAll(stamp: number): string {
  return `AAA ${Number.MAX_SAFE_INTEGER - stamp}`
}

/**
 * Open the bots admin and wait for the live window's first row; the caller is admin already, or a viewer of the admin view mode.
 *
 * @param page The Playwright page.
 */
async function openBots(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/app/bots')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    shownByTestId(page, /^hilos-table-(row|card)-/).first(),
  ).toBeVisible()
}

/**
 * Create an inactive bot through the add dialog.
 *
 * @param page The Playwright page on the bots admin.
 * @param name The bot's name.
 */
async function createBot(page: Page, name: string): Promise<void> {
  await dismissToasts(page)
  await page.getByTestId('hilos-table-main-action').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), 'made by e2e')
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  // Settle before the caller asserts the new row: the save is in flight until the
  // backend echo closes the dialog. Asserting the row through an open dialog races
  // the reply (settle-before-assert).
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Change a bot's description through the edit dialog. The name stays, so the row
 * keeps its place in the order.
 *
 * @param page The Playwright page on the bots admin.
 * @param rowKey The bot's row key.
 * @param description The new description.
 */
async function editBotDescription(
  page: Page,
  rowKey: string,
  description: string,
): Promise<void> {
  await shownByTestId(page, `admin-bots-edit-${rowKey}`).click()
  await typeInto(page.getByTestId('admin-bots-description'), description)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Change a bot's style through the edit dialog.
 *
 * @param page The Playwright page on the bots admin.
 * @param rowKey The bot's row key.
 * @param style The new style.
 */
async function editBotStyle(
  page: Page,
  rowKey: string,
  style: string,
): Promise<void> {
  await shownByTestId(page, `admin-bots-edit-${rowKey}`).click()
  await typeInto(page.getByTestId('admin-bots-style'), style)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Rename a bot through the edit dialog. The caller picks the name for the place
 * it wants: one that keeps the row where it stands, or one that moves it.
 *
 * @param page The Playwright page on the bots admin.
 * @param rowKey The bot's row key.
 * @param name The new name.
 */
async function renameBot(
  page: Page,
  rowKey: string,
  name: string,
): Promise<void> {
  await shownByTestId(page, `admin-bots-edit-${rowKey}`).click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Create bots that sort after every current one until the total fills whole windows.
 *
 * A last page with room would take a rename past it as a tail with a free slot,
 * and Next would stay off for a true reason. The names sort after the bots
 * already in the set, including ones an earlier attempt left behind.
 *
 * @param page The Playwright page on the bots admin.
 * @param stamp The test's time stamp.
 * @returns The names of the bots created, for the caller to delete.
 */
async function fillToWholePages(page: Page, stamp: number): Promise<string[]> {
  const names: string[] = []
  let total = await tableTotal(expect, page)
  while (total % WINDOW !== 0) {
    const name = `ZZ ${stamp} pad ${names.length}`
    await createBot(page, name)
    names.push(name)
    total += 1
    await expectTableTotal(expect, page, total)
  }

  return names
}

/**
 * Delete the padding bots, which stand at the end of the set.
 *
 * @param page The Playwright page on the bots admin.
 * @param names Names {@link fillToWholePages} returned.
 */
async function deletePads(page: Page, names: string[]): Promise<void> {
  if (names.length === 0) {
    return
  }
  await dismissToasts(page)
  await goToLastPage(expect, page)
  for (const name of [...names].reverse()) {
    await deleteBot(page, await tableRowKeyByText(expect, page, name))
  }
}

/**
 * Delete a bot this page shows and wait for it to leave the window.
 *
 * Its controls are what says it left, not its slot: the deleting tab keeps the
 * slot as the table's "Removed" placeholder until the next window
 * (TableViewportController.applyOwnDelta), and the placeholder carries the row's
 * data-id but none of the page's cells.
 *
 * @param page The Playwright page on the bots admin.
 * @param rowKey The bot's row key.
 */
async function deleteBot(page: Page, rowKey: string): Promise<void> {
  await dismissToasts(page)
  await shownByTestId(page, `admin-bots-delete-${rowKey}`).click()
  await clickSubmit(expect, page.getByTestId('admin-bots-delete-confirm'))
  await expect(page.getByTestId('admin-bots-delete-confirm')).toHaveCount(0)
  await expect(page.getByTestId(`admin-bots-delete-${rowKey}`)).toHaveCount(0)
}

/**
 * Count the table windows the server sends a page, from the moment of the call.
 *
 * The one wait with no trace on screen: Show asks for the window again at the
 * place the reader stands, and when nothing arrived after that place the answer
 * draws exactly the rows already drawn. Only the frame says the answer came. Call
 * it before the page opens its socket.
 *
 * @param page The Playwright page to listen on.
 * @returns A reader of the number of `table_window` frames received so far.
 */
function countTableWindows(page: Page): () => number {
  const frames: SocketFrame[] = []
  watchSocketFrames(page, (frame) => frames.push(frame))
  return () => {
    expect(frames.filter(({ failure }) => failure !== undefined)).toEqual([])
    return frames.filter(
      ({ wire }) =>
        wire !== null &&
        typeof wire === 'object' &&
        'type' in wire &&
        wire.type === 'table_window',
    ).length
  }
}

/** Narrow the real server set; the count settles the socket reply. */
async function searchBots(
  page: Page,
  search: string,
  total: number,
): Promise<void> {
  await typeInto(page.getByTestId('hilos-table-search'), search)
  if (total === 0) {
    await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
  } else {
    await expectTableTotal(expect, page, total)
  }
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
}

/** Apply pending membership/order changes without requesting another window. */
async function applyBotChanges(page: Page): Promise<void> {
  await clickSubmit(expect, page.getByTestId('hilos-table-apply'))
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
}

/** Both mounted branches owe only the static removal sentence. */
async function expectBotDeparture(page: Page, key: string): Promise<void> {
  await expectDataFreeDeparture(expect, page, key, 'Removed', [
    `admin-bots-edit-${key}`,
    `admin-bots-delete-${key}`,
  ])
  for (const prefix of ['row', 'card']) {
    await expect(page.getByTestId(`hilos-table-${prefix}-${key}`)).toHaveText(
      'Removed',
    )
  }
}

/** Retained slots, including zero live members, draw no empty-state tile. */
async function expectNoBotEmptyTile(page: Page): Promise<void> {
  for (const id of [
    'hilos-table-empty',
    'hilos-table-empty-page',
    'hilos-table-no-matches',
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
}

/** Save inner table frames with their physical transport and outgoing descriptors. */
function watchBotFrames(page: Page) {
  const frames: {
    time: number
    direction: string
    frame: unknown
    transport?: SocketFrame
  }[] = []
  function save(direction: string, frame: unknown, transport?: SocketFrame) {
    if (
      frame !== null &&
      typeof frame === 'object' &&
      'type' in frame &&
      typeof frame.type === 'string' &&
      frame.type.startsWith('table_')
    ) {
      frames.push({ time: Date.now(), direction, frame, transport })
    }
  }
  watchSocketFrames(page, (transport) => {
    save('received', transport.wire, transport)
  })
  page.on('websocket', (socket) => {
    socket.on('framesent', (event) => {
      try {
        save('sent', JSON.parse(event.payload.toString()))
      } catch {
        // Keepalive text is not a table frame.
      }
    })
  })
  return frames
}

/** Preserve the entire table and all fractional intermediate frames before checking. */
async function botGeometryEvidence(
  watch: TableGeometryWatch,
  page: Page,
  info: TestInfo,
  name: string,
): Promise<void> {
  await dismissToasts(page)
  await settleTableLayout(page)
  await watch.stop()
  await info.attach(`${name}-bounds`, {
    body: await watch.evidence(),
    contentType: 'application/json',
  })
  await info.attach(`${name}-layout`, {
    body: await screenshotTable(expect, page),
    contentType: 'image/png',
  })
  await watch.check()
}

/** Create an isolated real set and a cold reader on a second independent socket. */
async function botProofSet(
  page: Page,
  size = 6,
  placement: 'search' | 'last-page' = 'search',
) {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const lastPage = placement === 'last-page'
  const pads = lastPage ? await fillToWholePages(page, Date.now() - 1000) : []
  const base = await tableTotal(expect, page)
  const prefix = `${lastPage ? 'ZZ' : 'Geo'} ${Date.now()}`
  if (!lastPage) await searchBots(page, prefix, 0)
  const names = Array.from({ length: size }, (_, index) => {
    const title =
      index === 1
        ? 'Wide water willow '.repeat(12)
        : index === 2
          ? 'A surviving paragraph stands near the wrapping boundary '.repeat(3)
          : `ordinary ${index}`
    return `${prefix} ${(index + 1) * 10} ${title}`.trim()
  })
  const keys: string[] = []
  for (const name of names) {
    await createBot(page, name)
    if (lastPage) {
      await dismissToasts(page)
      await goToLastPage(expect, page)
    }
    keys.push(await tableRowKeyByText(expect, page, name))
  }
  const reader = await page.context().newPage()
  await reader.setViewportSize({ width: 1440, height: 1000 })
  const windows = countTableWindows(reader)
  const frames = watchBotFrames(reader)
  await openBots(reader)
  if (lastPage) {
    await goToLastPage(expect, reader)
    await expectTableTotal(expect, reader, base + size)
  } else {
    await searchBots(reader, prefix, size)
  }
  await expectTableRowOrder(expect, reader, keys)
  await settleTableLayout(reader)
  return { prefix, names, keys, reader, windows, frames, base, pads }
}

test('a bot created here takes the place the sort gives it, and edits and deletes live', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const description = `edited by e2e ${stamp}`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await openBots(page)
  const loadsAfterColdLoad = fullLoads
  const base = await tableTotal(expect, page)

  // Create: the row goes in at once where the order puts it — first, not at the
  // tail — and no Apply stands between the author and its own row.
  await createBot(page, name)
  await expectTableTotal(expect, page, base + 1)
  const key = await tableRowKeyByText(expect, page, name)
  expect((await tableRowKeys(page))[0]).toBe(key)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Edit the description: the value lands in place, the row keeps its slot.
  await editBotDescription(page, key, description)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    description,
  )
  expect((await tableRowKeys(page))[0]).toBe(key)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Erase the description: an optional field left empty is written empty, not
  // skipped, so the cell clears and the dialog reopens on an empty field.
  await editBotDescription(page, key, '')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).not.toContainText(
    description,
  )
  await shownByTestId(page, `admin-bots-edit-${key}`).click()
  await expect(page.getByTestId('admin-bots-description')).toHaveValue('')
  await page.getByTestId('modal-close').click()
  await expect(page.getByTestId('modal')).toBeHidden()

  // Delete: the bot leaves the window and the count goes back.
  await deleteBot(page, key)
  await expectTableTotal(expect, page, base)

  // The whole tour stayed in one live document.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('a bot created in another tab arrives on its own at the tail of a window with room', async ({
  page,
}) => {
  const stamp = Date.now()
  const filler = `ZZ ${stamp} filler`
  const tail = `ZZ ${stamp} tail`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const base = await tableTotal(expect, page)

  // Tab A makes the page with room: the filler sorts after every bot, onto a page
  // A is not showing, so A sees the count and not the row.
  await createBot(page, filler)
  await expectTableTotal(expect, page, base + 1)

  // Tab B opens cold after it and goes to that last page, where the filler is the
  // last row of a window with room.
  const tabB = await page.context().newPage()
  let tabBLoads = 0
  tabB.on('load', () => {
    tabBLoads += 1
  })
  await openBots(tabB)
  await expectTableTotal(expect, tabB, base + 1)
  await goToLastPage(expect, tabB)
  const tabBLoadsAfterColdLoad = tabBLoads
  const fillerKey = await tableRowKeyByText(expect, tabB, filler)
  const keysBefore = await tableRowKeys(tabB)
  expect(keysBefore.at(-1)).toBe(fillerKey)
  expect(keysBefore.length).toBeLessThan(WINDOW)

  // A creates the tail. At B it sorts after the last row shown, into the free
  // slot, and shifts nothing: it arrives on its own, with no Apply, no strip and
  // no document reload.
  await createBot(page, tail)
  const tailKey = await tableRowKeyByText(expect, tabB, tail)
  expect(await tableRowKeys(tabB)).toEqual([...keysBefore, tailKey])
  await expectTableTotal(expect, tabB, base + 2)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-announce')).toHaveCount(0)
  expect(tabBLoads).toBe(tabBLoadsAfterColdLoad)

  // Cleanup: B is the tab that shows both.
  await deleteBot(tabB, tailKey)
  await deleteBot(tabB, fillerKey)
  await tabB.close()
})

test('a bot created above the window is announced, and Show brings the window level', async ({
  page,
}) => {
  const name = nameBeforeAll(Date.now())

  await signUpAdmin(expect, page, { entryId: 'message-signin' })

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(page)
  await openBots(tabB)
  await goToLastPage(expect, tabB)
  const base = await tableTotal(expect, tabB)
  const keysBefore = await tableRowKeys(tabB)
  // Where B's first row stands before anything is said: the strip comes into room
  // the table already held, so neither its arrival nor its leaving moves a row.
  const rowTop = await watchFirstRowTop(tabB)

  // A creates a bot that sorts before every other: first on A's own window, and
  // on a page above the one B is standing on.
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  // B is told, and nothing moves: the strip names the row, the rows shown are the
  // rows that were shown, and nothing waits behind Apply.
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expect(strip).not.toContainText('above')
  await expect(tabB.getByTestId('hilos-table-announce-show')).toBeVisible()
  await expectTableTotal(expect, tabB, base + 1)
  await expect(tabB.getByTestId('hilos-table-next')).toBeDisabled()
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await rowTop.unchanged()

  // Show asks for the window again at the place B stands, the same answer a
  // reload of that window gives: the strip goes, and a window arrives holding the
  // rows after that place — which a row above it did not touch.
  const tableWindowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(tableWindowsBeforeShow)
  await expect(strip).toHaveCount(0)
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await rowTop.unchanged()
  await expectTableTotal(expect, tabB, base + 1)
  await expect(tabB.getByTestId('hilos-table-next')).toBeDisabled()
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Cleanup: A shows the bot it made.
  await tabB.close()
  await deleteBot(page, key)
})

test('Next still reaches the rows below after bots above the window are deleted', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const base = await tableTotal(expect, page)
  const stamp = Date.now()
  const created: string[] = []
  for (let index = 0; index < 3; index += 1) {
    const name = nameBeforeAll(stamp + index)
    await createBot(page, name)
    created.push(await tableRowKeyByText(expect, page, name))
  }

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await expectTableTotal(expect, tabB, base + created.length)
  await goToLastPage(expect, tabB)
  const tailKeys = await tableRowKeys(tabB)
  expect(tailKeys.length).toBeGreaterThan(0)
  await pageBackOnce(expect, tabB)
  const keysBefore = await tableRowKeys(tabB)
  const next = tabB.getByTestId('hilos-table-next')
  await expect(next).toBeEnabled()

  // B keeps the delivered window's place. Deleting rows before it must not spend
  // the rows still standing after it, even when the live count loses a whole page.
  for (const key of created) {
    await deleteBot(page, key)
  }
  await expectTableTotal(expect, tabB, base)
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(next).toBeEnabled()

  await next.click()
  await expect.poll(() => tableRowKeys(tabB)).toEqual(tailKeys)
  await expect(tabB.getByTestId('hilos-table-empty-page')).toHaveCount(0)
  await expect(next).toBeDisabled()
  await tabB.close()
})

test('Next turns on when another tab renames a bot from above a full last page to past it', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const past = `ZZ ${stamp} zzz`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const pads = await fillToWholePages(page, stamp)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await goToLastPage(expect, tabB)
  const next = tabB.getByTestId('hilos-table-next')
  await expect(next).toBeDisabled()

  // A bot before every other stands above B. The strip says so, and a full last
  // page still has nothing after it, so Next stays off.
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expect(next).toBeDisabled()
  const total = await tableTotal(expect, tabB)

  // The same bot, renamed past every row B shows, is now the next page. The
  // total does not move; Next does.
  await renameBot(page, key, past)
  await expectTableTotal(expect, tabB, total)
  await expect(next).toBeEnabled()
  await next.click()
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  await expect(tabB.getByTestId('hilos-table-empty-page')).toHaveCount(0)

  await deleteBot(tabB, key)
  await tabB.close()
  await deletePads(page, pads)
})

test('Next turns off when the only bot past the window is renamed above it', async ({
  page,
}) => {
  const stamp = Date.now()
  const past = `ZZ ${stamp} zzz`
  const name = nameBeforeAll(stamp)

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const pads = await fillToWholePages(page, stamp)
  await createBot(page, past)
  await dismissToasts(page)
  await goToLastPage(expect, page)
  const key = await tableRowKeyByText(expect, page, past)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await goToLastPage(expect, tabB)
  await pageBackOnce(expect, tabB)
  const next = tabB.getByTestId('hilos-table-next')
  await expect(next).toBeEnabled()
  const total = await tableTotal(expect, tabB)

  // The only row after B moves above it. The total stays, and Next has nowhere
  // left to go.
  await renameBot(page, key, name)
  await expectTableTotal(expect, tabB, total)
  await expect(next).toBeDisabled()

  await tabB.close()
  await openBots(page)
  await deleteBot(page, await tableRowKeyByText(expect, page, name))
  await deletePads(page, pads)
})

test('after Show the footer names the tail, Next is off, and Back reaches the first row', async ({
  page,
}) => {
  const name = nameBeforeAll(Date.now())

  await signUpAdmin(expect, page, { entryId: 'message-signin' })

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(page)
  await openBots(tabB)
  // Two full pages of ten from the seed, so B stands on the last one and the row A is
  // about to create takes the count over a page boundary: this is the shape the defect
  // was found in (HIL-1093).
  await goToLastPage(expect, tabB)
  const base = await tableTotal(expect, tabB)
  const keysBefore = await tableRowKeys(tabB)
  const next = tabB.getByTestId('hilos-table-next')
  const prev = tabB.getByTestId('hilos-table-prev')
  // Read off what the table was found holding, never written as literals: a retry runs
  // against the same database, and a bot an earlier attempt left behind would otherwise
  // make the arithmetic below wrong rather than the behavior.
  const lastPage = Math.ceil(base / WINDOW)
  const pagesAfter = Math.ceil((base + 1) / WINDOW)

  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expect(strip).not.toContainText('above')
  const windowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(windowsBeforeShow)
  await expect(strip).toHaveCount(0)

  // The window kept its rows and moved through the set: it now ends the set, so the
  // footer names the page its FIRST ROW sits on and Next has nothing to offer. Counting
  // presses instead would read this as page two of three and offer a Next into nothing.
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expectTableTotal(expect, tabB, base + 1)
  await expect(
    tabB.locator(
      `[data-id="hilos-table-page-${lastPage}"][aria-current="page"]`,
    ),
  ).toBeVisible()
  await expect(tabB.getByTestId(`hilos-table-page-${pagesAfter}`)).toHaveCount(
    1,
  )
  await expect(tabB.getByTestId('hilos-table-count')).toHaveText(
    new RegExp(`– ${base + 1} of ${base + 1}\\s*$`),
  )
  await expect(next).toBeDisabled()
  await expect(prev).toBeEnabled()

  // Back walks to the top of the set. The second step is the one a page counter could
  // not take: less than a page stands to the left, so the window asked for is the START
  // of the set rather than the ten rows before its own first one.
  await pageBackOnce(expect, tabB)
  await expect(prev).toBeEnabled()
  await pageBackOnce(expect, tabB)
  expect((await tableRowKeys(tabB))[0]).toBe(key)
  await expect(prev).toBeDisabled()
  await expect(
    tabB.locator('[data-id="hilos-table-page-1"][aria-current="page"]'),
  ).toBeVisible()
  await expect(tabB.getByTestId('hilos-table-count')).toHaveText(/^\s*1 – /)

  // Cleanup: B holds the bot A made.
  await deleteBot(tabB, key)
  await tabB.close()
})

test('a bot created inside the window is announced by the same strip, and Show brings it between the rows', async ({
  page,
}) => {
  const name = `Dave ${Date.now()}`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(page)
  await openBots(tabB)
  const base = await tableTotal(expect, tabB)
  const keysBefore = await tableRowKeys(tabB)
  const rowTop = await watchFirstRowTop(tabB)

  // A creates a bot that sorts between Dasha and David, between two rows both
  // tabs are showing on the first page.
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  // B is told by the strip a row above the window raises, in the same words: it
  // names no place (HIL-1026). Nothing moves until the reader asks — the rows shown
  // are the rows that were shown, and nothing waits behind Apply.
  await expectTableTotal(expect, tabB, base + 1)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expect(strip).not.toContainText('above')
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await rowTop.unchanged()

  // Show asks for the window again at the same place, the start of the set: the
  // new row stands between the rows it sorts between, the rows below it move down
  // by one, and the last of them leaves for the next page.
  const tableWindowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(tableWindowsBeforeShow)
  await expect(strip).toHaveCount(0)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  const keysAfter = await tableRowKeys(tabB)
  expect(keysAfter).toHaveLength(keysBefore.length)
  expect(keysAfter.filter((rowKey) => rowKey !== key)).toEqual(
    keysBefore.slice(0, -1),
  )
  await rowTop.unchanged()

  // Cleanup: A shows the bot it made.
  await tabB.close()
  await deleteBot(page, key)
})

test('a bot another tab renamed into the window is announced by the same strip, and Show brings it between the rows', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = `ZZ ${stamp} renamed`
  const renamed = `Dave ${stamp}`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)

  // A creates the bot BEFORE B opens, past every other bot, so B's cold first
  // window does not hold it and its count already has it. A follows it to the
  // last page, where its edit button is — once the notice of the create, which
  // stands over the pager, is out of the way.
  await createBot(page, name)
  await dismissToasts(page)
  await goToLastPage(expect, page)
  const key = await tableRowKeyByText(expect, page, name)

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(tabB)
  const base = await tableTotal(expect, tabB)
  const keysBefore = await tableRowKeys(tabB)
  const rowTop = await watchFirstRowTop(tabB)

  // A renames it between Dasha and David, between two rows B is showing. B has
  // not seen this row here, so it is told as of a new one — the same strip, in
  // the same words. The set did not grow, so the total stays; nothing moves.
  await renameBot(page, key, renamed)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expect(strip).not.toContainText('above')
  await expectTableTotal(expect, tabB, base)
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await rowTop.unchanged()

  // Show asks for the window again at the start of the set: the row stands
  // between the rows it sorts between, and the last of the window leaves for the
  // next page — what a reload shows.
  const tableWindowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(tableWindowsBeforeShow)
  await expect(strip).toHaveCount(0)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  const keysAfter = await tableRowKeys(tabB)
  expect(keysAfter).toHaveLength(keysBefore.length)
  expect(keysAfter.filter((rowKey) => rowKey !== key)).toEqual(
    keysBefore.slice(0, -1),
  )
  await expectTableTotal(expect, tabB, base)

  // Cleanup: B shows the bot now.
  await deleteBot(tabB, key)
  await tabB.close()
})

test('a bot another tab renamed onto the tail of a last page with room is announced, not put in', async ({
  page,
}) => {
  const stamp = Date.now()
  const filler = `ZZ ${stamp} filler`
  const name = nameBeforeAll(stamp)
  const tail = `ZZ ${stamp} tail`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)

  // A makes the page with room — the filler sorts after every bot — and a bot
  // first in the order, on its own first page, before B opens.
  await createBot(page, filler)
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(tabB)
  await goToLastPage(expect, tabB)
  const base = await tableTotal(expect, tabB)
  const fillerKey = await tableRowKeyByText(expect, tabB, filler)
  const keysBefore = await tableRowKeys(tabB)
  expect(keysBefore.at(-1)).toBe(fillerKey)
  expect(keysBefore.length).toBeLessThan(WINDOW)

  // A renames it past the filler, into B's free slot. A created row would
  // arrive there on its own; an edited one is announced instead, since a row
  // that left this page earlier may still stand on it with its mark.
  await renameBot(page, key, tail)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row')
  await expectTableTotal(expect, tabB, base)
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Show brings the window again after the place B stands: the row stands last.
  const tableWindowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(tableWindowsBeforeShow)
  await expect(strip).toHaveCount(0)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  expect(await tableRowKeys(tabB)).toEqual([...keysBefore, key])

  // Cleanup: B is the tab that shows both.
  await deleteBot(tabB, key)
  await deleteBot(tabB, fillerKey)
  await tabB.close()
})

test('a value edited in another tab lands in place, highlighted and with no gate', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const description = `edited by e2e ${stamp}`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)

  // A creates the bot BEFORE B opens, so B's cold first window already holds it
  // and no announcement takes part.
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  const row = tabB.getByTestId(`hilos-table-row-${key}`)
  await expect(row).toBeVisible()

  await editBotDescription(page, key, description)

  // The class first. The highlight lasts two seconds (HIGHLIGHT_MS in
  // TableViewportController) and, unlike every other cap of the run, is not
  // stretched by host load; the dialog at A closes on the server's reply, after the
  // change went out, so the two seconds are plenty — as long as no other wait
  // stands in front of this one.
  await expect(row).toHaveClass(/\btable-success\b/)
  await expect(row).toContainText(description)
  const pendingMove = tabB.getByTestId(`hilos-table-pending-move-${key}`)
  const pendingRemove = tabB.getByTestId(`hilos-table-pending-remove-${key}`)
  await expect(pendingMove).toHaveCount(0)
  await expect(pendingRemove).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Cleanup: A shows the bot it made.
  await tabB.close()
  await deleteBot(page, key)
})

test('the last foreign deletion retains a data-free slot until an explicit window change', async ({
  page,
}) => {
  const name = nameBeforeAll(Date.now())
  const windows = countTableWindows(page)

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)
  await searchBots(page, name, 1)
  const tabB = await page.context().newPage()
  await openBots(tabB)
  await searchBots(tabB, name, 1)
  const beforeApply = windows()

  await deleteBot(tabB, key)
  await expectTableTotal(expect, page, 0)
  await expect(
    shownByTestId(page, `hilos-table-pending-remove-${key}`),
  ).toBeVisible()
  await expect(shownByTestId(page, `hilos-table-row-${key}`)).toContainText(
    name,
  )
  await applyBotChanges(page)

  await expectBotDeparture(page, key)
  await expect(page.getByTestId('hilos-table-count')).toHaveText('0 – 0 of 0')
  await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(2)
  await expectNoBotEmptyTile(page)
  expect(windows()).toBe(beforeApply)

  // The new search asks the server for a new empty snapshot. Apply did not.
  await searchBots(page, `${name} absent`, 0)
  await expect.poll(windows).toBeGreaterThan(beforeApply)
  await expect(shownByTestId(page, 'hilos-table-no-matches')).toContainText(
    'Nothing found',
  )
  await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
  await expect(page.getByTestId(`hilos-table-card-${key}`)).toHaveCount(0)
  await tabB.close()
})

// HIL-1051: the edit and the delete dialogs merge against the live row through
// the shared row-edit helper. Two tabs of one context over a bot A creates first
// in the window; every value B writes keeps the row there, so A's dialogs keep
// their live row throughout. The edit dialog has six fields, so what it says
// names the field.
test('an open edit follows the other tab field by field, and a delete dialog reads Deleted', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const renamed = `${name} renamed`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()

  // A opens the edit and touches nothing; B changes ONLY Style (not in any table
  // column — reads test): it lands in A's form, the notice names Style, and
  // Save stays locked.
  await shownByTestId(page, `admin-bots-edit-${key}`).click()
  await expect(page.getByTestId('admin-bots-description')).toBeVisible()
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()
  await editBotStyle(tabB, key, `style elsewhere ${stamp}`)
  await expect(page.getByTestId('admin-bots-style')).toHaveValue(
    `style elsewhere ${stamp}`,
  )
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    'Updated just now: Style',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()

  // B changes the description: it lands in A's form too, and the notice names
  // both fields in form order.
  await editBotDescription(tabB, key, `elsewhere one ${stamp}`)
  await expect(page.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere one ${stamp}`,
  )
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    'Updated just now: Description, Style',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()

  // A changes the style; B changes the description again: A left that field
  // alone, so it takes the new value silently, and the style stays A's.
  await typeInto(page.getByTestId('admin-bots-style'), `style of A ${stamp}`)
  await expect(page.getByTestId('admin-bots-save')).toBeEnabled()
  await editBotDescription(tabB, key, `elsewhere two ${stamp}`)
  await expect(page.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere two ${stamp}`,
  )
  await expect(page.getByTestId('admin-bots-style')).toHaveValue(
    `style of A ${stamp}`,
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)

  // A changes the description too; B changes it a third time: a conflict on
  // that field, Save locked, no Merge.
  await typeInto(
    page.getByTestId('admin-bots-description'),
    `description of A ${stamp}`,
  )
  await editBotDescription(tabB, key, `elsewhere three ${stamp}`)
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    `Description changed elsewhere to "elsewhere three ${stamp}".`,
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()

  // Take theirs puts B's description into the form; the style is still A's, so
  // Save opens and sends both. B then sees A's style beside its own description.
  await page.getByTestId('conflict-accept-theirs').click()
  await expect(page.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere three ${stamp}`,
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  await shownByTestId(tabB, `admin-bots-edit-${key}`).click()
  await expect(tabB.getByTestId('admin-bots-style')).toHaveValue(
    `style of A ${stamp}`,
  )
  await expect(tabB.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere three ${stamp}`,
  )
  await expect(tabB.getByTestId('admin-bots-save')).toBeDisabled()
  await tabB.getByTestId('modal-close').click()
  await expect(tabB.getByTestId('admin-bots-save')).toHaveCount(0)

  // The delete dialog reads the live row: B renames the bot and A's dialog
  // shows the new name; B deletes it and A's button reads Deleted, locked, with
  // the reason on the message line. Cancel is all that is left to press.
  await shownByTestId(page, `admin-bots-delete-${key}`).click()
  await expect(page.getByTestId('admin-bots-delete-confirm')).toBeEnabled()
  await renameBot(tabB, key, renamed)
  await expect(page.getByTestId('modal')).toContainText(renamed)
  await deleteBot(tabB, key)
  await expect(page.getByTestId('admin-bots-delete-confirm')).toHaveText(
    'Deleted',
  )
  await expect(page.getByTestId('admin-bots-delete-confirm')).toBeDisabled()
  await expect(page.getByTestId('admin-bots-delete-notice')).toContainText(
    'Deleted elsewhere.',
  )
  await page.getByTestId('modal-close').click()
  await expect(page.getByTestId('admin-bots-delete-confirm')).toHaveCount(0)
  await tabB.close()
})

test('an open edit follows its row past the window edge, and conflicts after it', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  // A name past every seeded bot: the rename takes the row past the bottom of
  // the first window. (This table cannot say whether a row left a search, so a
  // search never takes a row off the screen; the order does.)
  const renamed = `ZZ ${stamp}`

  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  await createBot(page, name)
  const key = await tableRowKeyByText(expect, page, name)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()

  // A opens the edit; B renames the bot past the window: A's row will leave,
  // and A's form takes the name silently — the dialog holds the row in focus,
  // and the frame that takes the row off the screen carries it (HIL-1050).
  await shownByTestId(page, `admin-bots-edit-${key}`).click()
  await expect(page.getByTestId('admin-bots-name')).toHaveValue(name)
  await renameBot(tabB, key, renamed)
  await expect(
    shownByTestId(page, `hilos-table-pending-remove-${key}`),
  ).toBeVisible()
  await expect(page.getByTestId('admin-bots-name')).toHaveValue(renamed)
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    'Updated just now: Name',
  )
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()

  // B follows the bot to its new place by name. A types a description; B
  // changes it too, with the row outside A's window: the server follows the row
  // for the dialog, and the dialog conflicts. Take theirs puts B's description
  // in, and there is nothing left to save.
  await typeInto(tabB.getByTestId('hilos-table-search'), renamed)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
  await typeInto(
    page.getByTestId('admin-bots-description'),
    `description of A ${stamp}`,
  )
  await editBotDescription(tabB, key, `elsewhere ${stamp}`)
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    `Description changed elsewhere to "elsewhere ${stamp}".`,
  )
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()
  await page.getByTestId('conflict-accept-theirs').click()
  await expect(page.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere ${stamp}`,
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()
  await page.getByTestId('modal-close').click()
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

  await deleteBot(tabB, key)
  await tabB.close()
})

test('reaches the bots admin from the dashboard', async ({ page }) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await gotoPage(expect, page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_bots').click()
  await expect(page.getByTestId('admin-bots-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/app/bots')
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens the add dialog, fills it in and has nothing to save it with', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await openBots(page)
    await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))

    const name = page.getByTestId('admin-bots-name')
    await typeInto(name, 'Viewer draft')

    const save = page.getByTestId('admin-bots-save')
    await expect(save).toBeDisabled()
    await expect(save).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )

    await clickSubmit(expect, page.getByTestId('admin-bots-cancel'))
    await clickSubmit(expect, page.getByTestId('modal-confirm-discard'))
    await expect(name).toBeHidden()
  })
})

test('Next starts after the rows another tab appended to a last page with room', async ({
  page,
}) => {
  // Filling a last page and then removing every bot takes more round trips than a single write.
  test.slow()
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const base = await tableTotal(expect, page)
  const stamp = Date.now()
  const createdNames: string[] = []
  let sequence = 0
  const nextName = () => {
    const name = `ZZ ${stamp} ${String(sequence).padStart(3, '0')}`
    sequence += 1
    createdNames.push(name)
    return name
  }

  // B must open with a partial last page, even if an earlier failed attempt left bots behind.
  const setupCount =
    base % WINDOW === 0 ? 1 : base % WINDOW === WINDOW - 1 ? 2 : 0
  for (let index = 0; index < setupCount; index += 1) {
    await createBot(page, nextName())
  }

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await goToLastPage(expect, tabB)
  let keys = await tableRowKeys(tabB)
  expect(keys.length).toBeLessThan(WINDOW)

  // Every next name is greater than the last, so these frames must be tail appends.
  while (keys.length < WINDOW) {
    const name = nextName()
    await createBot(page, name)
    const key = await tableRowKeyByText(expect, tabB, name)
    await expect.poll(() => tableRowKeys(tabB)).toEqual([...keys, key])
    keys = [...keys, key]
  }

  const extraName = nextName()
  await createBot(page, extraName)
  await expectTableTotal(expect, tabB, base + createdNames.length)
  const next = tabB.getByTestId('hilos-table-next')
  await expect(next).toBeEnabled()
  await next.click()
  const extraKey = await tableRowKeyByText(expect, tabB, extraName)
  expect(await tableRowKeys(tabB)).toEqual([extraKey])
  await tabB.close()

  // Remove in descending order: the next bot to erase remains on the last page.
  for (const name of createdNames.reverse()) {
    await openBots(page)
    await goToLastPage(expect, page)
    await deleteBot(page, await tableRowKeyByText(expect, page, name))
  }
})

test('Next retrieves the row an own create pushed below the first page', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const base = await tableTotal(expect, page)
  const keysBefore = await tableRowKeys(page)
  expect(keysBefore).toHaveLength(WINDOW)
  const lastKey = keysBefore.at(-1)
  const name = `Dave ${Date.now()}`

  await createBot(page, name)
  await expectTableTotal(expect, page, base + 1)
  const key = await tableRowKeyByText(expect, page, name)
  expect(await tableRowKeys(page)).not.toContain(lastKey)

  await page.getByTestId('hilos-table-next').click()
  await expect.poll(async () => (await tableRowKeys(page))[0]).toBe(lastKey)
  await pageBackOnce(expect, page)
  await deleteBot(page, key)
})

test('an own create on a Back window pushes its first row above and Back retrieves it', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await openBots(page)
  const base = await tableTotal(expect, page)
  const firstPageKeys = await tableRowKeys(page)
  expect(firstPageKeys).toHaveLength(WINDOW)
  const topKey = firstPageKeys[0]

  await page.getByTestId('hilos-table-next').click()
  await expect.poll(async () => (await tableRowKeys(page))[0]).not.toBe(topKey)
  await pageBackOnce(expect, page)
  expect(await tableRowKeys(page)).toEqual(firstPageKeys)

  const name = `Dave ${Date.now()}`
  await createBot(page, name)
  await expectTableTotal(expect, page, base + 1)
  const key = await tableRowKeyByText(expect, page, name)
  const dashaKey = await tableRowKeyByText(expect, page, 'Dasha')
  const davidKey = await tableRowKeyByText(expect, page, 'David')
  const after = await tableRowKeys(page)
  expect(after).not.toContain(topKey)
  expect(after.indexOf(key)).toBe(after.indexOf(dashaKey) + 1)
  expect(after.indexOf(davidKey)).toBe(after.indexOf(key) + 1)
  await expect(page.getByTestId('hilos-table-prev')).toBeEnabled()

  await pageBackOnce(expect, page)
  expect((await tableRowKeys(page))[0]).toBe(topKey)
  await deleteBot(page, key)
})

test.describe('server geometry', () => {
  const projection = 'wide'
  const recordId = (key: string) => `hilos-table-row-${key}`

  for (const mode of ['sequential', 'batch'] as const) {
    test(`${mode} foreign ordinary/widest/last departures retain all boundaries through zero`, async ({
      page,
    }, info) => {
      // Six real creates/deletes, two sockets and typed long names exceed one CRUD tour.
      test.setTimeout(test.info().timeout * 6)
      const { prefix, names, keys, reader, windows, frames } =
        await botProofSet(page)
      const observed = await watchTableGeometry(expect, reader, {
        bodyId: 'admin-bots-view',
        projection,
        rowKeys: keys.slice(2, 5),
        lastKey: keys[5]!,
      })
      try {
        await info.attach('real-wrapping-condition', {
          body: await expectTableRecordWrappingStress(
            expect,
            reader,
            [keys[1]!, ...keys.filter((_, index) => index !== 1)].map(recordId),
            recordId(keys[2]!),
          ),
          contentType: 'application/json',
        })
        const beforeApply = windows()
        let total = keys.length
        const departures = [0, 1, 5]
        for (const index of departures) {
          const key = keys[index]!
          await deleteBot(page, key)
          total -= 1
          await expectTableTotal(expect, reader, total)
          await expect(
            shownByTestId(reader, `hilos-table-pending-remove-${key}`),
          ).toBeVisible()
          await expect(reader.getByTestId(recordId(key))).toContainText(
            names[index]!,
          )
          await observed.check()
          if (mode === 'sequential') {
            await applyBotChanges(reader)
            await expectBotDeparture(reader, key)
            await observed.check()
          }
        }
        if (mode === 'batch') await applyBotChanges(reader)
        for (const index of departures)
          await expectBotDeparture(reader, keys[index]!)
        await expectTableRowOrder(expect, reader, keys)
        await expect(reader.getByTestId('hilos-table-count')).toHaveText(
          '1 – 3 of 3',
        )
        await expectNoBotEmptyTile(reader)

        // Count reaches zero before Apply; pending rows still carry their last values.
        for (const key of keys.slice(2, 5)) {
          await deleteBot(page, key)
          total -= 1
          await expectTableTotal(expect, reader, total)
          await expect(
            shownByTestId(reader, `hilos-table-pending-remove-${key}`),
          ).toBeVisible()
          await observed.check()
        }
        await applyBotChanges(reader)
        for (const key of keys) await expectBotDeparture(reader, key)
        await expect(reader.getByTestId('hilos-table-count')).toHaveText(
          '0 – 0 of 0',
        )
        await expect(reader.getByTestId('hilos-table-placeholder')).toHaveCount(
          12,
        )
        await expectNoBotEmptyTile(reader)
        expect(windows()).toBe(beforeApply)
        await botGeometryEvidence(observed, reader, info, 'pending-apply-zero')

        // A new server window retires the numeric trace, with no document reload.
        await searchBots(reader, `${prefix} absent`, 0)
        await expect.poll(windows).toBeGreaterThan(beforeApply)
        await expect(
          shownByTestId(reader, 'hilos-table-no-matches'),
        ).toContainText('Nothing found')
        await expect(reader.getByTestId('hilos-table-placeholder')).toHaveCount(
          0,
        )
      } finally {
        await observed.stop()
        await info.attach('server-frames', {
          body: JSON.stringify(frames, null, 2),
          contentType: 'application/json',
        })
        await reader.close()
      }
    })
  }

  test('Apply move and own create use server positions and edge eviction, preserving an unrelated slot', async ({
    page,
  }, info) => {
    // Ten seeded-by-UI records plus move/create/cleanup round trips on both sockets.
    test.setTimeout(test.info().timeout * 8)
    const { prefix, keys, reader, windows, frames } = await botProofSet(
      page,
      10,
    )
    await deleteBot(page, keys[0]!)
    await expect(
      shownByTestId(reader, `hilos-table-pending-remove-${keys[0]}`),
    ).toBeVisible()
    await applyBotChanges(reader)
    await expectBotDeparture(reader, keys[0]!)
    const allocation = await watchTableAllocation(
      expect,
      reader,
      keys[0]!,
      projection,
    )
    try {
      const beforeApply = windows()
      await renameBot(page, keys[9]!, `${prefix} 5 moved`)
      await expect(
        shownByTestId(reader, `hilos-table-pending-move-${keys[9]}`),
      ).toBeVisible()
      await expectTableRowOrder(expect, reader, keys)
      await applyBotChanges(reader)
      await expectTableRowOrder(expect, reader, [
        keys[0]!,
        keys[9]!,
        ...keys.slice(1, 9),
      ])
      await expect(reader.getByTestId(recordId(keys[9]!))).toContainText(
        `${prefix} 5 moved`,
      )
      expect(windows()).toBe(beforeApply)
      await expectBotDeparture(reader, keys[0]!)

      await createBot(reader, `${prefix} 25 created here`)
      const own = await tableRowKeyByText(
        expect,
        reader,
        `${prefix} 25 created here`,
      )
      await expectTableTotal(expect, reader, 10)
      await expectTableRowOrder(expect, reader, [
        keys[0]!,
        keys[9]!,
        keys[1]!,
        own,
        ...keys.slice(2, 9),
      ])
      await expect(reader.getByTestId('hilos-table-apply')).toHaveCount(0)

      // Live capacity is ten; the retained slot neither spends room nor gets evicted.
      await createBot(reader, `${prefix} 15 edge insertion`)
      const edge = await tableRowKeyByText(
        expect,
        reader,
        `${prefix} 15 edge insertion`,
      )
      await expectTableTotal(expect, reader, 11)
      await expectTableRowOrder(expect, reader, [
        keys[0]!,
        keys[9]!,
        edge,
        keys[1]!,
        own,
        ...keys.slice(2, 8),
      ])
      for (const branch of ['row', 'card']) {
        await expect(
          reader.getByTestId(`hilos-table-${branch}-${keys[8]}`),
        ).toHaveCount(0)
      }
      await expectBotDeparture(reader, keys[0]!)
      await expect(reader.getByTestId('hilos-table-next')).toBeEnabled()
      await expect(reader.getByTestId('hilos-table-apply')).toHaveCount(0)

      // An own removal takes effect immediately; another socket receives its gate.
      await deleteBot(reader, own)
      await expectBotDeparture(reader, own)
      await expect(reader.getByTestId('hilos-table-apply')).toHaveCount(0)
      await expectBotDeparture(reader, keys[0]!)
      await botGeometryEvidence(
        allocation,
        reader,
        info,
        'allowed-placement-retained-slot',
      )

      // Refresh only for cleanup: Show/window changes may legitimately retire the trace.
      await openBots(page)
      await searchBots(page, prefix, 10)
      for (const key of [...keys.slice(1), edge]) await deleteBot(page, key)
    } finally {
      await allocation.stop()
      await info.attach('server-frames', {
        body: JSON.stringify(frames, null, 2),
        contentType: 'application/json',
      })
      if (!reader.isClosed()) await reader.close()
    }
  })

  test('nonzero foreign and own server moves use live rank across preceding and interleaved departures', async ({
    page,
  }, info) => {
    // Six UI creates, two deletions and two renames on independent sockets.
    test.setTimeout(test.info().timeout * 6)
    const { prefix, keys, reader, windows, frames } = await botProofSet(page)
    const allocations: TableGeometryWatch[] = []
    try {
      for (const key of [keys[0]!, keys[3]!]) {
        await deleteBot(page, key)
        await expect(
          shownByTestId(reader, `hilos-table-pending-remove-${key}`),
        ).toBeVisible()
        await applyBotChanges(reader)
        await expectBotDeparture(reader, key)
        allocations.push(
          await watchTableAllocation(expect, reader, key, projection),
        )
      }
      await expectTableRowOrder(expect, reader, keys)
      const beforeApply = windows()
      await renameBot(page, keys[5]!, `${prefix} 25 foreign move`)
      await expect(
        shownByTestId(reader, `hilos-table-pending-move-${keys[5]}`),
      ).toBeVisible()
      expect(frames).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            direction: 'received',
            frame: expect.objectContaining({
              type: 'table_viewport_delta',
              data: expect.objectContaining({
                page: 'admin_bots',
                tableKey: 'bots',
                kind: 'row_moved',
                rowKey: Number(keys[5]),
                position: 1,
              }),
            }),
          }),
        ]),
      )
      // Before Apply the foreign row still stands in its old place.
      await expectTableRowOrder(expect, reader, keys)
      await applyBotChanges(reader)
      await expectTableRowOrder(expect, reader, [
        keys[0]!,
        keys[1]!,
        keys[5]!,
        keys[2]!,
        keys[3]!,
        keys[4]!,
      ])
      await expect(reader.getByTestId(recordId(keys[5]!))).toContainText(
        `${prefix} 25 foreign move`,
      )
      for (const watch of allocations) await watch.check()

      await renameBot(reader, keys[4]!, `${prefix} 22 own move`)
      await expectTableRowOrder(expect, reader, [
        keys[0]!,
        keys[1]!,
        keys[4]!,
        keys[5]!,
        keys[2]!,
        keys[3]!,
      ])
      expect(frames).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            direction: 'received',
            frame: expect.objectContaining({
              type: 'table_viewport_delta',
              data: expect.objectContaining({
                page: 'admin_bots',
                tableKey: 'bots',
                kind: 'row_moved',
                rowKey: Number(keys[4]),
                position: 1,
                own: true,
              }),
            }),
          }),
        ]),
      )
      await expect(reader.getByTestId(recordId(keys[4]!))).toContainText(
        `${prefix} 22 own move`,
      )
      await expect(reader.getByTestId('hilos-table-apply')).toHaveCount(0)
      expect(windows()).toBe(beforeApply)
      await expectTableTotal(expect, reader, 4)
      for (const [index, key] of [keys[0]!, keys[3]!].entries()) {
        await expectBotDeparture(reader, key)
        await botGeometryEvidence(
          allocations[index]!,
          reader,
          info,
          `nonzero-move-unrelated-${index}`,
        )
      }
      // Refresh only the actor for cleanup; the reader's trace stays live.
      await openBots(page)
      await searchBots(page, prefix, 4)
      for (const key of [keys[1]!, keys[2]!, keys[4]!, keys[5]!])
        await deleteBot(page, key)
    } finally {
      for (const watch of allocations) await watch.stop()
      await info.attach('server-frames', {
        body: JSON.stringify(frames, null, 2),
        contentType: 'application/json',
      })
      if (!reader.isClosed()) await reader.close()
    }
  })

  test('foreign append follows mixed and all-placeholder slots; Apply never substitutes for Show', async ({
    page,
  }, info) => {
    // Bots cannot classify membership under search (containsRow returns null).
    // The unfiltered last page supplies real append/announce with seed rows intact.
    // Repeated real append/delete cycles and Show exceed one CRUD tour's cap.
    test.setTimeout(test.info().timeout * 7)
    const { prefix, keys, reader, windows, frames, base, pads } =
      await botProofSet(page, 6, 'last-page')
    const watches: TableGeometryWatch[] = []
    const watch = async (lastKey: string) => {
      const observed = await watchTableGeometry(expect, reader, {
        bodyId: 'admin-bots-view',
        projection,
        rowKeys: keys.slice(2, 5),
        lastKey,
      })
      watches.push(observed)
      return observed
    }
    try {
      const mixed = await watch(keys[5]!)
      for (const key of [keys[0]!, keys[1]!, keys[5]!])
        await deleteBot(page, key)
      await expectTableTotal(expect, reader, base + 3)
      await applyBotChanges(reader)
      for (const key of [keys[0]!, keys[1]!, keys[5]!])
        await expectBotDeparture(reader, key)
      await createBot(page, `${prefix} 70 mixed tail`)
      const tail = await tableRowKeyByText(
        expect,
        reader,
        `${prefix} 70 mixed tail`,
      )
      await expectTableRowOrder(expect, reader, [...keys, tail])
      await expect(reader.getByTestId('hilos-table-count')).toHaveText(
        `${base + 1} – ${base + 4} of ${base + 4}`,
      )
      await expect(reader.getByTestId('hilos-table-announce')).toHaveCount(0)
      await botGeometryEvidence(mixed, reader, info, 'mixed-append-old-extent')

      const zero = await watch(tail)
      for (const key of [...keys.slice(2, 5), tail]) await deleteBot(page, key)
      await expectTableTotal(expect, reader, base)
      await applyBotChanges(reader)
      for (const key of [...keys, tail]) await expectBotDeparture(reader, key)
      await expect(reader.getByTestId('hilos-table-count')).toHaveText(
        `0 – 0 of ${base}`,
      )
      await expectNoBotEmptyTile(reader)
      await botGeometryEvidence(
        zero,
        reader,
        info,
        'all-placeholder-departures',
      )
      // This cold window was shorter than its limit, so the server knows its
      // end even without a source frame. Repeated real appends/departures
      // grow the trace past ten slots while live capacity remains one.
      const retained = [...keys, tail]
      let lastRetained = tail
      for (const ordinal of [80, 90, 100]) {
        const appended = await watch(lastRetained)
        const name = `${prefix} ${ordinal} cycle tail`
        await createBot(page, name)
        const key = await tableRowKeyByText(expect, reader, name)
        await expectTableRowOrder(expect, reader, [...retained, key])
        await expect(reader.getByTestId('hilos-table-count')).toHaveText(
          `${base + 1} – ${base + 1} of ${base + 1}`,
        )
        await expect(reader.getByTestId('hilos-table-announce')).toHaveCount(0)
        await botGeometryEvidence(
          appended,
          reader,
          info,
          `cycle-${ordinal}-append`,
        )
        const removed = await watch(key)
        await deleteBot(page, key)
        await expectTableTotal(expect, reader, base)
        await expect(
          shownByTestId(reader, `hilos-table-pending-remove-${key}`),
        ).toBeVisible()
        await applyBotChanges(reader)
        await expectBotDeparture(reader, key)
        await expect(reader.getByTestId('hilos-table-count')).toHaveText(
          `0 – 0 of ${base}`,
        )
        await botGeometryEvidence(
          removed,
          reader,
          info,
          `cycle-${ordinal}-departure`,
        )
        retained.push(key)
        lastRetained = key
      }
      const allPlaceholderAppend = await watch(lastRetained)
      await createBot(page, `${prefix} 120 retained tail`)
      const allPlaceholderTail = await tableRowKeyByText(
        expect,
        reader,
        `${prefix} 120 retained tail`,
      )
      await expectTableRowOrder(expect, reader, [
        ...retained,
        allPlaceholderTail,
      ])
      await expect(reader.getByTestId('hilos-table-count')).toHaveText(
        `${base + 1} – ${base + 1} of ${base + 1}`,
      )
      await expect(reader.getByTestId('hilos-table-announce')).toHaveCount(0)
      await expect(reader.getByTestId('hilos-table-apply')).toHaveCount(0)
      await botGeometryEvidence(
        allPlaceholderAppend,
        reader,
        info,
        'all-placeholder-append-old-extent',
      )

      const announcement = await watch(allPlaceholderTail)
      const beforeShow = windows()
      await createBot(page, `${prefix} 35 announced`)
      const announced = await tableRowKeyByText(
        expect,
        page,
        `${prefix} 35 announced`,
      )
      await expect(reader.getByTestId('hilos-table-announce')).toContainText(
        '1 new row',
      )
      await expectTableTotal(expect, reader, base + 2)
      await expectTableRowOrder(expect, reader, [
        ...retained,
        allPlaceholderTail,
      ])
      await expect(reader.getByTestId(recordId(announced))).toHaveCount(0)
      await deleteBot(page, allPlaceholderTail)
      await expectTableTotal(expect, reader, base + 1)
      await expect(
        shownByTestId(
          reader,
          `hilos-table-pending-remove-${allPlaceholderTail}`,
        ),
      ).toBeVisible()
      await applyBotChanges(reader)
      await expectBotDeparture(reader, allPlaceholderTail)
      await expect(reader.getByTestId('hilos-table-count')).toHaveText(
        `0 – 0 of ${base + 1}`,
      )
      await expect(reader.getByTestId('hilos-table-announce')).toContainText(
        '1 new row',
      )
      await expect(reader.getByTestId(recordId(announced))).toHaveCount(0)
      expect(windows()).toBe(beforeShow)
      await botGeometryEvidence(
        announcement,
        reader,
        info,
        'announcement-apply-keeps-trace',
      )

      // Show is permitted to rearrange and erase departed occurrences.
      await clickSubmit(expect, reader.getByTestId('hilos-table-announce-show'))
      await expect.poll(windows).toBeGreaterThan(beforeShow)
      await expect(reader.getByTestId('hilos-table-announce')).toHaveCount(0)
      await expectTableRowOrder(expect, reader, [announced])
      await expect(reader.getByTestId('hilos-table-count')).toHaveText(
        `${base + 1} – ${base + 1} of ${base + 1}`,
      )
      await expect(reader.getByTestId('hilos-table-placeholder')).toHaveCount(0)
      await deleteBot(reader, announced)
      await openBots(page)
      await deletePads(page, pads)
    } finally {
      for (const observed of watches) await observed.stop()
      await info.attach('server-frames', {
        body: JSON.stringify(frames, null, 2),
        contentType: 'application/json',
      })
      await reader.close()
    }
  })
})
