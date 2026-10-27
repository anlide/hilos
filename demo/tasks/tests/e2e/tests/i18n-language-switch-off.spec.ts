import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { switchLanguageOn } from '../../../../../framework/frontend/scripts/i18n.mjs'

test('switches off a known language from its card', async ({ page }) => {
  await signUpAdmin(expect, page)
  await switchLanguageOn('fr')
  await gotoPage(expect, page, '/hilos/i18n/languages/fr', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')

  const opener = page.getByTestId('language-card-switch-off')
  await clickSubmit(expect, opener)

  const confirm = page.getByTestId('language-card-switch-off-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language Français (fr) switched off.',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('language-card-frozen')).toHaveCount(0)
  await expect(opener).toHaveCount(0)
})
