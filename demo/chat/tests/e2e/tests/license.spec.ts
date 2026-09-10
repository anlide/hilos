import { test, expect } from '@playwright/test'
import { gotoPage } from '../helpers/page'

// The /license inventory e2e: the build-time snapshot generated from this
// project's own lockfiles reaches the framework page as a prop, and the page's
// three active elements work over it — the filter narrows the list, an
// unmatched filter says so instead of showing an empty page, and a row opens
// that package's own license text. Copy and download are left to the unit
// tests: clipboard permissions and download interception buy flakiness, not
// truth.

test('the search narrows the list to what it asks for', async ({ page }) => {
  await gotoPage(page, '/license')
  const rows = page.getByTestId('license-row')
  await expect(rows.first()).toBeVisible()
  const wholeBuild = await rows.count()
  expect(wholeBuild).toBeGreaterThan(1)

  const search = page.getByTestId('license-search')
  await search.fill('')
  await search.pressSequentially('bootstrap', { delay: 10 })

  await expect(rows).not.toHaveCount(wholeBuild)
  const shown = await rows.allTextContents()
  expect(shown.length).toBeGreaterThan(0)
  for (const row of shown) {
    expect(row).toContain('bootstrap')
  }
})

test('an unmatched filter says so, and the export stays live', async ({
  page,
}) => {
  await gotoPage(page, '/license')
  await expect(page.getByTestId('license-row').first()).toBeVisible()

  const search = page.getByTestId('license-search')
  await search.fill('')
  await search.pressSequentially('nothing-stands-on-this', { delay: 10 })

  await expect(page.getByTestId('license-row')).toHaveCount(0)
  await expect(page.getByTestId('license-empty')).toBeVisible()
  // The empty result is this page's most valuable answer, so the choice that
  // produced it stays visible and removable, and the list can still be taken
  // away — on an empty result that is the header row alone.
  await expect(search).toBeVisible()
  await expect(page.getByTestId('license-copy')).toBeEnabled()
  await expect(page.getByTestId('license-download')).toBeEnabled()
})

test("a row opens that package's own license text and closes again", async ({
  page,
}) => {
  await gotoPage(page, '/license')
  const search = page.getByTestId('license-search')
  await search.fill('')
  await search.pressSequentially('bootstrap', { delay: 10 })

  const row = page.getByTestId('license-row').first()
  await expect(row).toBeVisible()
  await row.click()

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
