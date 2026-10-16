import { expect, test } from '@playwright/test'
import { expectDefaultLanguageHeader } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the default language card directly with read-only details and staged tabs', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)

  await expectDefaultLanguageHeader(expect, page)
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

  const tabs = page.getByTestId('language-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs).toContainText('Locales')
  // The names (HIL-1477) and locales (HIL-1476) pages are built in Vue: every tab is a link.
  await expect(tabs.locator('a')).toHaveCount(3)
  await expect(tabs.locator('a').first()).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en',
  )
  await expect(tabs.locator('a').first()).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(
    page.getByTestId('language-card-tab-hilos_i18n_language_names'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/names')
  await expect(
    page.getByTestId('language-card-tab-hilos_i18n_language_locales'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/locales')
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

  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await gotoPage(page, '/hilos/i18n', PAGE_READY)
})
