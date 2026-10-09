import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

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
})
