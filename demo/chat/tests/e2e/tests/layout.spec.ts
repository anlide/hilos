import { test, expect } from '@playwright/test'
import { watchClientDocument } from '../../../../../framework/frontend/e2e/index.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'

const PUBLIC_PAGES = [
  {
    page: 'hilos_about',
    path: '/about',
    title: 'About',
    icon: 'bi-info-circle',
    subtitle: 'What this project is, who makes it, and how it keeps going.',
  },
  {
    page: 'hilos_terms',
    path: '/terms',
    title: 'Terms',
    icon: 'bi-file-earmark-text',
    subtitle:
      'The terms of using this project: the revision in force and every one before it.',
  },
  {
    page: 'hilos_privacy',
    path: '/privacy',
    title: 'Privacy',
    icon: 'bi-shield-lock',
    subtitle:
      'What this project keeps about you: the revision in force, every one before it, and wiping this browser.',
  },
  {
    page: 'hilos_license',
    path: '/license',
    title: 'License',
    icon: 'bi-journal-code',
    subtitle: 'The license of this project, and of everything it is built on.',
  },
] as const

// The SDK shell renders the framework's public footer links. Each click opens a
// framework-owned static page over the live socket, and its shared section head
// stays in the current document.
test('footer links navigate to framework static pages', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await gotoPage(page, '/')
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
      await expectPageReady(page)

      const head = page.getByTestId('hilos-page-head')
      await expect(head).toHaveCount(1)
      await expect(head).toBeVisible()
      await expect(page.getByTestId('hilos-page-head-block')).toBeVisible()
      await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)

      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveText(publicPage.title)
      await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
      await expect(
        head.getByText(publicPage.subtitle, { exact: true }),
      ).toBeVisible()

      const icon = head.locator('[aria-hidden="true"]')
      await expect(icon).toHaveCount(1)
      await expect(icon).toHaveAttribute('aria-hidden', 'true')
      await expect(icon.locator('i')).toHaveClass(
        new RegExp(`\\b${publicPage.icon}\\b`),
      )

      await expect(
        page.getByRole('navigation', { name: 'Breadcrumb' }),
      ).toHaveCount(0)
      await expect(
        page.getByRole('navigation', { name: 'Page sections' }),
      ).toHaveCount(0)
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
