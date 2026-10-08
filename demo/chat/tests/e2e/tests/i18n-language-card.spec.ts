import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY, PAGE_REFUSED } from '../helpers/page.js'

test('opens the default language card directly with read-only details and staged tabs', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)

  await expect(page.getByTestId('language-card-code-tile')).toHaveText('en')
  await expect(page.getByTestId('language-card-native-name')).toHaveText(
    'English',
  )
  await expect(page.getByTestId('language-card-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-card-counts')).toHaveText(
    /Locales: \d+ · Names: \d+/,
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

  const tabs = page.getByTestId('language-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs).toContainText('Locales')
  // The names page is built in Vue (HIL-1477); the locales tab stays a label.
  await expect(tabs.locator('a')).toHaveCount(2)
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
    page.getByTestId('language-card-main').locator('button, input'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('language-card-state').locator('button, input'),
  ).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n/languages', PAGE_REFUSED)
  await gotoPage(page, '/hilos/i18n', PAGE_REFUSED)
})
