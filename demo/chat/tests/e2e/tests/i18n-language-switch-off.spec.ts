import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchLanguageOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('switches off a known language from its card', async ({ page }) => {
  await signUpAdmin(page)
  await switchLanguageOn('fr')
  await gotoPage(page, '/hilos/i18n/languages/fr', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')

  const opener = page.getByTestId('language-card-switch-off')
  await opener.scrollIntoViewIfNeeded()
  await expect(opener).toBeVisible()
  await expect(opener).toBeEnabled()
  await opener.focus()
  await opener.click()

  const confirm = page.getByTestId('language-card-switch-off-confirm')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language switched off.',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Disabled')
  await expect(page.getByTestId('language-card-frozen')).toHaveCount(0)
  await expect(opener).toHaveCount(0)
})

test('shows why the default language cannot be switched off', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)

  const opener = page.getByTestId('language-card-switch-off')
  await expect(opener).toBeDisabled()
  await expect(
    page.getByTestId('language-card-switch-off-reason'),
  ).toContainText('default language')
  await expect(opener).toHaveAttribute(
    'aria-describedby',
    'language-card-switch-off-reason',
  )
})
