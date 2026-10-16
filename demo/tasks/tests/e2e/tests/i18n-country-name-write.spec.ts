import { expect, test, type Page } from '@playwright/test'
import {
  countSentFrames,
  dismissToasts,
  i18nNameRow,
  pickThemeBeforeLoad,
  shownByTestId,
  sidewaysOverflow,
  watchHeight,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  clearCountryNamesFixture,
  seedCountryNamesFixture,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

const NAMES_PATH = '/hilos/i18n/countries/qj/names'
const WINDOW = 'i18n-country-name-window'
const INPUT = 'i18n-country-name-input'
const SUBMIT = 'i18n-country-name-submit'
const CANCEL = 'i18n-country-name-cancel'
const TITLE = 'i18n-country-name-title'
const NOTICE = 'i18n-country-name-notice'
const DUPLICATE = 'A name already exists for this language or locale.'

/** Open the fixture without creating the env-language name or the FR correction. */
async function openNames(page: Page): Promise<void> {
  await gotoPage(page, NAMES_PATH, PAGE_READY)
  await expect(page.getByTestId('country-card-title')).toHaveText('qj')
  await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText('No name')
  await expect(shownByTestId(page, 'i18n-country-name-add-en')).toBeVisible()
  await expect(shownByTestId(page, 'i18n-country-name-edit-qk')).toBeVisible()
}

/** Expand the writing language through the table's native chevron. */
async function expandCorrections(page: Page): Promise<void> {
  await clickSubmit(
    i18nNameRow(page, 'country', 'qk').getByTestId(/^hilos-table-expand-/),
  )
  await expect(shownByTestId(page, 'i18n-names-inherit-qk-FR')).toHaveText(
    'as in “HIL-1501 writing”',
  )
}

/** Open from either table projection and wait for the modal's autofocus. */
async function openName(page: Page, control: string): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(shownByTestId(page, `i18n-country-name-${control}`))
  await expect(page.getByTestId(WINDOW)).toBeVisible()
  await expect(page.getByTestId(INPUT)).toBeFocused()
  await expect(page.getByTestId(INPUT)).toHaveAccessibleName('Name')
}

/** Wait for the tracked success before reading the live row in either tab. */
async function saveName(page: Page, message: string): Promise<void> {
  await clickSubmit(page.getByTestId(SUBMIT))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toast-success').filter({ hasText: message }),
  ).toBeVisible()
}

/** Read a base or correction in place; names do not move their language row. */
async function expectName(
  page: Page,
  key: string,
  value: string,
): Promise<void> {
  const correction = key.includes('-')
  const surface = correction
    ? shownByTestId(page, `i18n-names-correction-${key}`)
    : i18nNameRow(page, 'country', key)
  await expect(surface).toContainText(value)
  await expect(
    shownByTestId(
      page,
      `i18n-country-name-${correction ? 'locale-' : ''}edit-${key}`,
    ),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
}

test('writes country names and corrections live in two tabs, including literal empty values', async ({
  page,
  context,
}) => {
  // Two tabs, six writes and empty-value reopen checks exceed a single form path.
  test.slow()
  await clearCountryNamesFixture()
  const observer = await context.newPage()
  try {
    await seedCountryNamesFixture()
    await signUpAdmin(page)
    for (const tab of [page, observer]) await openNames(tab)

    await openName(page, 'add-en')
    await typeInto(page.getByTestId(INPUT), 'Country from UI')
    await saveName(page, 'Name of Country from UI (qj) in English (en) added.')
    for (const tab of [page, observer]) {
      await expect(tab.getByTestId('country-card-title')).toHaveText(
        'Country from UI',
      )
      await expectName(tab, 'en', 'Country from UI')
      await expandCorrections(tab)
    }

    await openName(page, 'edit-qk')
    await typeInto(page.getByTestId(INPUT), 'Edited base')
    await saveName(
      page,
      'Name of Country from UI (qj) in HIL-1501 writing (qk) saved.',
    )
    for (const tab of [page, observer])
      await expectName(tab, 'qk', 'Edited base')

    await openName(page, 'locale-edit-qk-GB')
    await typeInto(page.getByTestId(INPUT), 'Edited British correction')
    await saveName(
      page,
      'Correction qk-GB of the name of Country from UI (qj) saved.',
    )
    for (const tab of [page, observer])
      await expectName(tab, 'qk-GB', 'Edited British correction')

    await openName(page, 'locale-edit-qk-US')
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    await expect(page.getByTestId(SUBMIT)).toBeDisabled()
    await typeInto(page.getByTestId(INPUT), 'American correction')
    await saveName(
      page,
      'Correction qk-US of the name of Country from UI (qj) saved.',
    )
    for (const tab of [page, observer])
      await expectName(tab, 'qk-US', 'American correction')

    for (const key of ['qk-US', 'qk']) {
      const prefix = key.includes('-') ? 'locale-' : ''
      await openName(page, `${prefix}edit-${key}`)
      await typeInto(page.getByTestId(INPUT), '')
      await expect(page.getByTestId(SUBMIT)).toBeEnabled()
      await page.getByTestId(INPUT).press('Enter')
      await expect(page.getByTestId(WINDOW)).toHaveCount(0)
      for (const tab of [page, observer]) {
        await expect(
          tab.getByTestId(`i18n-country-name-${prefix}add-${key}`),
        ).toHaveCount(0)
        await expect(
          shownByTestId(tab, `i18n-country-name-${prefix}edit-${key}`),
        ).toBeVisible()
        await expect(
          shownByTestId(tab, `i18n-names-delete-${key}`),
        ).toBeVisible()
        await expect(
          tab.getByTestId(`i18n-names-${prefix ? 'inherit' : 'none'}-${key}`),
        ).toHaveCount(0)
        await openName(tab, `${prefix}edit-${key}`)
        await expect(tab.getByTestId(INPUT)).toHaveValue('')
        await expect(tab.getByTestId(SUBMIT)).toBeDisabled()
        await tab.getByTestId(INPUT).press('Escape')
        await expect(tab.getByTestId(WINDOW)).toHaveCount(0)
        await expect(
          shownByTestId(tab, `i18n-country-name-${prefix}edit-${key}`),
        ).toBeFocused()
      }
    }
    // An empty base still admits a new, empty correction on a disabled locale.
    await openName(page, 'locale-add-qk-FR')
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    await saveName(
      page,
      'Correction qk-FR of the name of Country from UI (qj) added.',
    )
    for (const tab of [page, observer]) {
      await expect(
        shownByTestId(tab, 'i18n-country-name-locale-edit-qk-FR'),
      ).toBeVisible()
      await expect(tab.getByTestId('i18n-names-inherit-qk-FR')).toHaveCount(0)
      await expectName(tab, 'qk-GB', 'Edited British correction')
    }
  } finally {
    await observer.close()
    await clearCountryNamesFixture()
  }
})

test('refuses a concurrent correction ADD and keeps its draft at 375px in dark', async ({
  page,
  context,
}) => {
  // Two tabs, a duplicate refusal and its details, keyboard focus and cancellation.
  test.slow()
  await clearCountryNamesFixture()
  const observer = await context.newPage()
  try {
    await seedCountryNamesFixture()
    // React has no theme menu yet (HIL-1440): the browser's pick is set before
    // any page loads, and registration carries it into the account.
    await pickThemeBeforeLoad(context, 'dark')
    await signUpAdmin(page)
    for (const tab of [page, observer]) {
      await openNames(tab)
      await expect(tab.locator('html')).toHaveAttribute('data-bs-theme', 'dark')
      await tab.setViewportSize({ width: 375, height: 812 })
      await expandCorrections(tab)
    }
    // The base form's longer catalog hint also fits the narrow screen.
    await openName(page, 'add-en')
    await expect(page.getByTestId(WINDOW)).toContainText('built-in catalog')
    await typeInto(page.getByTestId(INPUT), 'Canceled base')
    await expect.poll(() => sidewaysOverflow(page)).toEqual([0, 0])
    await clickSubmit(page.getByTestId(CANCEL))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-en')).toHaveText(
        'No name',
      )
      await expect(tab.getByTestId('country-card-title')).toHaveText('qj')
    }
    const trigger = shownByTestId(page, 'i18n-country-name-locale-add-qk-FR')
    await expect(trigger).toHaveAccessibleName(
      'Add locale correction · QJ in HIL-1501 writing · qk-FR',
    )
    await trigger.focus()
    await trigger.press('Enter')
    await expect(page.getByTestId(INPUT)).toBeFocused()
    await expect(page.getByTestId('modal')).toHaveAccessibleName(
      'Add locale correction · QJ',
    )
    await expect(page.getByTestId('modal')).toHaveAttribute(
      'aria-modal',
      'true',
    )
    await page.getByTestId(INPUT).press('Escape')
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(trigger).toBeFocused()

    for (const tab of [page, observer]) await openName(tab, 'locale-add-qk-FR')
    await typeInto(page.getByTestId(INPUT), 'First writer')
    await typeInto(observer.getByTestId(INPUT), 'Second writer')
    const errorRoom = await watchHeight(
      observer.getByTestId('i18n-country-name-error'),
    )
    const bodyRoom = await watchHeight(observer.getByTestId(WINDOW))
    await saveName(page, 'Correction qk-FR of the name of qj added.')
    await expectName(observer, 'qk-FR', 'First writer')
    await expect(observer.getByTestId(TITLE)).toHaveText(
      'Add locale correction · QJ',
    )
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
    await expect(observer.getByTestId('hilos-action-error-full')).toContainText(
      DUPLICATE,
    )
    await expect.poll(() => sidewaysOverflow(observer)).toEqual([0, 0])
    await clickSubmit(observer.getByTestId('hilos-action-error-close'))
    await expect(
      observer.getByTestId('hilos-action-error-details'),
    ).toBeFocused()
    await clickSubmit(observer.getByTestId(CANCEL))
    await expect(observer.getByTestId('modal-confirm')).toBeFocused()
    await clickSubmit(observer.getByTestId('modal-confirm-discard'))
    await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
    for (const tab of [page, observer])
      await expectName(tab, 'qk-FR', 'First writer')
  } finally {
    await observer.close()
    await clearCountryNamesFixture()
  }
})

for (const correction of [false, true]) {
  test(`resolves both edit choices for a country ${correction ? 'correction' : 'base'} with the latest name`, async ({
    page,
    context,
  }) => {
    // Both conflict choices and a save after Take theirs take several live round trips.
    test.slow()
    await clearCountryNamesFixture()
    const observer = await context.newPage()
    try {
      await seedCountryNamesFixture()
      await signUpAdmin(page)
      for (const tab of [page, observer]) {
        await openNames(tab)
        if (correction) await expandCorrections(tab)
      }
      const key = correction ? 'qk-GB' : 'qk'
      const control = `${correction ? 'locale-' : ''}edit-${key}`
      for (const choice of ['mine', 'theirs'] as const) {
        for (const tab of [page, observer]) await openName(tab, control)
        const mine = `My ${choice} draft`
        const theirs = `Their ${choice} value`
        await typeInto(observer.getByTestId(INPUT), mine)
        const noticeRoom = await watchHeight(
          observer.getByTestId(`${NOTICE}-slot`),
        )
        await typeInto(page.getByTestId(INPUT), theirs)
        await saveName(
          page,
          correction
            ? 'Correction qk-GB of the name of qj saved.'
            : 'Name of qj in HIL-1501 writing (qk) saved.',
        )
        await expect(observer.getByTestId(NOTICE)).toContainText(
          `Name changed elsewhere to "${theirs}".`,
        )
        await expect(observer.getByTestId(INPUT)).toHaveValue(mine)
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        await expect(observer.getByTestId('conflict-merge')).toHaveCount(0)
        await noticeRoom.unchanged()
        await expectName(observer, key, theirs)
        await observer.getByTestId(INPUT).press('Enter')
        await expect(observer.getByTestId('conflict-choices')).toBeVisible()
        await clickSubmit(observer.getByTestId(`conflict-accept-${choice}`))
        await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
        await noticeRoom.unchanged()
        if (choice === 'mine') {
          await expect(observer.getByTestId(INPUT)).toHaveValue(mine)
        } else {
          await expect(observer.getByTestId(INPUT)).toHaveValue(theirs)
          await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
          await typeInto(observer.getByTestId(INPUT), mine)
        }
        await saveName(
          observer,
          correction
            ? 'Correction qk-GB of the name of qj saved.'
            : 'Name of qj in HIL-1501 writing (qk) saved.',
        )
        for (const tab of [page, observer]) await expectName(tab, key, mine)
      }
    } finally {
      await observer.close()
      await clearCountryNamesFixture()
    }
  })
}

test('lets a viewer open, draft and cancel every country name form without writing', async ({
  page,
}) => {
  // Exercise add/edit, base/correction and dirty close under the real admin shell.
  test.slow()
  await clearCountryNamesFixture()
  const actionRequests = countSentFrames(page, '"type":"action"')
  try {
    await seedCountryNamesFixture()
    await setAdminViewMode(true)
    await openNames(page)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expandCorrections(page)
    for (const control of [
      'add-en',
      'edit-qk',
      'locale-add-qk-FR',
      'locale-edit-qk-US',
    ]) {
      await openName(page, control)
      await typeInto(page.getByTestId(INPUT), 'Viewer draft')
      await expect(page.getByTestId(SUBMIT)).toBeDisabled()
      await expect(page.getByTestId(SUBMIT)).toHaveAttribute(
        'aria-describedby',
        /view-mode/,
      )
      await page.getByTestId(INPUT).press('Enter')
      await expect(page.getByTestId(WINDOW)).toBeVisible()
      await clickSubmit(page.getByTestId(CANCEL))
      await clickSubmit(page.getByTestId('modal-confirm-discard'))
      await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    }
    await expect(page.getByTestId('country-card-title')).toHaveText('qj')
    await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
      'No name',
    )
    await expectName(page, 'qk', 'Fixture base')
    await expect(shownByTestId(page, 'i18n-names-inherit-qk-FR')).toBeVisible()
    expect(actionRequests()).toBe(0)
  } finally {
    await setAdminViewMode(false)
    await clearCountryNamesFixture()
  }
})
