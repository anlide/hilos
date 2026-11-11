import type { Locator, Page } from '@playwright/test'
import { test, expect } from '../test.js'

import {
  fitsInScrollport,
  fitsWithoutSidewaysScroll,
  openTableFixture,
  watchTop,
} from '../../../../../framework/frontend/e2e/index.js'

// The modal's own geometry stand, checked at the three widths of the norm: the
// only proof here is that nothing is cut off and nothing moves. A long body
// scrolls inside the dialog with its head and foot on screen, and a line with
// no place to break wraps instead of scrolling anything sideways. Where the
// dialog stands among other layers, how it looks and its exact positions are
// not checked.
const SCREENS = [
  { width: 1440, height: 900 },
  { width: 768, height: 1024 },
  { width: 375, height: 667 },
] as const

/**
 * Open the stand and the modal on it.
 *
 * @param page The browser page.
 * @returns The open dialog.
 */
async function openModal(page: Page): Promise<Locator> {
  await openTableFixture(
    expect,
    page,
    '/__modal_geometry/index.html',
    'modal-geometry-fixture',
  )
  await page.getByTestId('modal-geometry-open').click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  return dialog
}

for (const screen of SCREENS) {
  test.describe(`modal geometry at ${screen.width}`, () => {
    test.use({ viewport: screen, colorScheme: 'light' })

    test('a long body scrolls inside the dialog while its head and foot stay on screen', async ({
      page,
    }) => {
      const dialog = await openModal(page)
      expect(
        await page.evaluate(
          () => document.documentElement.scrollHeight > window.innerHeight,
        ),
      ).toBe(true)
      const heading = dialog.getByRole('heading', {
        name: 'Why the run failed',
      })
      const closeButton = dialog.getByTestId('modal-geometry-close')
      for (const control of [
        heading,
        dialog.getByTestId('modal-close'),
        dialog.getByTestId('modal-copy'),
        closeButton,
      ]) {
        await fitsInScrollport(control)
      }
      const end = dialog.getByTestId('modal-geometry-body-end')
      await expect(end).not.toBeInViewport()
      const headingTop = await watchTop(heading)
      const closeButtonTop = await watchTop(closeButton)
      await end.scrollIntoViewIfNeeded()
      await fitsInScrollport(end)
      await headingTop.unchanged()
      await closeButtonTop.unchanged()
    })

    test('an unbroken line wraps instead of scrolling the dialog sideways', async ({
      page,
    }) => {
      const dialog = await openModal(page)
      await fitsWithoutSidewaysScroll(
        dialog.getByTestId('modal-geometry-output'),
      )
    })
  })
}
