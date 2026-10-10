import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the countries list after reflow, pages it and searches it', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries', PAGE_READY)

  // Fifty-one known countries, twenty-five to a page. `us` sorts past the first page.
  await expect(page.getByTestId('hilos-table-count')).toHaveText('1 – 25 of 51')
  const pageTwo = page.getByTestId('hilos-table-page-2')
  await pageTwo.scrollIntoViewIfNeeded()
  await expect(pageTwo).toBeVisible()
  await expect(pageTwo).toBeEnabled()
  await pageTwo.click()
  await expect(page.getByTestId('hilos-table-count')).toHaveText(
    '26 – 50 of 51',
  )
  await expect(page.getByTestId('hilos-table-page-2')).toHaveAttribute(
    'aria-current',
    'page',
  )

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('united', { delay: 10 })
  const row = page.getByTestId('hilos-table-row-us')
  const link = row.getByTestId('i18n-countries-link-us')
  await expect(link).toBeVisible()
  await expect(link).toHaveAttribute('href', '/hilos/i18n/countries/us')
  await expect(row).toContainText('United States')
  await expect(row).toContainText('$')
  await expect(row).toContainText('USD')
  await expect(row.getByTestId('i18n-countries-locale-none-us')).toContainText(
    'Not chosen',
  )
  await expect(row.getByTestId('i18n-countries-enabled-us')).not.toBeChecked()

  await search.fill('')
  await search.pressSequentially('zzzz-absent', { delay: 10 })
  await expect(page.locator('table').getByText('Nothing found')).toBeVisible()
  await expect(row).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n', PAGE_READY)
  await expect(
    page.getByTestId('hilos-admin-child-hilos_i18n_countries'),
  ).toBeVisible()
})
