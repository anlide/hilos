import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the locales of the default language directly with its own locale on and nothing to press', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/locales', PAGE_READY)

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
  await expect(table.locator('button')).toHaveCount(0)

  // Card header and tabs (HIL-1480).
  await expect(page.getByTestId('language-card-code-tile')).toHaveText('en')
  await expect(page.getByTestId('language-card-native-name')).toHaveText(
    'English',
  )
  await expect(page.getByTestId('language-card-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-card-counts')).toHaveText(
    /Locales: \d+ · Names: \d+/,
  )

  const localesTab = page.getByTestId(
    'language-card-tab-hilos_i18n_language_locales',
  )
  await expect(localesTab).toHaveAttribute('aria-current', 'page')
  await expect(localesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en/locales',
  )

  const mainTab = page.getByTestId('language-card-tab-hilos_i18n_language')
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/languages/en')

  await mainTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(page)
  await expect(page.getByTestId('language-card-main')).toBeVisible()

  await page
    .getByTestId('language-card-tab-hilos_i18n_language_locales')
    .click()
  await expect(page).toHaveURL(/\/locales$/)
  await expectPageReady(page)
  await expect(table.getByTestId('i18n-locales-country-en')).toHaveText(
    '— no country',
  )
})
