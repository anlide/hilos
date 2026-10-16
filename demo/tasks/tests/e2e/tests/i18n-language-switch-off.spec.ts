import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchLanguageOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('switches off a known language from its card', async ({ page }) => {
  await signUpAdmin(page)
  await switchLanguageOn('fr')
  await gotoPage(page, '/hilos/i18n/languages/fr', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')

  const opener = page.getByTestId('language-card-switch-off')
  await clickSubmit(opener)

  const confirm = page.getByTestId('language-card-switch-off-confirm')
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language switched off.',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('language-card-frozen')).toHaveCount(0)
  await expect(opener).toHaveCount(0)
})
