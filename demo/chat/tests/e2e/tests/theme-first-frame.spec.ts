import { expect, test, type Route } from '@playwright/test'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
  emulateSystemTheme,
  expectThemeFirstFrame,
  expectThemeMode,
} from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('remembered Dark paints the shell and prerendered page before application JS', async ({
  page,
}) => {
  await emulateSystemTheme(page, 'light')
  await expectThemeFirstFrame(expect, page, '/', 'light')
  await page.evaluate(
    (key) => localStorage.setItem(key, 'dark'),
    THEME_PICK_STORAGE_KEY,
  )

  // A probe owns only its route. A caller's handler for the same URL pattern
  // must still receive bundles when normal navigation resumes afterwards.
  let scripts = 0
  let sockets = 0
  const observeScript = async (route: Route) => {
    scripts += 1
    await route.fallback()
  }
  const observeSocket = () => {
    sockets += 1
  }
  await page.route('**/*.js', observeScript)
  page.on('websocket', observeSocket)
  try {
    await expectThemeFirstFrame(expect, page, '/', 'dark')
    await expectThemeFirstFrame(expect, page, '/about', 'dark')
    expect(scripts).toBe(0)
    expect(sockets).toBe(0)
    await gotoPage(page, '/', PAGE_READY)
    await expectThemeMode(expect, page, 'dark')
    expect(scripts).toBeGreaterThan(0)
    expect(sockets).toBeGreaterThan(0)
  } finally {
    page.off('websocket', observeSocket)
    await page.unroute('**/*.js', observeScript)
  }
})

for (const palette of ['light', 'dark'] as const) {
  test(`fresh System ${palette} paints both entrypoints without a browser pick or cache`, async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: palette,
    })
    try {
      const page = await context.newPage()
      let sockets = 0
      page.on('websocket', () => {
        sockets += 1
      })
      for (const path of ['/', '/about']) {
        await expectThemeFirstFrame(expect, page, path, palette)
        expect(
          await page.evaluate(
            (keys) => keys.map((key) => localStorage.getItem(key)),
            [THEME_PICK_STORAGE_KEY, THEME_SETTINGS_STORAGE_KEY],
          ),
        ).toEqual([null, null])
      }
      expect(sockets).toBe(0)
      await gotoPage(page, '/', PAGE_READY)
      await expectThemeMode(expect, page, palette)
    } finally {
      await context.close()
    }
  })
}
