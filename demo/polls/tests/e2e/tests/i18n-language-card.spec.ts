import { expect, test } from '@playwright/test'
import { expectLanguagePageHead } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

const APP_NAME = 'Hilos Polls'
const OVERVIEW = 'hilos_i18n_language'
const NAMES = 'hilos_i18n_language_names'
const LOCALES = 'hilos_i18n_language_locales'
const LANGUAGE_PATH = '/hilos/i18n/languages/en'

test('opens the default language directly with read-only details and its shared head tabs', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, LANGUAGE_PATH, PAGE_READY)

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: OVERVIEW,
    appName: APP_NAME,
  })
  await expect(page.getByTestId('language-head-tile')).toHaveText('en')
  await expect(page.getByTestId('language-head-tile')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('language-head-default')).toContainText(
    'Default language',
  )
  await expect(page.getByTestId('language-head-own')).toHaveCount(0)
  await expect(page.getByTestId('language-head-direction')).toHaveText(
    'Left to right',
  )
  await expect(page.getByTestId('language-card-direction')).toHaveText(
    'Left to right',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText('Enabled')
  await expect(page.getByTestId('language-card-delete-verdict')).toContainText(
    'default language',
  )
  await expect(page.getByTestId('language-card-frozen')).toHaveText(
    'Language enabled — frozen',
  )

  await expect(
    page.getByTestId('hilos-page-tabs').getByRole('link'),
  ).toHaveText(['Overview', 'Names', 'Locales'])
  await expect(
    page.getByTestId('language-card-main').locator('button, input'),
  ).toHaveCount(1)
  await expect(
    page.getByTestId('language-card-main').getByTestId('language-card-view'),
  ).toBeVisible()
  await expect(
    page.getByTestId('language-card-state').locator('button'),
  ).toHaveCount(2)
  const switchOff = page.getByTestId('language-card-switch-off')
  await expect(switchOff).toBeDisabled()
  await expect(
    page.getByTestId('language-card-switch-off-reason'),
  ).toContainText('default language')
  await expect(switchOff).toHaveAttribute(
    'aria-describedby',
    'language-card-switch-off-reason',
  )
  const deleteButton = page.getByTestId('language-card-delete')
  const deleteReason = page.getByTestId('language-card-delete-reason')
  await expect(deleteButton).toHaveRole('button')
  await expect(deleteButton).toBeVisible()
  await expect(deleteButton).toBeDisabled()
  await expect(deleteButton).toHaveText('Delete language')
  await expect(deleteReason).toBeVisible()
  await expect(deleteReason).toHaveText(
    'Cannot delete: this is the default language.',
  )
  await expect(deleteReason).toHaveAttribute(
    'id',
    'language-card-delete-reason',
  )
  await expect(deleteButton).toHaveAttribute(
    'aria-describedby',
    (await deleteReason.getAttribute('id'))!,
  )
  await expect(
    page.getByTestId('language-card-state').locator('input'),
  ).toHaveCount(0)

  await page.getByTestId(`hilos-page-tab-${NAMES}`).click()
  await expect(page).toHaveURL(`${LANGUAGE_PATH}/names`)
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: NAMES,
    appName: APP_NAME,
  })

  await page.goBack()
  await expect(page).toHaveURL(`${LANGUAGE_PATH}`)
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: OVERVIEW,
    appName: APP_NAME,
  })

  await page.getByTestId(`hilos-page-tab-${LOCALES}`).click()
  await expect(page).toHaveURL(`${LANGUAGE_PATH}/locales`)
  await expectPageReady(page)
  await page.reload()
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: LOCALES,
    appName: APP_NAME,
  })

  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await gotoPage(page, '/hilos/i18n', PAGE_READY)
})
