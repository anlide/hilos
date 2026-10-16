import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  expectNoOtherLanguageNames,
  i18nNameRow,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  clearLanguageNamesFixture,
  seedLanguageNamesFixture,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

const NAMES_PATH = '/hilos/i18n/languages/qv/names'
const WINDOW = 'i18n-language-name-delete-window'
const CONFIRM = 'i18n-language-name-delete-confirm'
const CANCEL = 'i18n-language-name-delete-cancel'

/** Open the fixture's names and its corrections using the native row chevron. */
async function openNames(page: Page): Promise<void> {
  await gotoPage(page, NAMES_PATH, PAGE_READY)
  await expect(page.getByTestId('language-card-counts')).toHaveText(
    'Locales: 1 · Names: 1',
  )
  await expect(i18nNameRow(page, 'language', 'qw')).toContainText(
    'Fixture base',
  )
  await clickSubmit(
    i18nNameRow(page, 'language', 'qw').getByTestId(/^hilos-table-expand-/),
  )
  await expect(
    shownByTestId(page, 'i18n-names-correction-qw-GB'),
  ).toContainText('Fixture correction')
  await expect(shownByTestId(page, 'i18n-names-delete-qw-US')).toBeVisible()
}

/** Confirm through the UI and await the tracked reply before checking the subscription. */
async function confirmDelete(page: Page, message: string): Promise<void> {
  await clickSubmit(page.getByTestId(CONFIRM))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toast-success').filter({ hasText: message }),
  ).toBeVisible()
}

/** Prove cleanup before the empty names spec follows this fixture. */
async function clearNames(page: Page): Promise<void> {
  await clearLanguageNamesFixture()
  await gotoPage(page, '/hilos/i18n/languages/en/names', PAGE_READY)
  await expectNoOtherLanguageNames(expect, page)
}

test('cancels then deletes a base name and all corrections live in both windows', async ({
  page,
  context,
}) => {
  await clearLanguageNamesFixture()
  await seedLanguageNamesFixture()
  const observer = await context.newPage()
  try {
    await signUpAdmin(page)
    await openNames(page)
    await openNames(observer)
    const trash = shownByTestId(page, 'i18n-names-delete-qw')
    await expect(trash).toHaveAccessibleName(
      'Delete language name in HIL-1490 writing',
    )
    await clickSubmit(trash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(WINDOW)).toContainText(
      'All locale corrections of this base name will also be deleted.',
    )
    await expect(page.getByTestId(WINDOW)).toContainText('HIL-1490 target')
    await clickSubmit(page.getByTestId(CANCEL))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(trash).toBeFocused()
    await expect(i18nNameRow(page, 'language', 'qw')).toContainText(
      'Fixture base',
    )
    await expect(
      shownByTestId(observer, 'i18n-names-correction-qw-GB'),
    ).toContainText('Fixture correction')

    // Both dialogs hold the same row before the first window deletes it.
    await clickSubmit(shownByTestId(observer, 'i18n-names-delete-qw'))
    await clickSubmit(trash)
    await confirmDelete(
      page,
      'Name of HIL-1490 target (qv) in HIL-1490 writing (qw) deleted.',
    )
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-qw')).toHaveText(
        'No name',
      )
      await expect(i18nNameRow(tab, 'language', 'qw')).toBeVisible()
      await expect(tab.getByTestId('language-card-counts')).toHaveText(
        'Locales: 1 · Names: 0',
      )
      await expect(tab.getByTestId('i18n-names-correction-qw-GB')).toHaveCount(
        0,
      )
      await expect(tab.getByTestId('i18n-names-correction-qw-US')).toHaveCount(
        0,
      )
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
    await expect(observer.getByTestId(WINDOW)).toBeVisible()
    await expect(observer.getByTestId(CONFIRM)).toBeDisabled()
    await expect(
      observer.getByTestId('i18n-language-name-delete-notice'),
    ).toContainText('The name no longer exists.')
    await clickSubmit(observer.getByTestId(CANCEL))
  } finally {
    await observer.close()
    await clearNames(page)
  }
})

test('removes one correction and then the saved empty correction at 375px, with a live missing-name explanation', async ({
  page,
  context,
}) => {
  // Two live windows, three confirmations and fixture cleanup exceed the ordinary UI path.
  test.slow()
  await clearLanguageNamesFixture()
  await seedLanguageNamesFixture()
  const observer = await context.newPage()
  try {
    await signUpAdmin(page)
    await openNames(page)
    await openNames(observer)
    // Picked through the header menu here; the same spec in the React and
    // Angular demos sets the browser's pick before the page loads instead.
    await page.getByTestId('nav-theme').click()
    await page.getByTestId('nav-theme-option-dark').click()
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark')
    await page.setViewportSize({ width: 375, height: 812 })
    const trash = shownByTestId(page, 'i18n-names-delete-qw-GB')
    await expect(trash).toHaveAccessibleName('Remove locale correction qw-GB')
    await clickSubmit(trash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(WINDOW)).toContainText('Fixture correction')
    await expect(page.getByTestId(WINDOW)).toContainText(
      /Base name to inherit:\s*Fixture base/,
    )
    await expect.poll(() => sidewaysOverflow(page)).toEqual([0, 0])
    await page.getByTestId('modal').press('Escape')
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(trash).toBeFocused()
    await clickSubmit(trash)
    await confirmDelete(
      page,
      'Correction qw-GB of the name of HIL-1490 target (qv) removed.',
    )
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-inherit-qw-GB')).toHaveText(
        'as in “HIL-1490 writing”',
      )
      await expect(shownByTestId(tab, 'i18n-names-delete-qw-US')).toBeVisible()
      await expect(shownByTestId(tab, 'i18n-names-delete-qw')).toBeVisible()
      await expect(tab.getByTestId('language-card-counts')).toHaveText(
        'Locales: 1 · Names: 1',
      )
    }
    await dismissToasts(page)
    await clickSubmit(shownByTestId(page, 'i18n-names-delete-qw-US'))
    await expect(page.getByTestId(WINDOW)).toContainText('Empty value')
    await expect(page.getByTestId(WINDOW)).toContainText(
      'A saved empty string, not an absent name.',
    )
    await clickSubmit(shownByTestId(observer, 'i18n-names-delete-qw-US'))
    await confirmDelete(
      observer,
      'Correction qw-US of the name of HIL-1490 target (qv) removed.',
    )
    await expect(page.getByTestId(CONFIRM)).toBeDisabled()
    await expect(page.getByTestId(WINDOW)).toBeVisible()
    await expect(
      page.getByTestId('i18n-language-name-delete-notice'),
    ).toContainText('The name no longer exists.')
    await expect(
      page.getByTestId('i18n-language-name-delete-notice-slot'),
    ).toHaveAttribute('aria-live', 'polite')
    await expect.poll(() => sidewaysOverflow(page)).toEqual([0, 0])
    await clickSubmit(
      page.getByTestId('i18n-language-name-delete-notice-details'),
    )
    await expect(
      page.getByTestId('i18n-language-name-delete-notice-full'),
    ).toContainText('The name no longer exists.')
    await expect.poll(() => sidewaysOverflow(page)).toEqual([0, 0])
    await clickSubmit(
      page.getByTestId('i18n-language-name-delete-notice-close'),
    )
    await clickSubmit(page.getByTestId(CANCEL))
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-inherit-qw-US')).toHaveText(
        'as in “HIL-1490 writing”',
      )
      await expect(tab.getByTestId('language-card-counts')).toHaveText(
        'Locales: 1 · Names: 1',
      )
      await expect(shownByTestId(tab, 'i18n-names-delete-qw')).toBeVisible()
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
  } finally {
    await observer.close()
    await clearNames(page)
  }
})
