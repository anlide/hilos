import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchCountryOff } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('switches on a known country from its card', async ({ page }) => {
  await signUpAdmin(page)
  await switchCountryOff('ca')
  await gotoPage(page, '/hilos/i18n/countries/ca', PAGE_READY)
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Disabled')

  const opener = page.getByTestId('country-card-switch-on')
  await clickSubmit(opener)

  await expect(page.locator('.modal-title')).toHaveText(
    'Switch on country · Canada',
  )
  await expect(page.getByTestId('country-card-switch-on-note')).toContainText(
    'No default locale is chosen',
  )

  const confirm = page.getByTestId('country-card-switch-on-confirm')
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Country switched on.',
  )
  await expect(page.getByTestId('country-card-enabled')).toHaveText('Enabled')
  await expect(page.getByTestId('country-card-frozen')).toBeVisible()
  await expect(opener).toHaveCount(0)
})
