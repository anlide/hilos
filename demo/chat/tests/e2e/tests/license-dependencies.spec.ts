import { test, expect } from '@playwright/test'
import {
  expectPageReady,
  gotoPage,
  PAGE_READY,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'
import { hilosLicenseInventory } from '../../../frontend/src/generated/hilosLicenseInventory.js'

// This guest journey crosses the public page key, backend registration and
// access gate, and the project's real outlet. The inventory is the same build
// input the application mounts; filters, exports and modal arithmetic stay unit-tested.
test('a guest can follow Dependencies, return with Back and enter or reload its route', async ({
  page,
}) => {
  const packageNames = hilosLicenseInventory.entries.map((entry) => entry.name)
  expect(packageNames.length).toBeGreaterThan(0)
  const projectText = page.getByTestId('license-project-text')
  const packages = page
    .getByRole('table', { name: 'License', exact: true })
    .getByTestId('license-package-open')

  await gotoPage(expect, page, '/license', PAGE_READY)
  await expect(projectText).toHaveText(hilosLicenseInventory.projectLicenseText)
  await expect(page.getByRole('table')).toHaveCount(0)
  const document = await watchClientDocument(page)
  try {
    await test.step('follow the public link into the complete inventory', async () => {
      const link = page.getByTestId('hilos-page-tab-hilos_license_dependencies')
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
})
