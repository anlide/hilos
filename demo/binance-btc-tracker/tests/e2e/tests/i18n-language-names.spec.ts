import { expect, test } from '@playwright/test'
import {
  expectDefaultLanguageHeader,
  expectNoOtherLanguageNames,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the names of the default language directly as a table that says no other language is added', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en/names', PAGE_READY)

  // The stand knows one language, the default one from env, and a language has no
  // row of its own in its names table: the table stands empty and says why.
  await expectNoOtherLanguageNames(expect, page)
  const table = page.locator('table')
  await expect(table.getByTestId('hilos-table-empty-hint')).toHaveText(
    'A name in another language can be written once that language is added.',
  )
  await expect(table.locator('button, input')).toHaveCount(0)

  // Card header and tabs (HIL-1479).
  await expectDefaultLanguageHeader(expect, page)

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
  await expectNoOtherLanguageNames(expect, page)
})
