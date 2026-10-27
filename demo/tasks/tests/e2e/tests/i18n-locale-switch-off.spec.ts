import { expect, test } from '@playwright/test'
import {
  countSentFrames,
  shownByTestId,
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  switchLanguageOff,
  switchLanguageOn,
  switchLocaleOn,
} from '../../../../../framework/frontend/scripts/i18n.mjs'

test('switches off an ordinary locale and unlocks all seven formats', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await switchLanguageOff('pt')
  await switchLocaleOn('pt')
  await gotoPage(expect, page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
  await expect(shownByTestId(page, 'i18n-locales-enabled-pt')).toBeChecked()

  const view = shownByTestId(page, 'i18n-locales-view-pt')
  await clickSubmit(expect, view)

  const opener = page.getByTestId('i18n-locale-switch-off')
  await clickSubmit(expect, opener)
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Switch off locale · Português without a country',
  )
  await expect(page.getByTestId('i18n-locale-switch-off-warning')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(0)

  const confirm = page.getByTestId('i18n-locale-switch-off-confirm')
  await clickSubmit(expect, confirm)
  await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale pt switched off.' }),
  ).toBeVisible()
  await expect(shownByTestId(page, 'i18n-locales-enabled-pt')).not.toBeChecked()
  await expect(page.getByTestId('i18n-locales-view-pt')).toHaveCount(0)

  const edit = shownByTestId(page, 'i18n-locales-edit-pt')
  await clickSubmit(expect, edit)
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Edit locale · Português without a country',
  )
  for (const field of [
    'date',
    'time',
    'number',
    'phone',
    'address',
    'measurement',
    'collation',
  ]) {
    await expect(page.getByTestId(`i18n-locale-field-${field}`)).toBeEnabled()
  }
})

test('warns for an enabled language and updates both tabs without reloading', async ({
  page,
  context,
}) => {
  await signUpAdmin(expect, page)
  await switchLanguageOn('pt')
  await switchLocaleOn('pt')
  await gotoPage(expect, page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
  const second = await context.newPage()
  await gotoPage(expect, second, '/hilos/i18n/languages/pt/locales', PAGE_READY)

  let reloads = 0
  for (const tab of [page, second]) {
    await expect(shownByTestId(tab, 'i18n-locales-enabled-pt')).toBeChecked()
    tab.on('framenavigated', (frame) => {
      if (frame === tab.mainFrame()) reloads += 1
    })
  }

  const view = shownByTestId(page, 'i18n-locales-view-pt')
  await clickSubmit(expect, view)
  const opener = page.getByTestId('i18n-locale-switch-off')
  await clickSubmit(expect, opener)
  await expect(page.getByTestId('i18n-locale-switch-off-warning')).toHaveText(
    'This language is switched on. While its locale without a country is off, formats fall back to the default language.',
  )
  await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(0)

  const confirm = page.getByTestId('i18n-locale-switch-off-confirm')
  await clickSubmit(expect, confirm)
  await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale pt switched off.' }),
  ).toBeVisible()

  for (const tab of [page, second]) {
    await expect(
      shownByTestId(tab, 'i18n-locales-enabled-pt'),
    ).not.toBeChecked()
    await expect(shownByTestId(tab, 'i18n-locales-edit-pt')).toBeVisible()
    await expect(tab.getByTestId('i18n-locales-view-pt')).toHaveCount(0)
  }
  expect(reloads).toBe(0)
  await gotoPage(expect, page, '/hilos/i18n/languages/pt', PAGE_READY)
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')
})

test('explains the default locale protection without sending a switch-off action', async ({
  page,
}) => {
  const switchOffRequests = countSentFrames(
    page,
    'hilos_i18n_locale_switch_off',
  )
  await signUpAdmin(expect, page)
  await switchLocaleOn('en')
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const view = shownByTestId(page, 'i18n-locales-view-en')
  await clickSubmit(expect, view)
  const opener = page.getByTestId('i18n-locale-switch-off')
  await clickSubmit(expect, opener)

  await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveText(
    'The default language needs its locale without a country: it cannot be switched off.',
  )
  await expect(page.getByTestId('i18n-locale-switch-off-warning')).toHaveCount(
    0,
  )
  const confirm = page.getByTestId('i18n-locale-switch-off-confirm')
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeDisabled()
  await confirm.press('Enter')

  const back = page.getByTestId('i18n-locale-switch-off-back')
  await clickSubmit(expect, back)
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Locale · English without a country',
  )
  await expect(page.getByTestId('i18n-locale-field-date')).toBeDisabled()
  await expect(shownByTestId(page, 'i18n-locales-enabled-en')).toBeChecked()
  expect(switchOffRequests()).toBe(0)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
})
