import { expect, test } from '@playwright/test'
import {
  expectLanguagePageHead,
  expectNoOtherLanguageNames,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

const APP_NAME = 'Hilos Polls'
const OVERVIEW = 'hilos_i18n_language'
const NAMES = 'hilos_i18n_language_names'

test('opens the names of the default language directly as a table that says no other language is added', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/names', PAGE_READY)

  // The stand knows one language, the default one from env, and a language has no
  // row of its own in its names table: the table stands empty and says why.
  await expectNoOtherLanguageNames(expect, page)
  const table = page.locator('table')
  await expect(table.getByTestId('hilos-table-empty-hint')).toHaveText(
    'A name in another language can be written once that language is added.',
  )
  await expect(table.locator('button, input')).toHaveCount(0)
  await expect(table).toHaveAccessibleName('English')

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: NAMES,
    appName: APP_NAME,
  })

  const namesTab = page.getByTestId(`hilos-page-tab-${NAMES}`)
  await expect(namesTab).toHaveAttribute('aria-current', 'page')
  await expect(namesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en/names',
  )

  const mainTab = page.getByTestId(`hilos-page-tab-${OVERVIEW}`)
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/languages/en')

  await mainTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: OVERVIEW,
    appName: APP_NAME,
  })
  await expect(page.getByTestId('language-card-main')).toBeVisible()

  await page.getByTestId(`hilos-page-tab-${NAMES}`).click()
  await expect(page).toHaveURL(/\/names$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: NAMES,
    appName: APP_NAME,
  })
  await expectNoOtherLanguageNames(expect, page)
})
