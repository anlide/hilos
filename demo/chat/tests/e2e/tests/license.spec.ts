import { test, expect } from '@playwright/test'
import {
  gotoPage,
  shownByTestId,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

// The /license/dependencies inventory e2e: the build-time snapshot generated
// from this project's lockfiles reaches the framework page as a prop, and its
// standard table, search, empty state and package reader work over it. Copy and
// download are left to the unit tests: clipboard permissions and download
// interception buy flakiness, not truth.

test('the search narrows the list to what it asks for', async ({ page }) => {
  await gotoPage(expect, page, '/license/dependencies')
  const packages = shownByTestId(page, 'license-package-open')
  await expect(packages.first()).toBeVisible()
  const wholeBuild = await packages.count()
  expect(wholeBuild).toBeGreaterThan(1)

  const search = shownByTestId(page, 'hilos-table-search')
  await typeInto(search, 'bootstrap')

  await expect(packages).not.toHaveCount(wholeBuild)
  const shown = await packages.allTextContents()
  expect(shown.length).toBeGreaterThan(0)
  for (const packageName of shown) {
    expect(packageName).toContain('bootstrap')
  }
})

test('an unmatched filter says so, and the export stays live', async ({
  page,
}) => {
  await gotoPage(expect, page, '/license/dependencies')
  const packages = shownByTestId(page, 'license-package-open')
  await expect(packages.first()).toBeVisible()

  const search = shownByTestId(page, 'hilos-table-search')
  await typeInto(search, 'nothing-stands-on-this')

  await expect(packages).toHaveCount(0)
  const empty = shownByTestId(page, 'hilos-table-no-matches')
  await expect(empty).toBeVisible()
  await expect(empty).toContainText('Nothing found')
  // The empty result is this page's most valuable answer, so the choice that
  // produced it stays visible and removable, and the list can still be taken
  // away — on an empty result that is the header row alone.
  await expect(search).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-action-copy')).toBeEnabled()
  await expect(shownByTestId(page, 'hilos-table-action-download')).toBeEnabled()
})

test('a package opens its own license text and closes again', async ({
  page,
}) => {
  await gotoPage(expect, page, '/license/dependencies')
  const search = shownByTestId(page, 'hilos-table-search')
  await typeInto(search, 'bootstrap')

  const packageButton = shownByTestId(page, 'license-package-open').first()
  await expect(packageButton).toBeVisible()
  await packageButton.click()

  const modal = page.getByTestId('modal')
  await expect(modal).toBeVisible()
  // The text comes from the package on disk, not from a retelling: every
  // package in this build ships a license file, so the second state of this
  // modal is the unit tests' to cover.
  await expect(page.getByTestId('license-text')).toBeVisible()
  await expect(page.getByTestId('license-text')).toContainText('Permission is')

  await page.getByTestId('modal-close').click()
  await expect(modal).toBeHidden()
})
