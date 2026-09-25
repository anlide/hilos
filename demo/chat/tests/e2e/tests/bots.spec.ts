import { test, expect, type Page } from '@playwright/test'

import { watchFirstRowTop } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'
import {
  expectTableTotal,
  goToLastPage,
  pageBackOnce,
  tableRowKeyByText,
  tableRowKeys,
  tableTotal,
} from '../helpers/table'

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
// - one created inside the window moves nothing but the count — the view draws a
//   strip for "above" only (design debt D-041), so the count is all there is to see;
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
 * Open the bots admin and wait for the live window's first row; the caller is admin already.
 *
 * @param page The Playwright page.
 */
async function openBots(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/app/bots')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
}

/**
 * Create an inactive bot through the add dialog.
 *
 * @param page The Playwright page on the bots admin.
 * @param name The bot's name.
 */
async function createBot(page: Page, name: string): Promise<void> {
  await page.getByTestId('admin-bots-add').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), 'made by e2e')
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(page.getByTestId('admin-bots-save'))
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
  await page.getByTestId(`admin-bots-edit-${rowKey}`).click()
  await typeInto(page.getByTestId('admin-bots-description'), description)
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Rename a bot through the edit dialog. The caller picks a name that keeps the
 * row where it stands in the order.
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
  await page.getByTestId(`admin-bots-edit-${rowKey}`).click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
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
  await page.getByTestId(`admin-bots-delete-${rowKey}`).click()
  await clickSubmit(page.getByTestId('admin-bots-delete-confirm'))
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
  let windows = 0
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') {
        return
      }
      try {
        if (
          (JSON.parse(frame.payload) as { type?: unknown }).type ===
          'table_window'
        ) {
          windows += 1
        }
      } catch {
        // Not one of ours; the socket also carries the keepalive text ping.
      }
    })
  })

  return () => windows
}

test('a bot created here takes the place the sort gives it, and edits and deletes live', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const description = `edited by e2e ${stamp}`

  await signUpAdmin(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await openBots(page)
  const loadsAfterColdLoad = fullLoads
  const base = await tableTotal(page)

  // Create: the row goes in at once where the order puts it — first, not at the
  // tail — and no Apply stands between the author and its own row.
  await createBot(page, name)
  await expectTableTotal(page, base + 1)
  const key = await tableRowKeyByText(page, name)
  expect((await tableRowKeys(page))[0]).toBe(key)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Edit the description: the value lands in place, the row keeps its slot.
  await editBotDescription(page, key, description)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    description,
  )
  expect((await tableRowKeys(page))[0]).toBe(key)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Delete: the bot leaves the window and the count goes back.
  await deleteBot(page, key)
  await expectTableTotal(page, base)

  // The whole tour stayed in one live document.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('a bot created in another tab arrives on its own at the tail of a window with room', async ({
  page,
}) => {
  const stamp = Date.now()
  const filler = `ZZ ${stamp} filler`
  const tail = `ZZ ${stamp} tail`

  await signUpAdmin(page)
  await openBots(page)
  const base = await tableTotal(page)

  // Tab A makes the page with room: the filler sorts after every bot, onto a page
  // A is not showing, so A sees the count and not the row.
  await createBot(page, filler)
  await expectTableTotal(page, base + 1)

  // Tab B opens cold after it and goes to that last page, where the filler is the
  // last row of a window with room.
  const tabB = await page.context().newPage()
  let tabBLoads = 0
  tabB.on('load', () => {
    tabBLoads += 1
  })
  await openBots(tabB)
  await expectTableTotal(tabB, base + 1)
  await goToLastPage(tabB)
  const tabBLoadsAfterColdLoad = tabBLoads
  const fillerKey = await tableRowKeyByText(tabB, filler)
  const keysBefore = await tableRowKeys(tabB)
  expect(keysBefore.at(-1)).toBe(fillerKey)
  expect(keysBefore.length).toBeLessThan(WINDOW)

  // A creates the tail. At B it sorts after the last row shown, into the free
  // slot, and shifts nothing: it arrives on its own, with no Apply, no strip and
  // no document reload.
  await createBot(page, tail)
  const tailKey = await tableRowKeyByText(tabB, tail)
  expect(await tableRowKeys(tabB)).toEqual([...keysBefore, tailKey])
  await expectTableTotal(tabB, base + 2)
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

  await signUpAdmin(page)

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(page)
  await openBots(tabB)
  await goToLastPage(tabB)
  const base = await tableTotal(tabB)
  const keysBefore = await tableRowKeys(tabB)
  // Where B's first row stands before anything is said: the strip comes into room
  // the table already held, so neither its arrival nor its leaving moves a row.
  const rowTop = await watchFirstRowTop(tabB)

  // A creates a bot that sorts before every other: first on A's own window, and
  // on a page above the one B is standing on.
  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)

  // B is told, and nothing moves: the strip names the row, the rows shown are the
  // rows that were shown, and nothing waits behind Apply.
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row above the window')
  await expect(tabB.getByTestId('hilos-table-announce-show')).toBeVisible()
  await expectTableTotal(tabB, base + 1)
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
  await expectTableTotal(tabB, base + 1)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Cleanup: A shows the bot it made.
  await tabB.close()
  await deleteBot(page, key)
})

test('after Show the footer names the tail, Next is off, and Back reaches the first row', async ({
  page,
}) => {
  const name = nameBeforeAll(Date.now())

  await signUpAdmin(page)

  const tabB = await page.context().newPage()
  const tableWindowsOfB = countTableWindows(tabB)
  await openBots(page)
  await openBots(tabB)
  // Two full pages of ten from the seed, so B stands on the last one and the row A is
  // about to create takes the count over a page boundary: this is the shape the defect
  // was found in (HIL-1093).
  await goToLastPage(tabB)
  const base = await tableTotal(tabB)
  const keysBefore = await tableRowKeys(tabB)
  const caption = tabB.getByTestId('hilos-table-page')
  const next = tabB.getByTestId('hilos-table-next')
  const prev = tabB.getByTestId('hilos-table-prev')
  // Read off what the table was found holding, never written as literals: a retry runs
  // against the same database, and a bot an earlier attempt left behind would otherwise
  // make the arithmetic below wrong rather than the behavior.
  const lastPage = Math.ceil(base / WINDOW)
  const pagesAfter = Math.ceil((base + 1) / WINDOW)

  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)
  const strip = tabB.getByTestId('hilos-table-announce')
  await expect(strip).toContainText('1 new row above the window')
  const windowsBeforeShow = tableWindowsOfB()
  await tabB.getByTestId('hilos-table-announce-show').click()
  await expect.poll(tableWindowsOfB).toBeGreaterThan(windowsBeforeShow)
  await expect(strip).toHaveCount(0)

  // The window kept its rows and moved through the set: it now ends the set, so the
  // footer names the page its FIRST ROW sits on and Next has nothing to offer. Counting
  // presses instead would read this as page two of three and offer a Next into nothing.
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expectTableTotal(tabB, base + 1)
  await expect(caption).toHaveText(
    new RegExp(`^\\s*${lastPage} / ${pagesAfter}\\s*$`),
  )
  await expect(next).toBeDisabled()
  await expect(prev).toBeEnabled()

  // Back walks to the top of the set. The second step is the one a page counter could
  // not take: less than a page stands to the left, so the window asked for is the START
  // of the set rather than the ten rows before its own first one.
  await pageBackOnce(tabB)
  await expect(prev).toBeEnabled()
  await pageBackOnce(tabB)
  expect((await tableRowKeys(tabB))[0]).toBe(key)
  await expect(prev).toBeDisabled()
  await expect(caption).toHaveText(new RegExp(`^\\s*1 / ${pagesAfter}\\s*$`))

  // Cleanup: B holds the bot A made.
  await deleteBot(tabB, key)
  await tabB.close()
})

test('a bot created inside the window moves nothing but the count', async ({
  page,
}) => {
  const name = `Dave ${Date.now()}`

  await signUpAdmin(page)

  const tabB = await page.context().newPage()
  await openBots(page)
  await openBots(tabB)
  const base = await tableTotal(tabB)
  const keysBefore = await tableRowKeys(tabB)

  // A creates a bot that sorts between Dasha and David, between two rows both
  // tabs are showing on the first page.
  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)

  // The count is the only trace the announcement leaves at B, so wait for it
  // first: "nothing changed" asserted before the frame came proves nothing.
  await expectTableTotal(tabB, base + 1)
  expect(await tableRowKeys(tabB)).toEqual(keysBefore)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  // The view draws the strip for rows above the window only (D-041).
  await expect(tabB.getByTestId('hilos-table-announce')).toHaveCount(0)

  // Cleanup: A shows the bot it made.
  await tabB.close()
  await deleteBot(page, key)
})

test('a value edited in another tab lands in place, highlighted and with no gate', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = nameBeforeAll(stamp)
  const description = `edited by e2e ${stamp}`

  await signUpAdmin(page)
  await openBots(page)

  // A creates the bot BEFORE B opens, so B's cold first window already holds it
  // and no announcement takes part.
  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)

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

  await signUpAdmin(page)
  await openBots(page)
  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()

  // A opens the edit and touches nothing; B changes the description: it lands
  // in A's form, the message line names the field, and there is nothing to save.
  await page.getByTestId(`admin-bots-edit-${key}`).click()
  await expect(page.getByTestId('admin-bots-description')).toBeVisible()
  await expect(page.getByTestId('admin-bots-save')).toBeDisabled()
  await editBotDescription(tabB, key, `elsewhere one ${stamp}`)
  await expect(page.getByTestId('admin-bots-description')).toHaveValue(
    `elsewhere one ${stamp}`,
  )
  await expect(page.getByTestId('admin-bots-edit-notice')).toContainText(
    'Updated just now: Description',
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
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  await tabB.getByTestId(`admin-bots-edit-${key}`).click()
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
  await page.getByTestId(`admin-bots-delete-${key}`).click()
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

  await signUpAdmin(page)
  await openBots(page)
  await createBot(page, name)
  const key = await tableRowKeyByText(page, name)

  const tabB = await page.context().newPage()
  await openBots(tabB)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toBeVisible()

  // A opens the edit; B renames the bot past the window: A's row will leave,
  // and A's form takes the name silently — the dialog holds the row in focus,
  // and the frame that takes the row off the screen carries it (HIL-1050).
  await page.getByTestId(`admin-bots-edit-${key}`).click()
  await expect(page.getByTestId('admin-bots-name')).toHaveValue(name)
  await renameBot(tabB, key, renamed)
  await expect(
    page.getByTestId(`hilos-table-pending-remove-${key}`),
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
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_bots').click()
  await expect(page.getByTestId('admin-bots-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/app/bots')
})
