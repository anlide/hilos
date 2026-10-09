import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  expectPageReady,
  gotoPage,
  PAGE_READY,
  PAGE_REFUSED,
} from '../helpers/page.js'

test('opens the names of the default language directly as a table that says no other language is added', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/names', PAGE_READY)

  // The stand knows one language, the default one from env, and a language has no
  // row of its own in its names table: the table stands empty and says why.
  const table = page.locator('table')
  await expect(table.getByTestId('hilos-table-empty-title')).toHaveText(
    'No other languages yet',
  )
  await expect(table.getByTestId('hilos-table-empty-hint')).toHaveText(
    'A name in another language can be written once that language is added.',
  )
  await expect(table.locator('button, input')).toHaveCount(0)

  // Card header and tabs (HIL-1479).
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

  const namesTab = page.getByTestId(
    'language-card-tab-hilos_i18n_language_names',
  )
  await expect(namesTab).toHaveAttribute('aria-current', 'page')
  await expect(namesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en/names',
  )

  const mainTab = page.getByTestId('language-card-tab-hilos_i18n_language')
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/languages/en')

  await mainTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(page)
  await expect(page.getByTestId('language-card-main')).toBeVisible()

  await page.getByTestId('language-card-tab-hilos_i18n_language_names').click()
  await expect(page).toHaveURL(/\/names$/)
  await expectPageReady(page)
  await expect(table.getByTestId('hilos-table-empty-title')).toHaveText(
    'No other languages yet',
  )

  // The names of a country are not built in Vue yet (HIL-1483).
  await gotoPage(page, '/hilos/i18n/countries/gb/names', PAGE_REFUSED)
})
