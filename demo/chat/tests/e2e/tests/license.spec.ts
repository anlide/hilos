import { test, expect } from '../test.js'
import {
  expectLicenseDependenciesAccessibility,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  shownByTestId,
  typeInto,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'
import { hilosLicenseInventory } from '../../../frontend/src/generated/hilosLicenseInventory.js'

// The /license/dependencies inventory e2e: the build-time snapshot generated
// from this project's lockfiles reaches the framework page as a prop, and its
// standard table, search, empty state and package reader work over it. Copy and
// download are left to the unit tests: clipboard permissions and download
// interception buy flakiness, not truth.

// This guest journey crosses the public page key, backend registration and
// access gate, and the project's real outlet. The inventory is the same build
// input the application mounts; filters, exports and modal arithmetic stay unit-tested.
test('a guest can navigate the public license and search and read dependency licenses', async ({
  page,
}) => {
  const packageNames = hilosLicenseInventory.entries.map((entry) => entry.name)
  const projectText = page.getByTestId('license-project-text')
  const packages = page
    .getByRole('table', { name: 'License', exact: true })
    .getByTestId('license-package-open')

  await test.step('a guest can follow Dependencies, return with Back and enter or reload its route', async () => {
    expect(packageNames.length).toBeGreaterThan(0)

    await gotoPage(expect, page, '/license', PAGE_READY)
    await expect(projectText).toHaveText(
      hilosLicenseInventory.projectLicenseText,
    )
    await expect(page.getByRole('table')).toHaveCount(0)
    const document = await watchClientDocument(page)
    try {
      await test.step('follow the public link into the complete inventory', async () => {
        const link = page.getByTestId(
          'hilos-page-tab-hilos_license_dependencies',
        )
        await expect(link).toHaveAttribute('href', '/license/dependencies')
        await link.click()
        await expect(page).toHaveURL('/license/dependencies')
        await expectPageReady(expect, page)
        await expect(packages).toHaveText(packageNames)
        await expect(projectText).toHaveCount(0)
        await document.unchanged()
      })

      await test.step('Back restores the project license and Forward restores Dependencies', async () => {
        await page.goBack()
        await expect(page).toHaveURL('/license')
        await expectPageReady(expect, page)
        await expect(projectText).toHaveText(
          hilosLicenseInventory.projectLicenseText,
        )
        await expect(page.getByRole('table')).toHaveCount(0)
        await document.unchanged()

        await page.goForward()
        await expect(page).toHaveURL('/license/dependencies')
        await expectPageReady(expect, page)
        await expect(packages).toHaveText(packageNames)
        await expect(projectText).toHaveCount(0)
        await document.unchanged()
      })
    } finally {
      await document.dispose()
    }
  })

  await test.step('public Dependencies exposes a named table and keyboard navigation', async () => {
    await gotoPage(expect, page, '/license')
    const dependencies = page.getByTestId(
      'hilos-page-tab-hilos_license_dependencies',
    )
    await dependencies.focus()
    await expect(dependencies).toBeFocused()
    await dependencies.press('Enter')
    await expect(page).toHaveURL('/license/dependencies')
    await expectPageReady(expect, page)
    await expectLicenseDependenciesAccessibility(expect, page, 'Hilos Chat')
  })

  await test.step('direct entry and F5 resolve the same public outlet', async () => {
    await gotoPage(expect, page, '/license/dependencies', PAGE_READY)
    await expect(packages).toHaveText(packageNames)
    await expect(projectText).toHaveCount(0)

    await page.reload()
    await expect(page).toHaveURL('/license/dependencies')
    await expectPageReady(expect, page)
    await expect(packages).toHaveText(packageNames)
    await expect(projectText).toHaveCount(0)
  })

  await test.step('the search narrows the list to what it asks for', async () => {
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

  await test.step('a package opens its own license text and closes again', async () => {
    const packageButton = shownByTestId(page, 'license-package-open').first()
    await expect(packageButton).toBeVisible()
    await packageButton.click()

    const modal = page.getByTestId('modal')
    await expect(modal).toBeVisible()
    // The text comes from the package on disk, not from a retelling: every
    // package in this build ships a license file, so the second state of this
    // modal is the unit tests' to cover.
    await expect(page.getByTestId('license-text')).toBeVisible()
    await expect(page.getByTestId('license-text')).toContainText(
      'Permission is',
    )

    await page.getByTestId('modal-close').click()
    await expect(modal).toBeHidden()
  })

  await test.step('an unmatched filter says so, and the export stays live', async () => {
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
    await expect(
      shownByTestId(page, 'hilos-table-action-download'),
    ).toBeEnabled()
  })
})
