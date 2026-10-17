import { expect, test } from '@playwright/test'

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

  await expectThemeFirstFrame(expect, page, '/', 'dark')
  await expectThemeFirstFrame(expect, page, '/about', 'dark')
  await gotoPage(page, '/', PAGE_READY)
  await expectThemeMode(expect, page, 'dark')
})

for (const palette of ['light', 'dark'] as const) {
  test(`fresh System ${palette} paints both entrypoints without a browser pick or cache`, async ({
    browser,
  }) => {
    const { baseURL, ignoreHTTPSErrors, viewport } = test.info().project.use
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
      viewport,
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
