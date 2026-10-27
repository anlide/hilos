import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { switchCountryOn } from '../../../../../framework/frontend/scripts/i18n.mjs'

test('switches off a known country from its card', async ({ page }) => {
  await signUpAdmin(expect, page)
  await switchCountryOn('it')
  await gotoPage(expect, page, '/hilos/i18n/countries/it', PAGE_READY)
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')

  const opener = page.getByTestId('country-card-switch-off')
  await clickSubmit(expect, opener)

  await expect(page.getByTestId('country-card-switch-off-counts')).toBeVisible()
  await expect(
    page.getByTestId('country-card-switch-off-locale-count'),
  ).toHaveText(/^\d+$/)
  await expect(
    page.getByTestId('country-card-switch-off-name-count'),
  ).toHaveText(/^\d+$/)

  const confirm = page.getByTestId('country-card-switch-off-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country Italy (it) switched off.',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('country-card-frozen')).toHaveCount(0)
  await expect(opener).toHaveCount(0)
  await expect(page.getByTestId('country-card-switch-on')).toBeVisible()
})
