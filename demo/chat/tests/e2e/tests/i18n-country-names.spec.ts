import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the names of a reflowed country directly with the shared header, tabs, and the names table', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries/us/names', PAGE_READY)

  await expect(page.getByTestId('country-card-title')).toHaveText(
    'United States',
  )
  await expect(page.getByTestId('country-card-code-badge')).toHaveText('us')
  await expect(page.getByTestId('country-card-own')).toHaveCount(0)
  await expect(page.getByTestId('country-card-line')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )

  const tabs = page.getByTestId('country-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs.locator('a')).toHaveCount(2)

  const namesTab = page.getByTestId('country-card-tab-hilos_i18n_country_names')
  await expect(namesTab).toHaveAttribute('aria-current', 'page')
  await expect(namesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/countries/us/names',
  )

  const mainTab = page.getByTestId('country-card-tab-hilos_i18n_country')
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/countries/us')

  const enRow = page.getByTestId('hilos-table-row-en')
  await expect(enRow).toContainText('English')
  await expect(enRow).toContainText('United States')
  await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-expand-en')).toHaveCount(0)

  const section = page.getByTestId('country-names')
  await expect(section.locator('button, input')).toHaveCount(0)
})
