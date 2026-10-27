import { expect, test } from '@playwright/test'
import {
  expectLanguagePageHead,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens the default language card directly with read-only details and staged tabs', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en', PAGE_READY)

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'Hilos Tasks',
  })
  await expect(page.getByTestId('language-head-tile')).toHaveText('en')
  await expect(page.getByTestId('language-head-tile')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('language-head-default')).toContainText(
    'Default language',
  )
  await expect(page.getByTestId('language-head-default')).toHaveAttribute(
    'title',
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

  await page.getByTestId('hilos-page-tab-hilos_i18n_language_names').click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en\/names$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_names',
    appName: 'Hilos Tasks',
  })

  await page.goBack()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'Hilos Tasks',
  })

  await page.getByTestId('hilos-page-tab-hilos_i18n_language_locales').click()
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en\/locales$/)
  await expectPageReady(expect, page)
  await page.reload()
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_locales',
    appName: 'Hilos Tasks',
  })

  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
  await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
})
