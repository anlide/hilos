import { test, expect, type Locator, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  dismissToasts,
  draftCustomSetting,
  expectDataFreeDeparture,
  expectSettingsPageHead,
  liesAbove,
  openSettingEdit,
  setCustomSetting,
  shownByTestId,
  watchHeight,
  watchLeft,
  watchTop,
  watchTableSearch,
  clickSubmit,
  typeInto,
  gotoPage,
  grantAdminToSelf,
  expectPageReady,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// Hilos settings admin e2e (server-windowed table): /hilos/settings renders the
// framework HilosViewportTable over the live socket. The window comes from the
// backend — search, sort, and paging change the viewport descriptor and the
// server replies a window — so a key is isolated with the search box before it
// is asserted on (this demo's catalog spans more than one page of ten). The table
// is a declared one, so it stands in the document twice — rows for a wide screen,
// cards for a narrow one — and a control inside a cell is aimed at through the
// copy on screen (shownByTestId). Live edits from
// another connection hang as pending (a tinted row + an Apply control), while the
// tab that made the edit applies its own change at once. Each editing test resets
// its key to the catalog default, so the suite stays idempotent on the shared
// database. This catalog has one free string key with no rule and no reader —
// example_string — and the six two-tab tests all write it, so the file runs its
// tests one by one, in order, instead of in parallel.
test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
test.describe.configure({ mode: 'default' })

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

/** Narrow the server window to a single key so assertions ignore pagination. */
async function isolate(page: Page, key: string): Promise<void> {
  await typeInto(page.getByTestId('hilos-table-search'), key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

/** Reset a custom value through the ↺ dialog, which names both values first. */
async function resetThroughDialog(
  page: Page,
  reset: Locator,
  now: string,
  catalogDefault: string,
): Promise<void> {
  await reset.click()
  await expect(page.getByTestId('hilos-settings-reset-now')).toContainText(now)
  await expect(page.getByTestId('hilos-settings-reset-default')).toContainText(
    catalogDefault,
  )
  await page.getByTestId('hilos-settings-reset-confirm').click()
  await expect(page.getByTestId('hilos-settings-reset-confirm')).toHaveCount(0)
}

// FIXME(F-029, 2026-10-24): registration modal disappears after address entry · чужой · попытка 2 · HIL-1667
test(
  'lists settings in the server window and filters from the search box',
  { tag: '@quarantine:F-029' },
  async ({ page }) => {
    await grantAdminToSelf(expect, page)
    await openSettings(page)
    await expectSettingsPageHead(expect, page)

    // Direct entry and F5 keep the same head without a single Overview tab.
    await page.reload()
    await expectPageReady(expect, page)
    await expectSettingsPageHead(expect, page)

    // A cataloged table has no free "add a setting" entry point.
    await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

    // A key query narrows the window to its row; a query nothing matches empties
    // it into "Nothing found", which names the query and offers the reset (the
    // framework's own state since HIL-808, distinct from the skeleton of a late
    // window); the reset restores the window and clears the box.
    const search = page.getByTestId('hilos-table-search')
    await typeInto(search, 'example_string')
    await expect(
      page.getByTestId('hilos-table-row-example_string'),
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
  },
)

test('paginates the server window by its page numbers and its neighbors', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await openSettings(page)

  // Compare the windows themselves: catalog additions legitimately move a
  // named setting across a page boundary, while page 1 → page 2 must still
  // replace the ten row keys and both neighbor controls must restore them.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  const rowIds = (): Promise<(string | null)[]> =>
    rows.evaluateAll((found) => found.map((row) => row.getAttribute('data-id')))
  const pageOne = page.getByTestId('hilos-table-page-1')
  const pageTwo = page.getByTestId('hilos-table-page-2')

  await expect(rows).toHaveCount(10)
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(pageTwo).toBeVisible()
  const firstPageIds = await rowIds()

  // A number leads to its own page. The row is what says the window arrived: the
  // current number moves the moment it is pressed, the rows only with the answer.
  await pageTwo.click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect.poll(rowIds).not.toEqual(firstPageIds)
  const secondPageIds = await rowIds()

  await pageOne.click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect.poll(rowIds).toEqual(firstPageIds)

  // Previous and Next stand beside the numbers and still walk one page at a time.
  await page.getByTestId('hilos-table-next').click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect.poll(rowIds).toEqual(secondPageIds)

  await page.getByTestId('hilos-table-prev').click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect.poll(rowIds).toEqual(firstPageIds)
})

test('a third click on a sorted header returns the table to its initial order', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
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

test('a tab applies its own edit at once, with no pending gate', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await grantAdminToSelf(expect, page)
  await openSettings(page)
  const loadsAfterColdLoad = fullLoads
  await isolate(page, 'example_integer')
  const row = page.getByTestId('hilos-table-row-example_integer')
  await expect(row).toContainText('default')

  // The catalog type stays in the header, and custom-off keeps a disabled,
  // dimmed field mounted before there is a custom value.
  await openSettingEdit(page, 'example_integer')
  await expect(page.getByTestId('hilos-settings-edit-type')).toHaveText(
    'integer',
  )
  const custom = page.getByTestId('hilos-settings-edit-custom')
  const value = page.getByTestId('hilos-settings-edit-value')
  await expect(custom).not.toBeChecked()
  await expect(value).toBeVisible()
  await expect(value).toHaveAttribute('disabled', '')
  await expect(value.locator('..')).toHaveClass(/\bopacity-50\b/)
  const input = await value.elementHandle()

  // Off/on preserves both the input node and the exact unsaved draft.
  await custom.check()
  await expect(value).toBeEnabled()
  await typeInto(value, '42')
  await custom.uncheck()
  await expect(value).toHaveAttribute('disabled', '')
  await expect(value.locator('..')).toHaveClass(/\bopacity-50\b/)
  await expect(value).toHaveValue('42')
  expect(
    await value.evaluate((node, original) => node === original, input),
  ).toBe(true)
  await custom.check()
  await expect(value).toBeEnabled()
  await expect(value.locator('..')).not.toHaveClass(/\bopacity-50\b/)
  await expect(value).toHaveValue('42')
  expect(
    await value.evaluate((node, original) => node === original, input),
  ).toBe(true)
  const save = page.getByTestId('hilos-settings-edit-save')
  await clickSubmit(expect, save)
  await expect(save).toHaveCount(0)

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

  // Saving with custom off resets to the default rather than writing the draft.
  await dismissToasts(page)
  await openSettingEdit(page, 'example_integer')
  await typeInto(value, '43')
  await custom.uncheck()
  await expect(value).toHaveAttribute('disabled', '')
  await expect(value).toHaveValue('43')
  await clickSubmit(expect, save)
  await expect(save).toHaveCount(0)
  await expect(row).toContainText('default')
  await expect(row).not.toContainText('custom')
  await expect(row).not.toContainText('43')

  // The reset took the row away, so the key is back to offering a custom value
  // rather than pretending there is one to edit — this is what the stored row
  // with no value used to hide.
  await expect(
    shownByTestId(page, 'hilos-settings-edit-example_integer'),
  ).toHaveAttribute('aria-label', 'Set custom value')

  // And re-opening the dialog arms the switch from the value, not from the row:
  // off, with the disabled value field still visible.
  await openSettingEdit(page, 'example_integer')
  await expect(page.getByTestId('hilos-settings-edit-custom')).not.toBeChecked()
  await expect(value).toBeVisible()
  await expect(value).toHaveAttribute('disabled', '')
  await page.getByTestId('modal-close').click()

  // All of it happened over the live socket — no document reload.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('an edit in one tab lands at once in another, raising no Apply', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, 'example_string')
  await isolate(tabB, 'example_string')

  const rowA = page.getByTestId('hilos-table-row-example_string')
  const rowB = tabB.getByTestId('hilos-table-row-example_string')

  // Tab A sets a custom value and applies its own change at once (no Apply).
  await setCustomSetting(page, 'example_string', 'xx-test')
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
  await clearCustomSetting(page, 'example_string')
  await expect(rowA).not.toContainText('xx-test')
  await tabB.close()
})

test('the edit dialog opens on the value the other tab just wrote', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
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
  // A string with no catalog rule; the file runs in order, so sharing it with the
  // other two-tab tests is safe.
  const key = 'example_string'
  await grantAdminToSelf(expect, page)
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
  // The value arrived on its own, and the modal says so on its message line —
  // the line that was standing there, empty, before the value arrived.
  await expect(tabB.getByTestId('hilos-settings-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()
  await tabB.getByTestId('modal-close').click()

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('a dirty open edit conflicts when the other tab saves, with no Merge', async ({
  page,
}) => {
  const key = 'example_string'
  await grantAdminToSelf(expect, page)
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
  await expect(tabB.getByTestId('hilos-settings-edit-notice')).toContainText(
    'Changed elsewhere to "theirs-model"',
  )
  await expect(tabB.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()

  await tabB.getByTestId('conflict-accept-theirs').click()
  await expect(tabB.getByTestId('hilos-settings-edit-value')).toHaveValue(
    'theirs-model',
  )
  await expect(tabB.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-settings-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(tabB.getByTestId('hilos-settings-edit-save')).toBeDisabled()
  await tabB.getByTestId('modal-close').click()

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('Keep mine on a dirty conflict saves the typed value in both tabs', async ({
  page,
}) => {
  const key = 'example_string'
  await grantAdminToSelf(expect, page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, key)
  await isolate(tabB, key)

  await draftCustomSetting(tabB, key, 'mine-provider')
  const save = tabB.getByTestId('hilos-settings-edit-save')
  const cancel = tabB.getByTestId('hilos-settings-edit-cancel')
  // The choices of a conflict hold their room from the open (HIL-1148): their
  // arrival and their leaving move neither Cancel nor Save.
  const wide = [
    await watchLeft(save),
    await watchTop(save),
    await watchLeft(cancel),
    await watchTop(cancel),
  ]

  await setCustomSetting(page, key, 'theirs-provider')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'theirs-provider',
  )
  await expect(tabB.getByTestId('conflict-badge')).toBeVisible()
  for (const mark of wide) {
    await mark.unchanged()
  }

  await tabB.getByTestId('conflict-accept-mine').click()
  await expect(tabB.getByTestId('conflict-badge')).toHaveCount(0)
  for (const mark of wide) {
    await mark.unchanged()
  }
  await expect(save).toBeEnabled()
  await clickSubmit(expect, save)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'mine-provider',
  )
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'mine-provider',
  )

  await clearCustomSetting(page, key)
  await tabB.close()
})

test('an open edit follows its row past the window edge, and the conflict after it', async ({
  page,
}) => {
  // The dialog holds its row in focus (HIL-1050): the other tab's save takes the
  // row past the edge of this tab's window, and the dialog hears the change all
  // the same — first as a silent take while the row is still on screen as "will
  // leave", then, with the row outside the window, as a conflict the dialog
  // resolves and saves. The screen under the dialog gates the departure as it
  // always did. (This table cannot say whether a row left a search, so a search
  // never takes a row off the screen; an order does.)
  const key = 'example_string'
  await grantAdminToSelf(expect, page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, key)
  await isolate(tabB, key)

  // A's own value sorts after every other; A then leaves the search and takes
  // Value descending, which puts the row at the head of the first page.
  await setCustomSetting(page, key, '~follow-one')
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toContainText(
    '~follow-one',
  )
  await page.getByTestId('hilos-table-search').fill('')
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').nth(1),
  ).toBeVisible()
  const valueHeader = page.locator('th:has([data-id="hilos-table-sort-value"])')
  await page.getByTestId('hilos-table-sort-value').click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'ascending')
  await page.getByTestId('hilos-table-sort-value').click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'descending')
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toHaveAttribute('data-id', `hilos-table-row-${key}`)
  await openSettingEdit(page, key)
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveValue(
    '~follow-one',
  )

  // B saves a value that sorts before every other: A's row will leave past the
  // bottom of the window, and the dialog takes the value silently — not "Deleted".
  await setCustomSetting(tabB, key, '!follow-two')
  await expect(
    shownByTestId(page, `hilos-table-pending-remove-${key}`),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveValue(
    '!follow-two',
  )
  await expect(page.getByTestId('hilos-settings-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('hilos-settings-edit-save')).toHaveText('Save')
  await expect(page.getByTestId('hilos-settings-edit-save')).toBeDisabled()

  // A types its own; B saves a third value while the row is outside A's
  // window: the server follows the row for the dialog, and the dialog conflicts.
  await typeInto(page.getByTestId('hilos-settings-edit-value'), 'mine-follow')
  await setCustomSetting(tabB, key, '!follow-three')
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toContainText(
    '!follow-three',
  )
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-settings-edit-notice')).toContainText(
    'Changed elsewhere to "!follow-three"',
  )
  await expect(page.getByTestId('hilos-settings-edit-save')).toBeDisabled()

  // Keep mine and save: B sees A's value.
  await page.getByTestId('conflict-accept-mine').click()
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  const save = page.getByTestId('hilos-settings-edit-save')
  await expect(save).toBeEnabled()
  await clickSubmit(expect, save)
  await expect(save).toHaveCount(0)
  await expect(tabB.getByTestId(`hilos-table-row-${key}`)).toContainText(
    'mine-follow',
  )

  // B still holds the row in its window; A's page does not.
  await clearCustomSetting(tabB, key)
  await tabB.close()
})

test('deletes an orphan setting through the confirm modal', async ({
  page,
}) => {
  // An uncataloged (orphan) row seeded before the app came up by the composer
  // `test:db-prepare` step (cli `test:orphan:create e2e_orphan_delete ...`);
  // keep this key in sync with that step. The full e2e run always db-resets, so
  // this test owns the row and needs no cleanup.
  const orphanKey = 'e2e_orphan_delete'

  const windows = await watchTableSearch(page, {
    page: 'hilos_settings',
    tableKey: 'settings',
  })
  await grantAdminToSelf(expect, page)
  await openSettings(page)
  const mark = await windows.mark()
  await typeInto(page.getByTestId('hilos-table-search'), orphanKey)
  try {
    await windows.settled(expect, mark, orphanKey, [orphanKey])
  } finally {
    await test.info().attach('orphan-search', {
      body: JSON.stringify(await windows.read(), null, 2),
      contentType: 'application/json',
    })
  }

  // Both the visible value cell and its editor name the uncataloged key.
  const cellBadge = shownByTestId(page, 'hilos-setting-not-in-catalog')
  await expect(cellBadge).toBeVisible()
  await expect(cellBadge).toHaveText('not in catalog')
  await expect(cellBadge).toHaveAttribute(
    'title',
    'This key is not in the project catalog',
  )
  await openSettingEdit(page, orphanKey)
  const orphanBadge = page.getByTestId('hilos-settings-edit-orphan')
  const orphanValue = page.getByTestId('hilos-settings-edit-value')
  await expect(orphanBadge).toBeVisible()
  await expect(orphanBadge).toHaveText('not in catalog')
  await expect(orphanBadge).toHaveAttribute(
    'title',
    'This key is not in the project catalog',
  )
  await expect(page.getByTestId('hilos-settings-edit-type')).toHaveText(
    'string',
  )
  await expect(page.getByTestId('hilos-settings-edit-custom')).toHaveCount(0)
  await expect(orphanValue).toBeEnabled()
  await expect(orphanValue).toHaveValue('seed-value')
  await liesAbove(orphanBadge, orphanValue)
  await page.getByTestId('modal-close').click()
  await expect(orphanValue).toHaveCount(0)
  await expect(page.getByTestId('modal-confirm-discard')).toHaveCount(0)

  // The delete affordance is orphan-only: a catalog key never exposes it, this
  // uncataloged row does.
  const deleteButton = shownByTestId(page, `hilos-settings-delete-${orphanKey}`)
  await expect(deleteButton).toBeVisible()
  await expect(deleteButton).toBeEnabled()
  await expect(deleteButton).toHaveAccessibleName('Delete orphan setting')
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)

  // Own deletion applies immediately. This window keeps exactly one data-free
  // slot in each mounted projection until an explicit replacement is requested.
  await clickSubmit(expect, deleteButton)
  const confirm = page.getByTestId('hilos-settings-delete-confirm')
  await expect(page.getByTestId('modal')).toHaveAccessibleName(
    `Delete · ${orphanKey}`,
  )
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
  await expectDataFreeDeparture(expect, page, orphanKey, 'Removed', [
    `hilos-settings-edit-${orphanKey}`,
    `hilos-settings-reset-${orphanKey}`,
    `hilos-settings-delete-${orphanKey}`,
  ])
  await expect(page.locator('[data-id^="hilos-table-row-"]')).toHaveAttribute(
    'data-id',
    `hilos-table-row-${orphanKey}`,
  )
  await expect(page.locator('[data-id^="hilos-table-card-"]')).toHaveAttribute(
    'data-id',
    `hilos-table-card-${orphanKey}`,
  )
  await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(2)
  await expect(page.getByTestId('hilos-table-count')).toHaveText('0 – 0 of 0')
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-pending-row')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-no-matches')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-empty')).toHaveCount(0)
  for (const prefix of ['hilos-table-row-', 'hilos-table-card-']) {
    await expect(page.getByTestId(`${prefix}${orphanKey}`)).toHaveText(
      'Removed',
    )
    await expect(page.getByTestId(`${prefix}${orphanKey}`)).not.toContainText(
      'seed-value',
    )
  }

  const emptyMark = await windows.mark()
  const emptySearch = 'e2e_orphan_delete_missing'
  try {
    await typeInto(page.getByTestId('hilos-table-search'), emptySearch)
    await windows.settled(expect, emptyMark, emptySearch, [])
    await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
    await expect(
      shownByTestId(page, 'hilos-table-no-matches-terms'),
    ).toContainText(`“${emptySearch}”`)
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    await expect(page.getByTestId(`hilos-table-row-${orphanKey}`)).toHaveCount(
      0,
    )
    await expect(page.getByTestId(`hilos-table-card-${orphanKey}`)).toHaveCount(
      0,
    )
  } finally {
    await test.info().attach('orphan-replacement-empty', {
      body: JSON.stringify(await windows.read(), null, 2),
      contentType: 'application/json',
    })
  }
})

test('resets a custom setting to its catalog default through the confirm modal', async ({
  page,
}) => {
  // HIL-1147: ↺ never resets in one click — it opens a dialog that says what the
  // value is now and what it goes back to, and resets on its Reset only. The key
  // is read by the delivery-log pruner alone, so no other test leans on it; the
  // test ends on the catalog default, which is its own cleanup.
  // HIL-1757: the dark ↺ is explained by a ? in the same row, in the sentence
  // the server put on the row, and the ? follows the row live: it comes with
  // the reset that turns ↺ off and goes with the override that turns it on.
  const key = 'notifications.delivery_log.retention_days'

  await grantAdminToSelf(expect, page)
  await openSettings(page)
  await isolate(page, key)
  await setCustomSetting(page, key, '30')

  const row = page.getByTestId(`hilos-table-row-${key}`)
  const reset = shownByTestId(page, `hilos-settings-reset-${key}`)
  const why = row.getByTestId('hilos-action-reason')
  await expect(row).toContainText('custom')
  await expect(reset).toBeEnabled()
  await expect(why).toHaveCount(0)

  await resetThroughDialog(page, reset, '30', '90')

  await expect(row).not.toContainText('custom')
  await expect(row).toContainText('90')
  await expect(reset).toBeDisabled()

  const reason =
    'This setting already uses its default value, so there is no override to reset.'
  await expect(why).toBeVisible()
  await expect(why).toHaveAccessibleName('Why this action is unavailable')
  await why.focus()
  await why.press('Enter')
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveAccessibleName('Action unavailable')
  await expect(dialog).toBeFocused()
  await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(reason)
  await dialog.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(why).toBeFocused()
  await expect(reset).toBeDisabled()

  await setCustomSetting(page, key, '30')
  await expect(row).toContainText('custom')
  await expect(reset).toBeEnabled()
  await expect(why).toHaveCount(0)

  await resetThroughDialog(page, reset, '30', '90')
  await expect(row).toContainText('90')
  await expect(reset).toBeDisabled()
})

test('refuses a bad value in the words of the rule that refused it', async ({
  page,
}) => {
  // The regression this suite exists to hold (HIL-779): a catalog rule refuses
  // the value, and the sentence it wrote reaches the person who typed it. It
  // used to be replaced by "The action could not be completed" on the last step
  // of the journey, in the frontend driver.
  const key = 'logs.archive_retention.keep_batches'

  await grantAdminToSelf(expect, page)
  await openSettings(page)
  await isolate(page, key)

  const value = await draftCustomSetting(page, key, '-1')
  await expect(page.getByTestId('hilos-settings-edit-type')).toHaveText(
    'integer',
  )

  // The room for a refusal is held before there is one, and nothing in it looks
  // like a refusal: the live region stands there, the red plate does not
  // (HIL-887).
  const slot = page.getByTestId('hilos-action-error-slot')
  await expect(slot).toBeAttached()
  await expect(page.getByTestId('hilos-action-error')).toHaveCount(0)
  await expect(slot.locator('..')).toHaveAttribute(
    'id',
    'hilos-settings-edit-error',
  )

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
  await clickSubmit(expect, save)

  // The dialog stays open with the refusal above the field, and the value the
  // person typed is still there for them to correct.
  const refusal = page.getByTestId('hilos-action-error')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Value must be an integer of 0 or more')
  await expect(value).toHaveValue('-1')
  await expect(value).toHaveClass(/\bis-invalid\b/)
  await expect(value).toHaveAttribute('aria-invalid', 'true')
  await expect(value).toHaveAttribute(
    'aria-describedby',
    'hilos-settings-edit-error',
  )

  // The plate landed in room that was already taken: the region is the height it
  // stood at while empty, and the field under it did not move. That is what the
  // invisible twin is for.
  await slotRoom.unchanged()
  await valueTop.unchanged()

  // No detail badge: a sentence written for a person is shown in full, so there
  // is nothing the framework held back to reveal.
  await expect(page.getByTestId('hilos-action-error-type')).toHaveCount(0)

  // Repeat the ordinary Save at the desktop width: the refusal stays in its
  // reserved room, the draft remains, and the repeat merges into one toast.
  const toast = page.getByTestId('hilos-toast-error')
  await expect(toast).toBeVisible()
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  await expect(save).toBeEnabled()
  await save.focus()
  await clickSubmit(expect, save)
  await expect(toast.getByTestId('hilos-toast-repeats')).toHaveText('×2')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Value must be an integer of 0 or more')
  await expect(value).toHaveValue('-1')
  await expect(value).toHaveClass(/\bis-invalid\b/)
  await expect(value).toHaveAttribute('aria-invalid', 'true')
  await expect(value).toHaveAttribute(
    'aria-describedby',
    'hilos-settings-edit-error',
  )
  await slotRoom.unchanged()
  await valueTop.unchanged()
  await expect(toast).toHaveCount(1)

  // Correcting the draft clears the previous refusal and its field annotation
  // without submitting anything or releasing the reserved room.
  await typeInto(value, '1')
  await expect(value).toHaveValue('1')
  await expect(refusal).toHaveCount(0)
  await expect(value).not.toHaveClass(/\bis-invalid\b/)
  await expect(value).not.toHaveAttribute('aria-invalid', /./)
  await expect(value).not.toHaveAttribute('aria-describedby', /./)
  await expect(slot).toBeAttached()
  await slotRoom.unchanged()
  await valueTop.unchanged()

  // Nothing was written: the row still reads as the catalog default. Closing
  // goes through the discard guard, because the draft is dirty by construction.
  await page.getByTestId('modal-close').click()
  await page.getByTestId('modal-confirm-discard').click()
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  const row = page.getByTestId(`hilos-table-row-${key}`)
  await expect(row).toContainText('default')
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens a setting, sees the hidden mark in the modal and has nothing to edit or save', async ({
    page,
  }) => {
    const key = 'example_string'

    await setAdminViewMode(true)
    await openSettings(page)
    await isolate(page, key)

    await shownByTestId(page, `hilos-settings-edit-${key}`).click()

    const modal = page.getByTestId('modal')
    await expect(modal.getByTestId('hilos-hidden').first()).toBeVisible()
    await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)

    const save = page.getByTestId('hilos-settings-edit-save')
    await expect(save).toBeDisabled()
    await expect(save).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )

    await clickSubmit(expect, page.getByTestId('hilos-settings-edit-cancel'))
    await expect(page.getByTestId('modal-confirm-discard')).toHaveCount(0)
    await expect(modal).toBeHidden()
  })
})
