import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  switchLanguageOff,
  switchLocaleOn,
} from '../../../../../framework/frontend/scripts/i18n.mjs'

test('switches on a language without a countryless locale', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await switchLanguageOff('de')
  await gotoPage(expect, page, '/hilos/i18n/languages/de', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText(
    'Language switched off',
  )

  const opener = page.getByTestId('language-card-switch-on')
  await clickSubmit(expect, opener)

  await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
    'not created',
  )
  await expect(
    page.getByTestId('language-card-switch-on-warning'),
  ).toBeVisible()

  const confirm = page.getByTestId('language-card-switch-on-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language Deutsch (de) switched on.',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText(
    'Language switched on',
  )
  await expect(page.getByTestId('language-card-frozen')).toBeVisible()
  await expect(opener).toHaveCount(0)
})

test('switches on a language with its countryless locale on', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await switchLanguageOff('es')
  await switchLocaleOn('es')
  await gotoPage(expect, page, '/hilos/i18n/languages/es', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText(
    'Language switched off',
  )

  const opener = page.getByTestId('language-card-switch-on')
  await clickSubmit(expect, opener)

  await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
    'switched on',
  )
  await expect(page.getByTestId('language-card-switch-on-warning')).toHaveCount(
    0,
  )

  const confirm = page.getByTestId('language-card-switch-on-confirm')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('language-card-enabled')).toHaveText(
    'Language switched on',
  )
})
