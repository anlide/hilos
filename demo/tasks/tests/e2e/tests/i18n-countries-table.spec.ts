import { expect, test } from '@playwright/test'
import {
  expectI18nPageHead,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens the countries list after reflow, pages it and searches it', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
  await expectI18nPageHead(expect, page, {
    currentPage: 'hilos_i18n_countries',
    appName: 'Hilos Tasks',
  })

  const catalog = page.getByTestId('i18n-countries-catalog')
  await expect(catalog).toBeVisible()
  await expect(catalog.locator('h2')).toContainText(
    /The system knows \d+ languages and \d+ countries on its own/,
  )

  const legend = page.getByTestId('i18n-countries-legend')
  await expect(legend).toBeVisible()
  await expect(legend.getByTestId('i18n-countries-legend-own')).toBeVisible()

  // Fifty-one known countries plus any own country another scene added; twenty-five to a page.
  // Angular keeps the template's whitespace around the count text and toHaveText does not
  // collapse it for a RegExp, so the count is matched with its edges trimmed.
  await expect(page.getByTestId('hilos-table-count')).toHaveText(
    /^\s*1 – 25 of \d+\s*$/,
  )
  const pageTwo = page.getByTestId('hilos-table-page-2')
  await pageTwo.scrollIntoViewIfNeeded()
  await expect(pageTwo).toBeVisible()
  await expect(pageTwo).toBeEnabled()
  await pageTwo.click()
  await expect(page.getByTestId('hilos-table-count')).toHaveText(
    /^\s*26 – 50 of \d+\s*$/,
  )
  await expect(page.getByTestId('hilos-table-page-2')).toHaveAttribute(
    'aria-current',
    'page',
  )

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('united', { delay: 10 })
  const row = page
    .getByTestId(/^hilos-table-row-/)
    .filter({ has: page.getByTestId('i18n-countries-link-us') })
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
  await expect(legend).toBeVisible()

  await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
  await expectI18nPageHead(expect, page, {
    currentPage: 'hilos_i18n',
    appName: 'Hilos Tasks',
  })
  const sectionTab = page.getByTestId('hilos-page-tab-hilos_i18n_countries')
  await sectionTab.click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/countries$/)
  await expectPageReady(expect, page)
  await expectI18nPageHead(expect, page, {
    currentPage: 'hilos_i18n_countries',
    appName: 'Hilos Tasks',
  })
  await expect(catalog).toBeVisible()
})
