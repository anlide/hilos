import { test, expect } from '@playwright/test'

// Footer + framework static pages e2e: the SDK shell renders the framework's
// footer links (HILOS_FOOTER_LINKS), and clicking one navigates — over the live
// socket, with no document reload — to a framework-declared static page. The
// page_subscribe to the framework page (e.g. hilos_about) is answered with no
// payload, and the project's own content component renders in its place.
test('footer links navigate to framework static pages', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const loadsAfterColdLoad = fullLoads

  // All four framework footer links are present.
  await expect(page.getByTestId('footer-link-hilos_about')).toBeVisible()
  await expect(page.getByTestId('footer-link-hilos_terms')).toBeVisible()
  await expect(page.getByTestId('footer-link-hilos_privacy')).toBeVisible()
  await expect(page.getByTestId('footer-link-hilos_license')).toBeVisible()

  // About -> a framework static page rendered with the project's content,
  // reached over the live socket with no reload.
  await page.getByTestId('footer-link-hilos_about').click()
  await expect(page.getByTestId('static-page-title')).toHaveText('About')
  expect(new URL(page.url()).pathname).toBe('/about')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)

  // Terms too, to prove the whole set is wired, not just the first link.
  await page.getByTestId('footer-link-hilos_terms').click()
  await expect(page.getByTestId('static-page-title')).toHaveText(
    'Terms of Service',
  )
  expect(new URL(page.url()).pathname).toBe('/terms')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// The chat message list owns its own scroll: the shell is a fixed-height
// viewport (vh-100 + overflow-hidden) and the event stream is an overflow-auto
// region (the min-h-0 flex chain), so messages scroll inside the card rather
// than growing the document.
test('the event stream is its own scroll region', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const overflowY = await page
    .getByTestId('events-scroll')
    .evaluate((el) => getComputedStyle(el).overflowY)
  expect(overflowY).toBe('auto')

  // The document itself never scrolls: the shell is locked to the viewport, so
  // only inner regions (here the event stream) scroll.
  const documentScrolls = await page.evaluate(
    () =>
      document.documentElement.scrollHeight >
      document.documentElement.clientHeight + 1,
  )
  expect(documentScrolls).toBe(false)
})
