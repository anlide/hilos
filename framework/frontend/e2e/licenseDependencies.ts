import type { Expect, Page } from '@playwright/test'

import { shownByTestId } from './table.js'

/**
 * Check the public Dependencies outlet's shared accessibility contract.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The guest browser showing the settled Dependencies page.
 * @param appName The application's browser-title suffix.
 */
export async function expectLicenseDependenciesAccessibility(
  expect: Expect,
  page: Page,
  appName: string,
): Promise<void> {
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('License')
  const table = page.getByRole('table', { name: 'License', exact: true })
  await expect(table).toBeVisible()
  const headingId = await heading.getAttribute('id')
  expect(headingId).toBeTruthy()
  await expect(table).toHaveAttribute('aria-labelledby', headingId!)

  const title = `License · Dependencies · ${appName}`
  await expect(page).toHaveTitle(title)
  await expect(page.getByTestId('page-title')).toHaveText(title)
  const navigation = page.getByRole('navigation', { name: 'Page sections' })
  await expect(navigation).toBeVisible()
  await expect(page.getByRole('tablist')).toHaveCount(0)
  const license = navigation.getByTestId('hilos-page-tab-hilos_license')
  await expect(license).toHaveRole('link')
  await expect(license).toHaveAccessibleName('License')
  await expect(license).toHaveAttribute('href', '/license')
  await expect(license).not.toHaveAttribute('aria-current', 'page')
  const dependencies = navigation.getByTestId(
    'hilos-page-tab-hilos_license_dependencies',
  )
  await expect(dependencies).toHaveRole('link')
  await expect(dependencies).toHaveAccessibleName('Dependencies')
  await expect(dependencies).toHaveAttribute('href', '/license/dependencies')
  await expect(dependencies).toHaveAttribute('aria-current', 'page')

  await expect(
    page.getByRole('searchbox', { name: 'Search a package or a license' }),
  ).toBeEnabled()
  for (const name of [
    'License: All licenses',
    'Language: All languages',
    'Copy the list',
    'Download as a file',
  ]) {
    const control = page.getByRole('button', { name, exact: true })
    await expect(control).toBeVisible()
    await expect(control).toBeEnabled()
  }
  await expect(page.getByTestId('license-copy-status')).toHaveRole('status')
  await expect(page.getByTestId('license-copy-status')).toHaveAttribute(
    'aria-live',
    'polite',
  )

  const licenseFilter = shownByTestId(
    page,
    'hilos-table-filter-license',
  ).getByTestId('hilos-dropdown-toggle')
  await licenseFilter.focus()
  await expect(licenseFilter).toBeFocused()
  await licenseFilter.press('ArrowDown')
  const choices = page.getByRole('listbox', { name: 'License', exact: true })
  await expect(choices).toBeVisible()
  await expect(
    choices.getByRole('option', { name: 'All licenses', exact: true }),
  ).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(choices).toBeHidden()
  await expect(licenseFilter).toBeFocused()
}
