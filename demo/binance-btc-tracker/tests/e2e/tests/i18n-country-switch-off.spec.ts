import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchCountryOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('switches off a known country from its card', async ({ page }) => {
  await signUpAdmin(page)
  await switchCountryOn('it')
  await gotoPage(page, '/hilos/i18n/countries/it', PAGE_READY)
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')

  const opener = page.getByTestId('country-card-switch-off')
  await clickSubmit(opener)

  await expect(page.getByTestId('country-card-switch-off-counts')).toBeVisible()
  await expect(
    page.getByTestId('country-card-switch-off-locale-count'),
  ).toHaveText(/^\d+$/)
  await expect(
    page.getByTestId('country-card-switch-off-name-count'),
  ).toHaveText(/^\d+$/)

  const confirm = page.getByTestId('country-card-switch-off-confirm')
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country Italy (it) switched off.',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
  await expect(opener).toHaveCount(0)
  await expect(page.getByTestId('country-card-switch-on')).toBeVisible()
})
