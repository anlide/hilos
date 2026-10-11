import { expect, test } from '@playwright/test'
import { openFirstFrame } from '../../../../../framework/frontend/e2e/index.js'

const DARK_FRAME = { theme: 'dark', background: 'rgb(33, 37, 41)' }
const LIGHT_FRAME = { theme: 'light', background: 'rgb(255, 255, 255)' }

test('remembered dark pick paints the shell and prerendered page', async ({
  page,
}) => {
  await openFirstFrame(page, '/')
  await page.evaluate(() => localStorage.setItem('hilos.theme.pick', 'dark'))

  expect(await openFirstFrame(page, '/')).toEqual(DARK_FRAME)
  expect(await openFirstFrame(page, '/about')).toEqual(DARK_FRAME)
})

test('system preference paints the first frame in a new browser', async ({
  browser,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use

  for (const [colorScheme, expected] of [
    ['dark', DARK_FRAME],
    ['light', LIGHT_FRAME],
  ] as const) {
    const context = await browser.newContext({
      colorScheme,
      baseURL,
      ignoreHTTPSErrors,
    })
    try {
      const page = await context.newPage()
      expect(await openFirstFrame(page, '/')).toEqual(expected)
    } finally {
      await context.close()
    }
  }
})
