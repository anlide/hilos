import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  shownByTestId,
  sidewaysOverflow,
  watchHeight,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  clearLanguageNamesFixture,
  seedLanguageNamesFixture,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

const NAMES_PATH = '/hilos/i18n/languages/qw/names'
const WINDOW = 'i18n-language-name-window'
const INPUT = 'i18n-language-name-input'
const SUBMIT = 'i18n-language-name-submit'
const CANCEL = 'i18n-language-name-cancel'
const TITLE = 'i18n-language-name-title'
const NOTICE = 'i18n-language-name-notice'
const BASE_ADD = 'i18n-language-name-add-qv'
const BASE_EDIT = 'i18n-language-name-edit-qv'
const CORRECTION_ADD = 'i18n-language-name-locale-add-qv-GB'
const CORRECTION_EDIT = 'i18n-language-name-locale-edit-qv-GB'
const DUPLICATE = 'A name already exists for this language or locale.'

/** Open the unseeded reverse pair; all of its writes belong to the UI. */
async function openNames(page: Page): Promise<void> {
  await gotoPage(page, NAMES_PATH, PAGE_READY)
  await expect(shownByTestId(page, 'i18n-names-none-qv')).toHaveText('No name')
  await expect(shownByTestId(page, BASE_ADD)).toBeVisible()
  await expect(page.getByTestId(CORRECTION_ADD)).toHaveCount(0)
}

/** Open the name window through its visible wide or narrow control. */
async function openName(page: Page, control: string): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(shownByTestId(page, control))
  await expect(page.getByTestId(WINDOW)).toBeVisible()
  await expect(page.getByTestId(INPUT)).toBeFocused()
  await expect(page.getByTestId(INPUT)).toHaveAccessibleName('Name')
}

/** Await the tracked success before inspecting either tab's live table. */
async function saveName(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId(SUBMIT))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
}

/** Expand the writing language's country locale through the native chevron. */
async function expandCorrections(page: Page): Promise<void> {
  await clickSubmit(shownByTestId(page, 'hilos-table-expand-qv'))
  await expect(shownByTestId(page, 'i18n-names-inherit-qv-GB')).toHaveText(
    'as in “HIL-1490 target”',
  )
}

/** Read the committed base or correction in place, without Apply or navigation. */
async function expectName(
  page: Page,
  correction: boolean,
  value: string,
): Promise<void> {
  const surface = correction
    ? shownByTestId(page, 'i18n-names-correction-qv-GB')
    : shownByTestId(page, /^(hilos-table-row-qv|hilos-table-card-qv)$/)
  await expect(surface).toContainText(value)
  await expect(
    shownByTestId(page, correction ? CORRECTION_EDIT : BASE_EDIT),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
}

/** The shared fixture must leave the env-language empty-state spec independent. */
async function clearNames(page: Page): Promise<void> {
  await clearLanguageNamesFixture()
  await gotoPage(page, '/hilos/i18n/languages/en/names', PAGE_READY)
  await expect(
    page.locator('table').getByTestId('hilos-table-empty-title'),
  ).toHaveText('No other languages yet')
}

test('creates and edits a base and correction live, preserving literal empty values', async ({
  page,
  context,
}) => {
  // Two tabs and five writes, with reopen checks and cleanup, exceed a single form path.
  test.slow()
  await clearLanguageNamesFixture()
  const observer = await context.newPage()
  try {
    await seedLanguageNamesFixture()
    await signUpAdmin(page)
    await openNames(page)
    await openNames(observer)

    await openName(page, BASE_ADD)
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    await saveName(page)
    for (const tab of [page, observer]) {
      await expect(tab.getByTestId('i18n-names-none-qv')).toHaveCount(0)
      await expect(tab.getByTestId(BASE_ADD)).toHaveCount(0)
      await expect(shownByTestId(tab, BASE_EDIT)).toBeVisible()
      // A stored empty base still permits an independent locale correction.
      await expandCorrections(tab)
    }
    await openName(observer, BASE_EDIT)
    await expect(observer.getByTestId(INPUT)).toHaveValue('')
    await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
    await observer.getByTestId(INPUT).press('Escape')
    await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
    await expect(shownByTestId(observer, BASE_EDIT)).toBeFocused()

    await openName(page, CORRECTION_ADD)
    await typeInto(page.getByTestId(INPUT), 'First correction')
    await saveName(page)
    for (const tab of [page, observer]) {
      await expectName(tab, true, 'First correction')
    }

    await openName(page, BASE_EDIT)
    await typeInto(page.getByTestId(INPUT), 'Edited base')
    await saveName(page)
    for (const tab of [page, observer]) {
      await expectName(tab, false, 'Edited base')
      await expectName(tab, true, 'First correction')
    }

    await openName(page, CORRECTION_EDIT)
    await typeInto(page.getByTestId(INPUT), 'Edited correction')
    await saveName(page)
    for (const tab of [page, observer]) {
      await expectName(tab, true, 'Edited correction')
    }

    await openName(page, CORRECTION_EDIT)
    await typeInto(page.getByTestId(INPUT), '')
    // Enter submits the same guarded form as Save.
    await expect(page.getByTestId(SUBMIT)).toBeEnabled()
    await page.getByTestId(INPUT).press('Enter')
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    for (const tab of [page, observer]) {
      const pencil = shownByTestId(tab, CORRECTION_EDIT)
      await expect(pencil).toBeVisible()
      await expect(pencil.locator('i')).toHaveClass(/bi-pencil/)
      await expect(tab.getByTestId(CORRECTION_ADD)).toHaveCount(0)
      await expect(tab.getByTestId('i18n-names-inherit-qv-GB')).toHaveCount(0)
      await expect(shownByTestId(tab, 'i18n-names-delete-qv-GB')).toBeVisible()
      await expectName(tab, false, 'Edited base')
      await openName(tab, CORRECTION_EDIT)
      await expect(tab.getByTestId(INPUT)).toHaveValue('')
      await expect(tab.getByTestId(SUBMIT)).toBeDisabled()
      await clickSubmit(tab.getByTestId(CANCEL))
      await expect(tab.getByTestId(WINDOW)).toHaveCount(0)
    }
  } finally {
    await observer.close()
    await clearNames(page)
  }
})

for (const theme of ['light', 'dark'] as const) {
  test(`refuses concurrent base and correction ADD without overwriting at 375px in ${theme}`, async ({
    page,
    context,
  }) => {
    // Two duplicate races, keyboard checks and details dialogs share one fixture.
    test.slow()
    await clearLanguageNamesFixture()
    const observer = await context.newPage()
    try {
      await seedLanguageNamesFixture()
      await signUpAdmin(page)
      for (const tab of [page, observer]) {
        await openNames(tab)
        await clickSubmit(tab.getByTestId('nav-theme'))
        await clickSubmit(tab.getByTestId(`nav-theme-option-${theme}`))
        await expect(tab.locator('html')).toHaveAttribute(
          'data-bs-theme',
          theme,
        )
        await tab.setViewportSize({ width: 375, height: 812 })
      }

      for (const correction of [false, true]) {
        const control = correction ? CORRECTION_ADD : BASE_ADD
        const title = `${correction ? 'Add locale correction' : 'Add name'} · HIL-1490 writing`
        if (correction) {
          for (const tab of [page, observer]) await expandCorrections(tab)
        }
        const trigger = shownByTestId(page, control)
        await expect(trigger).toHaveAccessibleName(
          `Add ${correction ? 'locale correction' : 'name'} · HIL-1490 writing in HIL-1490 target${correction ? ' · qv-GB' : ''}`,
        )
        await trigger.focus()
        await trigger.press('Enter')
        await expect(page.getByTestId(INPUT)).toBeFocused()
        await expect(page.getByTestId('modal')).toHaveAccessibleName(title)
        await expect(page.getByTestId('modal')).toHaveAttribute(
          'aria-modal',
          'true',
        )
        await page.getByTestId(INPUT).press('Escape')
        await expect(page.getByTestId(WINDOW)).toHaveCount(0)
        await expect(trigger).toBeFocused()

        await openName(page, control)
        await openName(observer, control)
        await typeInto(page.getByTestId(INPUT), 'First writer')
        await typeInto(observer.getByTestId(INPUT), 'Second writer')
        const errorRoom = await watchHeight(
          observer.getByTestId('i18n-language-name-error'),
        )
        const bodyRoom = await watchHeight(observer.getByTestId(WINDOW))
        await saveName(page)
        await expectName(observer, correction, 'First writer')
        await expect(observer.getByTestId(TITLE)).toHaveText(title)
        await expect(observer.getByTestId(INPUT)).toHaveValue('Second writer')
        await expect(observer.getByTestId(SUBMIT)).toHaveText('Add')
        await clickSubmit(observer.getByTestId(SUBMIT))
        await expect(observer.getByTestId('hilos-action-error')).toContainText(
          DUPLICATE,
        )
        await expect(observer.getByTestId(SUBMIT)).toBeEnabled()
        await expect(observer.getByTestId(INPUT)).toHaveValue('Second writer')
        await expect(observer.getByTestId(WINDOW)).toBeVisible()
        await errorRoom.unchanged()
        await bodyRoom.unchanged()
        await expect.poll(() => sidewaysOverflow(observer)).toEqual([0, 0])
        await expect(
          observer.getByTestId('hilos-action-error-slot'),
        ).toHaveAttribute('aria-live', 'assertive')
        await clickSubmit(observer.getByTestId('hilos-action-error-details'))
        await expect(
          observer.getByTestId('hilos-action-error-full'),
        ).toContainText(DUPLICATE)
        await expect.poll(() => sidewaysOverflow(observer)).toEqual([0, 0])
        await clickSubmit(observer.getByTestId('hilos-action-error-close'))
        await expect(
          observer.getByTestId('hilos-action-error-details'),
        ).toBeFocused()
        await clickSubmit(observer.getByTestId(CANCEL))
        await expect(observer.getByTestId('modal-confirm')).toBeFocused()
        await clickSubmit(observer.getByTestId('modal-confirm-discard'))
        await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
        for (const tab of [page, observer]) {
          await expectName(tab, correction, 'First writer')
          await openName(tab, correction ? CORRECTION_EDIT : BASE_EDIT)
          await expect(tab.getByTestId(INPUT)).toHaveValue('First writer')
          await tab.getByTestId(INPUT).press('Escape')
          await expect(tab.getByTestId(WINDOW)).toHaveCount(0)
        }
      }
    } finally {
      await observer.close()
      await clearNames(page)
    }
  })
}

for (const correction of [false, true]) {
  test(`resolves both EDIT choices for a ${correction ? 'correction' : 'base'} using the latest live name`, async ({
    page,
    context,
  }) => {
    // Both conflict choices and the save after Take theirs require several live round trips.
    test.slow()
    await clearLanguageNamesFixture()
    const observer = await context.newPage()
    try {
      await seedLanguageNamesFixture()
      await signUpAdmin(page)
      await openNames(page)
      await openNames(observer)
      await openName(page, BASE_ADD)
      await typeInto(page.getByTestId(INPUT), 'Initial base')
      await saveName(page)
      await expectName(observer, false, 'Initial base')
      if (correction) {
        for (const tab of [page, observer]) await expandCorrections(tab)
        await openName(page, CORRECTION_ADD)
        await typeInto(page.getByTestId(INPUT), 'Initial correction')
        await saveName(page)
        await expectName(observer, true, 'Initial correction')
      }

      const control = correction ? CORRECTION_EDIT : BASE_EDIT
      for (const choice of ['mine', 'theirs'] as const) {
        await openName(page, control)
        await openName(observer, control)
        const mine = `My ${choice} draft`
        const theirs = `Their ${choice} value`
        await typeInto(observer.getByTestId(INPUT), mine)
        const noticeRoom = await watchHeight(
          observer.getByTestId(`${NOTICE}-slot`),
        )
        await typeInto(page.getByTestId(INPUT), theirs)
        await saveName(page)
        await expect(observer.getByTestId(NOTICE)).toContainText(
          `Name changed elsewhere to "${theirs}".`,
        )
        await expect(observer.getByTestId(INPUT)).toHaveValue(mine)
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        await expect(observer.getByTestId('conflict-merge')).toHaveCount(0)
        await noticeRoom.unchanged()
        await expectName(observer, correction, theirs)
        // Enter cannot write while the conflict is unresolved.
        await observer.getByTestId(INPUT).press('Enter')
        await expect(observer.getByTestId(WINDOW)).toBeVisible()
        await expect(observer.getByTestId('conflict-choices')).toBeVisible()
        await clickSubmit(observer.getByTestId(`conflict-accept-${choice}`))
        await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
        await noticeRoom.unchanged()
        if (choice === 'mine') {
          await expect(observer.getByTestId(INPUT)).toHaveValue(mine)
        } else {
          await expect(observer.getByTestId(INPUT)).toHaveValue(theirs)
          await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
          // A subsequent edit must compare against the value just accepted, too.
          await typeInto(observer.getByTestId(INPUT), mine)
        }
        await saveName(observer)
        for (const tab of [page, observer])
          await expectName(tab, correction, mine)
      }
    } finally {
      await observer.close()
      await clearNames(page)
    }
  })
}
