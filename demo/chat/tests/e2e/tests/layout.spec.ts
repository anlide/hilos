import { test, expect } from '@playwright/test'
import {
  watchClientDocument,
  expectPageReady,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'

const PUBLIC_PAGES = [
  {
    page: 'hilos_about',
    path: '/about',
    title: 'About',
  },
  {
    page: 'hilos_terms',
    path: '/terms',
    title: 'Terms',
  },
  {
    page: 'hilos_privacy',
    path: '/privacy',
    title: 'Privacy',
  },
  {
    page: 'hilos_license',
    path: '/license',
    title: 'License',
  },
] as const

// The SDK shell renders the framework's public footer links. Each click opens a
// framework-owned static page over the live socket without reloading the document.
test('footer links navigate to framework static pages', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads
  const document = await watchClientDocument(page)

  try {
    // All four framework footer links are present.
    for (const publicPage of PUBLIC_PAGES) {
      await expect(
        page.getByTestId(`footer-link-${publicPage.page}`),
      ).toBeVisible()
    }

    for (const publicPage of PUBLIC_PAGES) {
      await page.getByTestId(`footer-link-${publicPage.page}`).click()
      await expectPageReady(expect, page)

      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveText(publicPage.title)
      await expect
        .poll(() => new URL(page.url()).pathname)
        .toBe(publicPage.path)
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await document.unchanged()
      expect(fullLoads).toBe(loadsAfterColdLoad)
    }
  } finally {
    await document.dispose()
  }
})
