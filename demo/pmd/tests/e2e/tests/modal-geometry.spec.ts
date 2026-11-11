import type { Locator, Page } from '@playwright/test'
import { test, expect } from '../test.js'

import {
  fitsInScrollport,
  fitsWithoutSidewaysScroll,
  openTableFixture,
  scrollWithGesture,
  watchTop,
  type ScrollGestureResult,
  type ScrollGestureSource,
} from '../../../../../framework/frontend/e2e/index.js'

// The modal's own geometry stand, checked at the three widths of the norm: the
// only proof here is that nothing is cut off and nothing moves. A long body
// scrolls inside the dialog with its head and foot on screen, and a line with
// no place to break wraps instead of scrolling anything sideways. A wheel or a
// finger moves only the open dialog's body, never the page behind it, until
// the last layer closes. Where the dialog stands among other layers, how it
// looks and its exact positions are not checked.
const SCREENS = [
  { width: 1440, height: 900 },
  { width: 768, height: 1024 },
  { width: 375, height: 667 },
] as const

/** The two ways a person scrolls: a mouse wheel and one finger. */
const SOURCES: readonly ScrollGestureSource[] = ['mouse', 'touch']

/** How far one gesture asks the content to scroll, in CSS pixels. */
const GESTURE_PX = 120

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

/**
 * Refuse a gesture that found no scroll container around its target, or did
 * not move it down.
 *
 * @param gesture The gesture made over a dialog's long body.
 */
function expectContentScrolledDown(gesture: ScrollGestureResult): void {
  if (gesture.contentBefore === null || gesture.contentAfter === null) {
    throw new Error('the gesture found no scroll container around its target')
  }
  expect(gesture.contentAfter.y).toBeGreaterThan(gesture.contentBefore.y)
}

/**
 * One pass of a person over the stand with one device: scroll the page, open
 * the modal and a second one over it, scroll at their heads, edges and bodies,
 * close both, and scroll the page again.
 *
 * @param page The browser page.
 * @param source The device every gesture of the pass is made with.
 */
async function scrollThroughModalLayers(
  page: Page,
  source: ScrollGestureSource,
): Promise<void> {
  await openTableFixture(
    expect,
    page,
    '/__modal_geometry/index.html',
    'modal-geometry-fixture',
  )
  const fixture = page.getByTestId('modal-geometry-fixture')
  const firstPageScroll = await scrollWithGesture(page, fixture, {
    source,
    deltaY: GESTURE_PX,
  })
  expect(firstPageScroll.documentAfter.y).toBeGreaterThan(
    firstPageScroll.documentBefore.y,
  )
  const resting = firstPageScroll.documentAfter
  const headingTop = await watchTop(page.getByRole('heading', { level: 1 }))

  /** Make a gesture the open layers must keep from the page behind them. */
  const keepsPageStill = async (
    target: Locator,
    deltaY: number,
    at: 'center' | 'top-left',
  ): Promise<ScrollGestureResult> => {
    const gesture = await scrollWithGesture(page, target, {
      source,
      deltaY,
      at,
    })
    expect(gesture.documentBefore).toEqual(resting)
    expect(gesture.documentAfter).toEqual(resting)
    await headingTop.unchanged()

    return gesture
  }

  await page.getByTestId('modal-geometry-open').click()
  const lower = page.getByRole('dialog', { name: 'Why the run failed' })
  await expect(lower).toBeVisible()
  const lowerHeading = lower.getByRole('heading', {
    name: 'Why the run failed',
  })
  await keepsPageStill(lowerHeading, -GESTURE_PX, 'center')
  await keepsPageStill(lower, -GESTURE_PX, 'top-left')
  const output = lower.getByTestId('modal-geometry-output')
  expectContentScrolledDown(await keepsPageStill(output, GESTURE_PX, 'center'))
  // A programmatic scroll only brings the body to its edge; the proof is the
  // native gesture past that edge.
  await lower.getByTestId('modal-geometry-body-start').scrollIntoViewIfNeeded()
  await keepsPageStill(output, -GESTURE_PX, 'center')
  await lower.getByTestId('modal-geometry-body-end').scrollIntoViewIfNeeded()
  await keepsPageStill(output, GESTURE_PX, 'center')

  await lower.getByTestId('modal-geometry-nested-open').click()
  const nested = page.getByRole('dialog', { name: 'Nested details' })
  await expect(nested).toBeVisible()
  await keepsPageStill(
    nested.getByRole('heading', { name: 'Nested details' }),
    -GESTURE_PX,
    'center',
  )
  await keepsPageStill(nested, -GESTURE_PX, 'top-left')
  expectContentScrolledDown(
    await keepsPageStill(
      nested.getByTestId('modal-geometry-nested-output'),
      GESTURE_PX,
      'center',
    ),
  )

  await nested.getByTestId('modal-geometry-nested-close').click()
  await expect(nested).toBeHidden()
  await keepsPageStill(lowerHeading, -GESTURE_PX, 'center')

  await lower.getByTestId('modal-geometry-close').click()
  await expect(lower).toBeHidden()
  expect(
    await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY })),
  ).toEqual(resting)
  await headingTop.unchanged()
  const lastPageScroll = await scrollWithGesture(page, fixture, {
    source,
    deltaY: GESTURE_PX,
  })
  expect(lastPageScroll.documentBefore).toEqual(resting)
  expect(lastPageScroll.documentAfter.y).toBeGreaterThan(resting.y)
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

    test.describe('under a finger', () => {
      test.use({ hasTouch: true })

      test('wheel and touch scroll only the open modal until its last layer closes', async ({
        page,
      }) => {
        for (const source of SOURCES) {
          await test.step(`by ${source}`, () =>
            scrollThroughModalLayers(page, source))
        }
      })
    })
  })
}
