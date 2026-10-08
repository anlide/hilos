import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY, PAGE_REFUSED } from '../helpers/page.js'

test('opens a reflowed country card directly with read-only details and staged tabs', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries/us', PAGE_READY)

  await expect(page.getByTestId('country-card-title')).toHaveText(
    'United States',
  )
  await expect(page.getByTestId('country-card-code-badge')).toHaveText('us')
  await expect(page.getByTestId('country-card-own')).toHaveCount(0)
  await expect(page.getByTestId('country-card-line')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )
  await expect(page.getByTestId('country-card-code')).toHaveText('us')
  await expect(page.getByTestId('country-card-currency')).toHaveText('$ USD')
  await expect(page.getByTestId('country-card-default-locale')).toHaveText(
    'not chosen',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('country-card-delete-verdict')).toContainText(
    'the built-in catalog knows this country',
  )
  await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)

  const tabs = page.getByTestId('country-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs.locator('a')).toHaveCount(1)
  await expect(tabs.locator('a')).toHaveAttribute(
    'href',
    '/hilos/i18n/countries/us',
  )
  await expect(tabs.locator('a')).toHaveAttribute('aria-current', 'page')
  await expect(
    page.getByTestId('country-card-main').locator('button, input'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('country-card-state').locator('button, input'),
  ).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n/countries', PAGE_REFUSED)
  await gotoPage(page, '/hilos/i18n', PAGE_REFUSED)
})
