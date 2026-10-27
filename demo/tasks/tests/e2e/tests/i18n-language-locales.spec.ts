import { expect, test } from '@playwright/test'
import {
  expectLanguagePageHead,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens the locales of the default language directly with its own locale on and a view control', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  // The default language from env and its locale with no country are set up and
  // switched on at start (HIL-1471): the row of the language alone shows both.
  const table = page.locator('table')
  await expect(table.getByTestId('i18n-locales-country-en')).toHaveText(
    '— no country',
  )
  await expect(table.getByTestId('i18n-locales-code-en')).toHaveText('en')
  const enabled = table.getByTestId('i18n-locales-enabled-en')
  await expect(enabled).toBeChecked()
  await expect(enabled).toBeDisabled()
  await expect(enabled).toHaveAccessibleName('Enabled')
  await expect(table.getByTestId('i18n-locales-view-en')).toBeVisible()
  await expect(table.getByTestId('i18n-locales-add-en-GB')).toBeVisible()

  // Shared owner head and current catalog tab.
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_locales',
    appName: 'Hilos Tasks',
  })

  const localesTab = page.getByTestId(
    'hilos-page-tab-hilos_i18n_language_locales',
  )
  await expect(localesTab).toHaveAttribute('aria-current', 'page')
  await expect(localesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en/locales',
  )

  const mainTab = page.getByTestId('hilos-page-tab-hilos_i18n_language')
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/languages/en')

  await mainTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'Hilos Tasks',
  })
  await expect(page.getByTestId('language-card-main')).toBeVisible()

  await page.getByTestId('hilos-page-tab-hilos_i18n_language_locales').click()
  await expect(page).toHaveURL(/\/locales$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_locales',
    appName: 'Hilos Tasks',
  })
  await expect(table.getByTestId('i18n-locales-country-en')).toHaveText(
    '— no country',
  )
})
