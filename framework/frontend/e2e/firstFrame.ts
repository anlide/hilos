import type { Page } from '@playwright/test'

/**
 * Read the theme and painted body before any application bundle can run.
 *
 * @param page The page whose first frame is being checked.
 * @param path The address to open.
 */
export async function openFirstFrame(
  page: Page,
  path: string,
): Promise<{ theme: string | null; background: string }> {
  await page.route('**/*.js', (route) => route.abort())
  try {
    await page.goto(path)
    return await page.evaluate(() => ({
      theme: document.documentElement.getAttribute('data-bs-theme'),
      background: getComputedStyle(document.body).backgroundColor,
    }))
  } finally {
    await page.unroute('**/*.js')
  }
}
