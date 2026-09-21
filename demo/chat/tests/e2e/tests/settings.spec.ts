import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  draftCustomSetting,
  openSettingEdit,
  setCustomSetting,
  shownByTestId,
  sidewaysOverflow,
  watchHeight,
  watchTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'

// Hilos settings admin e2e (server-windowed table): /hilos/settings renders the
// framework HilosViewportTable over the live socket. The window comes from the
// backend — search, sort, and paging change the viewport descriptor and the
// server replies a window — so a key is isolated with the search box before it
// is asserted on (the chat catalog spans six pages of ten). The table is a
// declared one, so it stands in the document twice — rows for a wide screen,
// cards for a narrow one — and a control inside a cell is aimed at through the
// copy on screen (shownByTestId). Live edits from
// another connection hang as pending (a tinted row + an Apply control), while the
// tab that made the edit applies its own change at once. Each editing test uses a
// distinct catalog key and resets it to the catalog default, so the suite stays
// idempotent and parallel-safe on the shared database.

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

/** Narrow the server window to a single key so assertions ignore pagination. */
async function isolate(page: Page, key: string): Promise<void> {
  await page.getByTestId('hilos-table-search').fill(key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

test('lists settings in the server window and filters from the search box', async ({
  page,
}) => {
  await signUpAdmin(page)
  await openSettings(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Settings')

  // A cataloged table has no free "add a setting" entry point.
  await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

  // A key query narrows the window to its row; a query nothing matches empties
  // it into "Nothing found", which names the query and offers the reset (the
  // framework's own state since HIL-808, distinct from the skeleton of a late
  // window); the reset restores the window and clears the box.
  const search = page.getByTestId('hilos-table-search')
  await typeInto(search, 'chat_bot_language')
  await expect(
    page.getByTestId('hilos-table-row-chat_bot_language'),
  ).toBeVisible()
  await expect(page.locator('[data-id^="hilos-table-row-"]')).toHaveCount(1)

  await typeInto(search, 'zzz-no-such-setting-zzz')
  await expect(page.locator('[data-id^="hilos-table-row-"]')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  // The words stand in both branches of the table, so they are read off the copy
  // on screen; that they are gone is asserted of both.
  const noMatches = page.getByTestId('hilos-table-no-matches')
  await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
  await expect(
    shownByTestId(page, 'hilos-table-no-matches-terms'),
  ).toContainText('“zzz-no-such-setting-zzz”')

  await shownByTestId(page, 'hilos-table-no-matches-reset').click()
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
  await expect(noMatches).toHaveCount(0)
  await expect(search).toHaveValue('')
})

test('paginates the server window by its page numbers and its neighbors', async ({
  page,
}) => {
  await signUpAdmin(page)
  await openSettings(page)

  // The catalog spans six pages of ten; the first page opens with the sign-in
  // method list (HIL-427) and holds the chat_* keys, the default_bot_* keys
  // sort onto the second next to the example_* keys, and the logs.* and
  // notifications.* keys trail onto the rest. The row read on the second page
  // is taken from its middle, so an uncataloged row a neighboring spec leaves
  // behind does not carry it across a page boundary.
  const firstPageRow = page.getByTestId(
    'hilos-table-row-chat_attachment_max_file_bytes',
  )
  const secondPageRow = page.getByTestId('hilos-table-row-default_bot_provider')
  const pageOne = page.getByTestId('hilos-table-page-1')
  const pageTwo = page.getByTestId('hilos-table-page-2')

  // A cataloged set is counted whole, so the count is exact and the footer draws
  // a number for every page — six fit the pager without a gap — with the page on
  // screen as the one that says so (HIL-802). Nothing past the sixth: a number
  // with no page behind it would lead nowhere.
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(page.getByTestId('hilos-table-page-6')).toBeVisible()
  await expect(page.getByTestId('hilos-table-page-7')).toHaveCount(0)
  await expect(firstPageRow).toBeVisible()
  await expect(secondPageRow).toHaveCount(0)

  // A number leads to its own page. The row is what says the window arrived: the
  // current number moves the moment it is pressed, the rows only with the answer.
  await pageTwo.click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect(secondPageRow).toBeVisible()
  await expect(firstPageRow).toHaveCount(0)

  await pageOne.click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(firstPageRow).toBeVisible()
  await expect(secondPageRow).toHaveCount(0)

  // Previous and Next stand beside the numbers and still walk one page at a time.
  await page.getByTestId('hilos-table-next').click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect(secondPageRow).toBeVisible()
  await expect(firstPageRow).toHaveCount(0)

  await page.getByTestId('hilos-table-prev').click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(firstPageRow).toBeVisible()
})

test('a third click on a sorted header returns the table to its initial order', async ({
  page,
}) => {
  await signUpAdmin(page)
  await openSettings(page)

  // The catalog opens sorted by Key ascending: that is the order the third
  // click has to hand back.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  const order = (): Promise<(string | null)[]> =>
    rows.evaluateAll((found) => found.map((row) => row.getAttribute('data-id')))
  await expect(rows.first()).toBeVisible()
  const initialOrder = await order()
  const keyHeader = page.locator('th:has([data-id="hilos-table-sort-key"])')
  const valueHeader = page.locator('th:has([data-id="hilos-table-sort-value"])')
  const sortByValue = page.getByTestId('hilos-table-sort-value')

  // aria-sort follows the click without waiting for anything, so the rows
  // themselves are what says the server window has landed: sorting by Value
  // rearranges the page.
  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect.poll(order).not.toEqual(initialOrder)

  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'descending')

  // The third click reports no sort on Value, hands Key its opening direction
  // back, and the window arrives in the order the page opened with.
  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'none')
  await expect(keyHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect.poll(order).toEqual(initialOrder)
})

test('a narrow window draws the settings as cards and never scrolls sideways', async ({
  page,
}) => {
  // HIL-806 acceptance. A declared table is a table on a wide screen and a list of
  // cards on a narrow one; both are mounted, and Bootstrap's display utilities show
  // exactly one of them. What a phone must never get is the wide table squeezed
  // into a sideways scroll — the thing the cards exist to replace.
  //
  // The sign-up runs at the usual width: it is not what is under test, and driving
  // it on a phone would put its own troubles into this verdict. The page itself is
  // opened narrow, the way a phone opens it.
  await signUpAdmin(page)
  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  await page.setViewportSize({ width: 375, height: desktop.height })
  await openSettings(page)

  // The first page opens on the chat_* keys, so this record is on screen in one
  // branch or the other; which branch is the whole question.
  const key = 'chat_attachment_max_file_bytes'
  const cards = page.getByTestId('hilos-table-cards')
  const card = cards.getByTestId(`hilos-table-card-${key}`)
  const row = page.getByTestId(`hilos-table-row-${key}`)

  await expect(card).toBeVisible()
  await expect(row).toBeHidden()

  // Nothing reaches sideways: neither the shell's own scrolling container, which is
  // where the admin page lives (HilosLayout), nor the document around it.
  expect(await sidewaysOverflow(page)).toEqual([0, 0])

  // Back on a wide screen it is the table again, and the cards are gone from sight:
  // both branches were drawn from the same window, so the row is there to show.
  await page.setViewportSize(desktop)
  await expect(row).toBeVisible()
  await expect(cards).toBeHidden()
})

test('a tab applies its own edit at once, with no pending gate', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(page)
  await openSettings(page)
  const loadsAfterColdLoad = fullLoads
  await isolate(page, 'example_integer')
  const row = page.getByTestId('hilos-table-row-example_integer')
  await expect(row).toContainText('default')

  // Add-by-key: open the on-default row, switch on a custom value, and save it.
  await setCustomSetting(page, 'example_integer', '42')

  // The success sentence is the backend's own, naming the key it saved: the
  // driver has none of its own to fall back on (HIL-770).
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Setting "example_integer" saved.',
  )

  // The editing tab picks up its own change at once: the row updates and no
  // pending Apply control ever appears.
  await expect(row).toContainText('42')
  await expect(row).toContainText('custom')
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Reset back to the catalog default.
  await clearCustomSetting(page, 'example_integer')
  await expect(row).toContainText('default')
  await expect(row).not.toContainText('custom')

  // The reset took the row away, so the key is back to offering a custom value
  // rather than pretending there is one to edit — this is what the stored row
  // with no value used to hide.
  await expect(
    shownByTestId(page, 'hilos-settings-edit-example_integer'),
  ).toHaveAttribute('aria-label', 'Set custom value')

  // And re-opening the dialog arms the switch from the value, not from the row:
  // off, with no value field behind it.
  await openSettingEdit(page, 'example_integer')
  await expect(page.getByTestId('hilos-settings-edit-custom')).not.toBeChecked()
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await page.getByTestId('modal-close').click()

  // All of it happened over the live socket — no document reload.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('an edit in one tab lands at once in another, raising no Apply', async ({
  page,
}) => {
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, 'chat_bot_language')
  await isolate(tabB, 'chat_bot_language')

  const rowA = page.getByTestId('hilos-table-row-chat_bot_language')
  const rowB = tabB.getByTestId('hilos-table-row-chat_bot_language')

  // Tab A sets a custom value and applies its own change at once (no Apply).
  await setCustomSetting(page, 'chat_bot_language', 'xx-test')
  await expect(rowA).toContainText('xx-test')
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Tab B takes the change at once, with nothing to press: the window is ordered by
  // key and a value carries no key, so the edit moved nothing, and the gate holds
  // position and membership rather than the fields of a record (HIL-793). The
  // settings value is inline rather than an entity reference, so this row is the
  // proof the delta itself landed — it could not have changed any other way.
  await expect(rowB).toContainText('xx-test')
  // And the row says on itself that it just changed (HIL-803): the tint is checked
  // HERE, immediately behind the text that is the delta arriving, because it runs on
  // a two-second timer in the controller. Nothing may be awaited in between — no
  // wait would make this less racy, only later.
  await expect(rowB).toHaveClass(/table-success/)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(rowB).not.toHaveClass(/table-warning/)
  // A change that landed in place is not a change that waits: no mark stands beside it.
  await expect(tabB.locator('[data-id^="hilos-table-pending-"]')).toHaveCount(0)

  // And the tint goes by itself, with nobody pressing anything.
  await expect(rowB).not.toHaveClass(/table-success/, { timeout: 10_000 })

  // Reset the key back to its catalog default.
  await clearCustomSetting(page, 'chat_bot_language')
  await expect(rowA).not.toContainText('xx-test')
  await tabB.close()
})

test('the edit dialog opens on the value the other tab just wrote', async ({
  page,
}) => {
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, 'example_string')
  await isolate(tabB, 'example_string')

  const rowB = tabB.getByTestId('hilos-table-row-example_string')

  // Tab A sets a custom value; tab B has it on screen before anyone opens a dialog.
  await setCustomSetting(page, 'example_string', 'hello-modal')
  await expect(rowB).toContainText('hello-modal')

  // The dialog is armed from what the row holds, so it edits the value that
  // arrived and never the one it replaced. Since HIL-793 there is nothing queued
  // to flush on the way in — a value that leaves the row in its place is applied
  // when it arrives, and applyAndResolve is left with the removals it still owns.
  await openSettingEdit(tabB, 'example_string')
  await expect(tabB.getByTestId('hilos-settings-edit-value')).toHaveValue(
    'hello-modal',
  )
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await tabB.getByTestId('modal-close').click()

  // Reset the key back to its catalog default.
  await clearCustomSetting(page, 'example_string')
  await tabB.close()
})

test('an open pristine edit reloads when the other tab saves', async ({
  page,
}) => {
  // Distinct from the other two-tab keys in this file: parallel workers share
  // the database, and this case needs a string with no catalog rule.
  const key = 'default_bot_url'
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, key)
  await isolate(tabB, key)

  // Tab B opens and does not type: a pristine modal must follow the live row.
  // The switch may already be on (an env overlay or an empty stored URL); that
  // is still pristine as long as the field is not edited.
  await openSettingEdit(tabB, key)

  await setCustomSetting(page, key, 'elsewhere-url')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'elsewhere-url',
  )

  await expect(tabB.getByTestId('hilos-settings-edit-value')).toHaveValue(
    'elsewhere-url',
  )
  await expect(tabB.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()
  await tabB.getByTestId('modal-close').click()

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('a dirty open edit conflicts when the other tab saves, with no Merge', async ({
  page,
}) => {
  const key = 'default_bot_model'
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, key)
  await isolate(tabB, key)

  await draftCustomSetting(tabB, key, 'mine-model')

  await setCustomSetting(page, key, 'theirs-model')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'theirs-model',
  )

  await expect(tabB.getByTestId('conflict-badge')).toBeVisible()
  await expect(tabB.getByTestId('hilos-settings-edit-conflict')).toContainText(
    'The value changed elsewhere to "theirs-model"',
  )
  await expect(tabB.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()

  await tabB.getByTestId('conflict-accept-theirs').click()
  await expect(tabB.getByTestId('hilos-settings-edit-value')).toHaveValue(
    'theirs-model',
  )
  await expect(tabB.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()
  await tabB.getByTestId('modal-close').click()

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('Keep mine on a dirty conflict saves the typed value in both tabs', async ({
  page,
}) => {
  const key = 'default_bot_provider'
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, key)
  await isolate(tabB, key)

  await draftCustomSetting(tabB, key, 'mine-provider')

  await setCustomSetting(page, key, 'theirs-provider')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'theirs-provider',
  )
  await expect(tabB.getByTestId('conflict-badge')).toBeVisible()

  await tabB.getByTestId('conflict-accept-mine').click()
  await expect(tabB.getByTestId('conflict-badge')).toHaveCount(0)
  const save = tabB.getByTestId('hilos-settings-edit-save')
  await expect(save).toBeEnabled()
  await clickSubmit(save)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'mine-provider',
  )
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'mine-provider',
  )

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('deletes an orphan setting through the confirm modal', async ({
  page,
}) => {
  // An uncataloged (orphan) row seeded before the app came up by the composer
  // `test:e2e-seed-orphan` step (cli `test:orphan:create e2e_orphan_delete ...`);
  // keep this key in sync with that step. The full e2e run always db-resets, so
  // this test owns the row and needs no cleanup.
  const orphanKey = 'e2e_orphan_delete'

  await signUpAdmin(page)
  await openSettings(page)
  await isolate(page, orphanKey)

  // The delete affordance is orphan-only: a catalog key never exposes it, this
  // uncataloged row does.
  const deleteButton = shownByTestId(page, `hilos-settings-delete-${orphanKey}`)
  await expect(deleteButton).toBeVisible()

  // Confirm-modal delete removes the DB row. The initiating tab applies its own
  // change at once (no pending Apply gate); a removed row collapses in place to a
  // "Removed" placeholder rather than pulling the layout up, and its delete
  // affordance is gone with the row slot — from the card as well as from the row.
  await deleteButton.click()
  await page.getByTestId('hilos-settings-delete-confirm').click()
  const row = page.getByTestId(`hilos-table-row-${orphanKey}`)
  await expect(row.getByTestId('hilos-table-placeholder')).toBeVisible()
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-settings-delete-${orphanKey}`),
  ).toHaveCount(0)
})

test('refuses a bad value in the words of the rule that refused it', async ({
  page,
}) => {
  // The regression this suite exists to hold (HIL-779): a catalog rule refuses
  // the value, and the sentence it wrote reaches the person who typed it. It
  // used to be replaced by "The action could not be completed" on the last step
  // of the journey, in the frontend driver.
  const key = 'logs.archive_retention.keep_batches'

  await signUpAdmin(page)
  await openSettings(page)
  await isolate(page, key)

  const value = await draftCustomSetting(page, key, '-1')

  // The room for a refusal is held before there is one, and nothing in it looks
  // like a refusal: the live region stands there, the red plate does not
  // (HIL-887).
  const slot = page.getByTestId('hilos-action-error-slot')
  await expect(slot).toBeAttached()
  await expect(page.getByTestId('hilos-action-error')).toHaveCount(0)

  // Measured once the submit has been scrolled to, not before: the click would
  // scroll it there itself, and a body that moved under the measurement would
  // answer for the plate.
  const save = page.getByTestId('hilos-settings-edit-save')
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  const slotRoom = await watchHeight(slot)
  const valueTop = await watchTop(value)

  await save.focus()
  await save.click()

  // The dialog stays open with the refusal above the field, and the value the
  // person typed is still there for them to correct.
  const refusal = page.getByTestId('hilos-action-error')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Value must be an integer of 0 or more')
  await expect(value).toHaveValue('-1')

  // The plate landed in room that was already taken: the region is the height it
  // stood at while empty, and the field under it did not move. That is what the
  // invisible twin is for.
  await slotRoom.unchanged()
  await valueTop.unchanged()

  // No detail badge: a sentence written for a person is shown in full, so there
  // is nothing the framework held back to reveal.
  await expect(page.getByTestId('hilos-action-error-type')).toHaveCount(0)

  // Nothing was written: the row still reads as the catalog default. Closing
  // goes through the discard guard, because the draft is dirty by construction.
  await page.getByTestId('modal-close').click()
  await page.getByTestId('modal-confirm-discard').click()
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  const row = page.getByTestId(`hilos-table-row-${key}`)
  await expect(row).toContainText('default')
})

test('a refusal too long for the line still moves nothing under it', async ({
  page,
}) => {
  // The proof that the plate is one line at any length: at 375 the sentence does
  // not fit, and a plate that wrapped would grow and push the field down.
  //
  // Only the measurement is narrow. The journey to the dialog runs at the usual
  // width, the way logs-rotation.spec.ts narrows an already-open modal: the
  // admin table is not what is under test here, and driving it on a phone would
  // put its own troubles into this verdict.
  //
  // The key is the one the case above uses, and sharing it is safe — a refused
  // write leaves the catalog exactly as it found it.
  const key = 'logs.archive_retention.keep_batches'

  await signUpAdmin(page)
  await openSettings(page)
  await isolate(page, key)

  const value = await draftCustomSetting(page, key, '-1')

  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  await page.setViewportSize({ width: 375, height: desktop.height })

  const slot = page.getByTestId('hilos-action-error-slot')
  const save = page.getByTestId('hilos-settings-edit-save')
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  const slotRoom = await watchHeight(slot)
  const valueTop = await watchTop(value)

  await save.focus()
  await save.click()

  const refusal = page.getByTestId('hilos-action-error')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Value must be an integer of 0 or more')

  await slotRoom.unchanged()
  await valueTop.unchanged()

  await page.setViewportSize(desktop)
})
