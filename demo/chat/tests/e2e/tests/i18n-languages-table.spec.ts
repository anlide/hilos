import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the languages list with the default language and searches it', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)

  // The same cells are also drawn in the narrow-screen card, which stays in the
  // document. The wide table is the one this viewport shows.
  const row = page.getByTestId('hilos-table-row-en')
  const link = row.getByTestId('i18n-languages-link-en')
  await expect(link).toBeVisible()
  await expect(link).toHaveAttribute('href', '/hilos/i18n/languages/en')
  await expect(row.getByTestId('i18n-languages-default-en')).toContainText(
    'Default language',
  )
  await expect(row.getByTestId('i18n-languages-enabled-en')).toBeChecked()

  const search = page.getByTestId('hilos-table-search')
  await search.fill('en')
  await expect(link).toBeVisible()

  await search.fill('zzzz-absent')
  await expect(page.locator('table').getByText('Nothing found')).toBeVisible()
  await expect(row).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n', PAGE_READY)
  await expect(
    page.getByTestId('hilos-admin-child-hilos_i18n_languages'),
  ).toBeVisible()
  await expect(
    page.getByTestId('hilos-admin-child-hilos_i18n_countries'),
  ).toBeVisible()
})
