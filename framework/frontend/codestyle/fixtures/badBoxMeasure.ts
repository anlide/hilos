// Deliberately broken measurements: direct, inside evaluate, and hidden in a
// helper. Two calls on one line still owe two reports. Only the fixture test
// reads this file; it sits outside the demo roots the guard scans.
import type { Locator } from '@playwright/test'

/** A spec reaching for the box itself. */
export async function direct(element: Locator): Promise<void> {
  await element.boundingBox()
}

/** Both DOM boxes are read inside the browser callback. */
export async function inBrowser(element: Locator): Promise<void> {
  await element.evaluate((root) => {
    const row = root.querySelector('tr')!
    return row.getBoundingClientRect().top - root.getBoundingClientRect().top
  })
}

/** A local helper is no escape from the rule. */
export async function boxOf(element: Locator): Promise<unknown> {
  return element.boundingBox()
}

/** Optional calls still measure a box when the element exists. */
export async function optional(element?: Locator): Promise<unknown> {
  return element?.boundingBox()
}
