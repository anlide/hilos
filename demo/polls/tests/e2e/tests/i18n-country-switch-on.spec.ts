import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { switchCountryOff } from '../../../../../framework/frontend/scripts/i18n.mjs'

test('switches on a known country from its card', async ({ page }) => {
  await signUpAdmin(expect, page)
  await switchCountryOff('ca')
  await gotoPage(expect, page, '/hilos/i18n/countries/ca', PAGE_READY)
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')

  const opener = page.getByTestId('country-card-switch-on')
  await clickSubmit(expect, opener)

  await expect(page.locator('.modal-title')).toHaveText(
    'Switch on country · Canada',
  )
  await expect(page.getByTestId('country-card-switch-on-note')).toContainText(
    'No default locale is chosen',
  )

  const confirm = page.getByTestId('country-card-switch-on-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country Canada (ca) switched on.',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
  await expect(page.getByTestId('country-card-frozen')).toBeVisible()
  await expect(opener).toHaveCount(0)
})
