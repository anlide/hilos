import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY, PAGE_REFUSED } from '../helpers/page.js'

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

  // The names of a country are not built in Vue yet (HIL-1483).
  await gotoPage(page, '/hilos/i18n/countries/gb/names', PAGE_REFUSED)
})
