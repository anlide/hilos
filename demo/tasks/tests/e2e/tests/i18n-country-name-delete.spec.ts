import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  i18nNameRow,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  clearCountryNamesFixture,
  seedCountryNamesFixture,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

const NAMES_PATH = '/hilos/i18n/countries/qj/names'
const WINDOW = 'i18n-country-name-delete-window'
const CONFIRM = 'i18n-country-name-delete-confirm'
const CANCEL = 'i18n-country-name-delete-cancel'

/** Open the fixture's names and its corrections through the native row chevron. */
async function openNames(page: Page): Promise<void> {
  await gotoPage(page, NAMES_PATH, PAGE_READY)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
    'Unnamed country',
  )
  await expect(i18nNameRow(page, 'country', 'qk')).toContainText('Fixture base')
  await clickSubmit(
    i18nNameRow(page, 'country', 'qk').getByTestId(/^hilos-table-expand-/),
  )
  await expect(
    shownByTestId(page, 'i18n-names-correction-qk-GB'),
  ).toContainText('Fixture correction')
  await expect(shownByTestId(page, 'i18n-names-delete-qk-US')).toBeVisible()
}

/** Wait for the tracked reply before inspecting the subscription's live values. */
async function confirmDelete(page: Page, message: string): Promise<void> {
  await clickSubmit(page.getByTestId(CONFIRM))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toast-success').filter({ hasText: message }),
  ).toBeVisible()
}

test('removes country name corrections and their base live in two windows, including empty values', async ({
  page,
  context,
}) => {
  // Two live windows, three writes and cancellation checks exceed the ordinary UI path.
  test.slow()
  await clearCountryNamesFixture()
  const observer = await context.newPage()
  try {
    await seedCountryNamesFixture()
    await signUpAdmin(page)
    await openNames(page)
    await openNames(observer)

    const correctionTrash = shownByTestId(page, 'i18n-names-delete-qk-GB')
    await expect(correctionTrash).toHaveAccessibleName(
      'Remove locale correction qk-GB',
    )
    await clickSubmit(correctionTrash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(WINDOW)).toHaveCount(1)
    await expect(page.getByTestId(WINDOW)).toContainText('Fixture correction')
    await expect(page.getByTestId(WINDOW)).toContainText(
      /Base name to inherit:\s*Fixture base/,
    )
    await expect(page.getByTestId(WINDOW)).toContainText(
      'This correction will not return by itself.',
    )
    await page.getByTestId('modal').press('Escape')
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(correctionTrash).toBeFocused()
    await expect(
      shownByTestId(observer, 'i18n-names-correction-qk-GB'),
    ).toContainText('Fixture correction')

    await clickSubmit(correctionTrash)
    await confirmDelete(page, 'Correction qk-GB of the name of qj removed.')
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-inherit-qk-GB')).toHaveText(
        'as in “HIL-1501 writing”',
      )
      await expect(i18nNameRow(tab, 'country', 'qk')).toContainText(
        'Fixture base',
      )
      await expect(shownByTestId(tab, 'i18n-names-delete-qk-US')).toBeVisible()
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }

    await dismissToasts(page)
    await clickSubmit(shownByTestId(page, 'i18n-names-delete-qk-US'))
    await expect(page.getByTestId(WINDOW)).toContainText('Empty value')
    await expect(page.getByTestId(WINDOW)).toContainText(
      'A saved empty string, not an absent name.',
    )
    await confirmDelete(page, 'Correction qk-US of the name of qj removed.')
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-inherit-qk-US')).toHaveText(
        'as in “HIL-1501 writing”',
      )
      await expect(i18nNameRow(tab, 'country', 'qk')).toContainText(
        'Fixture base',
      )
    }

    await dismissToasts(page)
    const baseTrash = shownByTestId(page, 'i18n-names-delete-qk')
    await clickSubmit(baseTrash)
    await expect(page.getByTestId(WINDOW)).toContainText(
      'All locale corrections of this base name will also be deleted.',
    )
    await expect(page.getByTestId(WINDOW)).toContainText(
      'If the built-in catalog knows this name, it returns immediately as an unlocked name. Otherwise, there will be no name in this language.',
    )
    await expect(page.getByTestId(WINDOW)).not.toContainText(/36|hours/)
    await clickSubmit(page.getByTestId(CANCEL))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(baseTrash).toBeFocused()
    for (const tab of [page, observer]) {
      await expect(i18nNameRow(tab, 'country', 'qk')).toContainText(
        'Fixture base',
      )
      await expect(shownByTestId(tab, 'i18n-names-inherit-qk-GB')).toBeVisible()
    }

    // The other window keeps its confirmation and explains the disappeared source.
    await clickSubmit(shownByTestId(observer, 'i18n-names-delete-qk'))
    await clickSubmit(baseTrash)
    await confirmDelete(page, 'Name of qj in HIL-1501 writing (qk) removed.')
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-qk')).toHaveText(
        'No name',
      )
      await expect(i18nNameRow(tab, 'country', 'qk')).toBeVisible()
      await expect(tab.getByTestId('i18n-names-correction-qk-GB')).toHaveCount(
        0,
      )
      await expect(tab.getByTestId('i18n-names-correction-qk-US')).toHaveCount(
        0,
      )
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
    await expect(observer.getByTestId(WINDOW)).toBeVisible()
    await expect(observer.getByTestId(CONFIRM)).toBeDisabled()
    await expect(
      observer.getByTestId('i18n-country-name-delete-notice'),
    ).toContainText('The name no longer exists.')
    await clickSubmit(observer.getByTestId(CANCEL))
  } finally {
    await observer.close()
    await clearCountryNamesFixture()
  }
})
