import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { switchLanguageOff, switchLocaleOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('switches on a language without a countryless locale', async ({
  page,
}) => {
  await signUpAdmin(page)
  await switchLanguageOff('de')
  await gotoPage(page, '/hilos/i18n/languages/de', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Disabled')

  const opener = page.getByTestId('language-card-switch-on')
  await clickSubmit(opener)

  await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
    'not created',
  )
  await expect(
    page.getByTestId('language-card-switch-on-warning'),
  ).toBeVisible()

  const confirm = page.getByTestId('language-card-switch-on-confirm')
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language Deutsch (de) switched on.',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')
  await expect(page.getByTestId('language-card-frozen')).toBeVisible()
  await expect(opener).toHaveCount(0)
})

test('switches on a language with its countryless locale on', async ({
  page,
}) => {
  await signUpAdmin(page)
  await switchLanguageOff('es')
  await switchLocaleOn('es')
  await gotoPage(page, '/hilos/i18n/languages/es', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Disabled')

  const opener = page.getByTestId('language-card-switch-on')
  await clickSubmit(opener)

  await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
    'switched on',
  )
  await expect(page.getByTestId('language-card-switch-on-warning')).toHaveCount(
    0,
  )

  const confirm = page.getByTestId('language-card-switch-on-confirm')
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')
})
