import { expect, test, type Page } from '@playwright/test'
import {
  countSentFrames,
  dismissToasts,
  i18nNameRow,
  shownByTestId,
  watchHeight,
  watchI18nLivePages,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  clearCountryNamesFixture,
  seedCountryNamesFixture,
} from '../helpers/i18n.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

const COUNTRY_PATH = '/hilos/i18n/countries/qj'
const NAMES_PATH = '/hilos/i18n/countries/qj/names'
const OVERVIEW_TAB = 'hilos_i18n_country'
const NAMES_TAB = 'hilos_i18n_country_names'
const APP_NAME = 'Hilos Polls'
const UNNAMED = 'Unnamed country'
const WINDOW = 'i18n-country-name-window'
const INPUT = 'i18n-country-name-input'
const SUBMIT = 'i18n-country-name-submit'
const CANCEL = 'i18n-country-name-cancel'
const TITLE = 'i18n-country-name-title'
const NOTICE = 'i18n-country-name-notice'
const DELETE_WINDOW = 'i18n-country-name-delete-window'
const DELETE_CONFIRM = 'i18n-country-name-delete-confirm'
const DUPLICATE = 'A name already exists for this language or locale.'

/** Open the fixture without creating the env-language name or the FR correction. */
async function openNames(page: Page): Promise<void> {
  await gotoPage(page, NAMES_PATH, PAGE_READY)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
    'Unnamed country',
  )
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

/** Write the default-language name from its modal and wait for the tracked success. */
async function writeName(
  page: Page,
  control: string,
  value: string,
  message: string,
): Promise<void> {
  await openName(page, control)
  await typeInto(page.getByTestId(INPUT), value)
  await saveName(page, message)
}

/** Delete the default-language name through its confirmation and wait for the reply. */
async function deleteName(page: Page): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(shownByTestId(page, 'i18n-names-delete-en'))
  await expect(page.getByTestId(DELETE_WINDOW)).toBeVisible()
  await clickSubmit(page.getByTestId(DELETE_CONFIRM))
  await expect(page.getByTestId(DELETE_WINDOW)).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Name of qj in English (en) removed.' }),
  ).toBeVisible()
}

/**
 * Both windows name the country alike in the one h1, the last crumb and the
 * browser tab, and each keeps its own address on the code: the window's URL, the
 * hrefs of both tabs and the code in the head block never follow the name.
 */
async function expectBothHeads(
  windows: readonly { tab: Page; path: string }[],
  name: string,
): Promise<void> {
  for (const { tab, path } of windows) {
    const heading = tab.getByRole('heading', { level: 1 })
    await expect(heading).toHaveCount(1)
    await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
    await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(name)
    const lastCrumb = tab
      .getByTestId('hilos-breadcrumb')
      .locator('[aria-current="page"]')
    // The words are the name alone: the divider before the crumb is hidden from
    // assistive technology, so only the accessibility tree reads them clean.
    await expect(lastCrumb).toMatchAriaSnapshot(`- listitem: ${name}`)
    await expect(lastCrumb.locator('a')).toHaveCount(0)
    await expect(tab).toHaveTitle(`${name} · ${APP_NAME}`)
    await expect(tab.getByTestId('country-head-code')).toHaveText('qj')
    await expect(tab).toHaveURL(new RegExp(`${path}$`))
    await expect(
      tab.getByTestId(`hilos-page-tab-${OVERVIEW_TAB}`),
    ).toHaveAttribute('href', COUNTRY_PATH)
    await expect(
      tab.getByTestId(`hilos-page-tab-${NAMES_TAB}`),
    ).toHaveAttribute('href', NAMES_PATH)
  }
}

test('keeps the live country name and code URLs in Names and Overview through create, rename, empty save and delete', async ({
  page,
  context,
}) => {
  // Four writes, each read live in two windows and again after a reload and a direct entry.
  test.slow()
  await clearCountryNamesFixture()
  const observer = await context.newPage()
  try {
    await seedCountryNamesFixture()
    await signUpAdmin(page)
    await openNames(page)
    await gotoPage(observer, COUNTRY_PATH, PAGE_READY)
    const windows = [
      { tab: page, path: NAMES_PATH },
      { tab: observer, path: COUNTRY_PATH },
    ]
    await expectBothHeads(windows, UNNAMED)

    for (const step of [
      {
        name: 'Country from UI',
        write: () =>
          writeName(
            page,
            'add-en',
            'Country from UI',
            'Name of Country from UI (qj) in English (en) added.',
          ),
      },
      {
        name: 'Renamed country',
        write: () =>
          writeName(
            page,
            'edit-en',
            'Renamed country',
            'Name of Renamed country (qj) in English (en) saved.',
          ),
      },
      {
        name: UNNAMED,
        write: async () => {
          await writeName(
            page,
            'edit-en',
            '',
            'Name of qj in English (en) saved.',
          )
          // A saved empty name is a name, not its absence.
          await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)
        },
      },
      {
        name: UNNAMED,
        write: async () => {
          await deleteName(page)
          await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
            'No name',
          )
        },
      },
    ]) {
      const live = await watchI18nLivePages(expect, [page, observer])
      await step.write()
      await expectBothHeads(windows, step.name)
      await live.unchanged()

      // The writer reloads on Names; the observer enters Overview from the address.
      await page.reload()
      await expectPageReady(page)
      await gotoPage(observer, COUNTRY_PATH, PAGE_READY)
      await expectBothHeads(windows, step.name)
    }
  } finally {
    await observer.close()
    await clearCountryNamesFixture()
  }
})

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
      await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(
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

test('refuses a concurrent correction ADD and keeps its draft', async ({
  page,
  context,
}) => {
  // Two tabs, a duplicate refusal and its details, keyboard focus and cancellation.
  test.slow()
  await clearCountryNamesFixture()
  const observer = await context.newPage()
  try {
    await seedCountryNamesFixture()
    await signUpAdmin(page)
    for (const tab of [page, observer]) {
      await openNames(tab)
      await expandCorrections(tab)
    }
    // The base form carries the longer catalog hint.
    await openName(page, 'add-en')
    await expect(page.getByTestId(WINDOW)).toContainText('built-in catalog')
    await typeInto(page.getByTestId(INPUT), 'Canceled base')
    await clickSubmit(page.getByTestId(CANCEL))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-en')).toHaveText(
        'No name',
      )
      await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(
        'Unnamed country',
      )
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
    await expect(
      observer.getByTestId('hilos-action-error-slot'),
    ).toHaveAttribute('aria-live', 'assertive')
    await clickSubmit(observer.getByTestId('hilos-action-error-details'))
    await expect(observer.getByTestId('hilos-action-error-full')).toContainText(
      DUPLICATE,
    )
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
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Unnamed country',
    )
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
