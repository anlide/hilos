import { expect, test, type Page } from '@playwright/test'
import {
  dismissToasts,
  expectLanguagePageHead,
  shownByTestId,
  watchHeight,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  deleteLocale,
  switchCountryOff,
  switchLanguageOn,
  switchLocaleOn,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

/** Add this spec's known pair using its seven catalog-prefilled formats. */
async function addLocale(page: Page, localeCode: string): Promise<void> {
  const add = shownByTestId(page, `i18n-locales-add-${localeCode}`)
  await clickSubmit(add)
  for (const field of [
    'date',
    'time',
    'number',
    'phone',
    'address',
    'measurement',
    'collation',
  ]) {
    await expect(
      page.getByTestId(`i18n-locale-field-${field}`),
    ).not.toHaveValue('')
  }
  const submit = page.getByTestId('i18n-locale-submit')
  await clickSubmit(submit)
  await expect(submit).toHaveCount(0)
  await expect(
    shownByTestId(page, `i18n-locales-code-${localeCode}`),
  ).toHaveText(localeCode)
  await expect(
    shownByTestId(page, `i18n-locales-enabled-${localeCode}`),
  ).not.toBeChecked()
  await dismissToasts(page)
}

test('deletes a countryless locale and updates both subscribed pairs without reloading', async ({
  page,
  context,
}) => {
  await signUpAdmin(page)
  await switchLanguageOn('pt')
  await deleteLocale('pt')
  await gotoPage(page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
  await addLocale(page, 'pt')
  const other = await context.newPage()
  await gotoPage(other, '/hilos/i18n/languages/pt/locales', PAGE_READY)
  for (const tab of [page, other]) {
    await expect(shownByTestId(tab, 'i18n-locales-code-pt')).toHaveText('pt')
    await expectLanguagePageHead(expect, tab, {
      code: 'pt',
      nativeName: 'Português',
      currentPage: 'hilos_i18n_language_locales',
      appName: 'Hilos Tasks',
    })
  }
  let reloads = 0
  for (const tab of [page, other]) {
    tab.on('framenavigated', (frame) => {
      if (frame === tab.mainFrame()) reloads++
    })
  }
  const trash = shownByTestId(page, 'i18n-locales-delete-pt')
  await trash.scrollIntoViewIfNeeded()
  await expect(trash).toBeVisible()
  await expect(trash).toBeEnabled()
  await expect(trash).toHaveAccessibleName('Delete locale pt')
  await trash.focus()
  await trash.press('Enter')
  const window = page.getByTestId('i18n-locale-delete-window')
  const modal = page.getByTestId('modal').filter({ has: window })
  await expect(modal).toBeFocused()
  await expect(modal).toHaveAccessibleName(
    'Delete locale · Português without a country',
  )
  await expect(window).toContainText('fall back to the default language')
  await modal.press('Escape')
  await expect(window).toHaveCount(0)
  await expect(trash).toBeFocused()
  await trash.press('Enter')
  await expect(modal).toBeFocused()
  const confirm = page.getByTestId('i18n-locale-delete-confirm')
  await clickSubmit(confirm)
  await expect(window).toHaveCount(0)
  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Locale pt deleted.',
  )
  for (const tab of [page, other]) {
    await expect(shownByTestId(tab, 'i18n-locales-none-pt')).toHaveText(
      'No locale',
    )
    await expect(shownByTestId(tab, 'i18n-locales-add-pt')).toBeVisible()
    for (const control of ['code', 'enabled', 'edit', 'view', 'delete']) {
      await expect(tab.getByTestId(`i18n-locales-${control}-pt`)).toHaveCount(0)
    }
    await expectLanguagePageHead(expect, tab, {
      code: 'pt',
      nativeName: 'Português',
      currentPage: 'hilos_i18n_language_locales',
      appName: 'Hilos Tasks',
    })
    await expect(tab).toHaveURL(/\/hilos\/i18n\/languages\/pt\/locales$/)
  }
  expect(reloads).toBe(0)
  await other.close()
})

test('keeps the switched-on refusal inside the locale confirmation', async ({
  page,
}) => {
  await signUpAdmin(page)
  await switchLanguageOn('pt')
  await switchLocaleOn('pt')
  await gotoPage(page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
  const trash = shownByTestId(page, 'i18n-locales-delete-pt')
  await clickSubmit(trash)
  const window = page.getByTestId('i18n-locale-delete-window')
  const room = await watchHeight(window)
  const confirm = page.getByTestId('i18n-locale-delete-confirm')
  await clickSubmit(confirm)
  await expect(page.getByTestId('i18n-locale-delete-error')).toContainText(
    "Locale 'pt' is switched on: switch it off before deleting it.",
  )
  await expect(window).toBeVisible()
  await expect(confirm).toBeEnabled()
  await room.unchanged()
  await expect(shownByTestId(page, 'i18n-locales-enabled-pt')).toBeChecked()
})

test('refuses the default locale of a switched-off country and preserves its selection', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)
  const noLocale = shownByTestId(page, 'i18n-locales-none-en-IE')
  await expect(
    shownByTestId(page, 'i18n-locales-code-en-IE').or(noLocale),
  ).toBeVisible()
  if (await noLocale.isVisible()) {
    await addLocale(page, 'en-IE')
  }
  await expect(
    shownByTestId(page, 'i18n-locales-enabled-en-IE'),
  ).not.toBeChecked()
  await switchCountryOff('ie')
  await gotoPage(page, '/hilos/i18n/countries/ie', PAGE_READY)
  const defaultLocale = page.getByTestId('country-card-default-locale')
  await expect(defaultLocale).toBeVisible()
  if ((await defaultLocale.textContent()) !== 'en-IE') {
    const edit = page.getByTestId('country-card-edit')
    await clickSubmit(edit)
    await page
      .getByTestId('i18n-country-edit-default-locale')
      .selectOption({ label: 'en-IE — switched off' })
    const save = page.getByTestId('i18n-country-save')
    await clickSubmit(save)
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
  }
  await expect(page.getByTestId('country-card-default-locale')).toHaveText(
    'en-IE',
  )
  await dismissToasts(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)
  const trash = shownByTestId(page, 'i18n-locales-delete-en-IE')
  await clickSubmit(trash)
  const confirm = page.getByTestId('i18n-locale-delete-confirm')
  await clickSubmit(confirm)
  await expect(page.getByTestId('i18n-locale-delete-error')).toContainText(
    "Locale 'en-IE' is the country's default: choose another locale for the country first.",
  )
  await expect(page.getByTestId('i18n-locale-delete-window')).toBeVisible()
  await expect(confirm).toBeEnabled()
  await expect(shownByTestId(page, 'i18n-locales-code-en-IE')).toHaveText(
    'en-IE',
  )
  await expect(
    shownByTestId(page, 'i18n-locales-enabled-en-IE'),
  ).not.toBeChecked()
  const cancel = page.getByTestId('i18n-locale-delete-cancel')
  await clickSubmit(cancel)
  await expect(page.getByTestId('i18n-locale-delete-window')).toHaveCount(0)
  await gotoPage(page, '/hilos/i18n/countries/ie', PAGE_READY)
  await expect(page.getByTestId('country-card-default-locale')).toHaveText(
    'en-IE',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
})
