import { expect, test } from '@playwright/test'
import { expectLanguagePageHead } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

const APP_NAME = 'Hilos Polls'
const OVERVIEW = 'hilos_i18n_language'
const LOCALES = 'hilos_i18n_language_locales'

test('opens the locales of the default language directly with its own locale on and a view control', async ({
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
  await expect(table.getByTestId('i18n-locales-view-en')).toBeVisible()
  await expect(table.getByTestId('i18n-locales-add-en-GB')).toBeVisible()
  await expect(table).toHaveAccessibleName('English')

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: LOCALES,
    appName: APP_NAME,
  })

  const localesTab = page.getByTestId(`hilos-page-tab-${LOCALES}`)
  await expect(localesTab).toHaveAttribute('aria-current', 'page')
  await expect(localesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en/locales',
  )

  const mainTab = page.getByTestId(`hilos-page-tab-${OVERVIEW}`)
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/languages/en')

  await mainTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: OVERVIEW,
    appName: APP_NAME,
  })
  await expect(page.getByTestId('language-card-main')).toBeVisible()

  await page.getByTestId(`hilos-page-tab-${LOCALES}`).click()
  await expect(page).toHaveURL(/\/locales$/)
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: LOCALES,
    appName: APP_NAME,
  })
  await expect(table.getByTestId('i18n-locales-country-en')).toHaveText(
    '— no country',
  )
})
