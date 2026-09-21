// Look-alikes that do not measure a box: names in prose and in data, a member
// read without a call, and the shared toolbox doing the measurement itself.
import type { Locator } from '@playwright/test'

import { watchTop } from '../../e2e/index.js'

/** A method name in a string is not a call. */
export const TEXT = 'element.boundingBox() and row.getBoundingClientRect()'

/** Fields with the same names are not calls either. */
export const DATA = { boundingBox: 1, getBoundingClientRect: 2 }

/** Reading a member does not call it. */
export function methodOf(element: Locator): unknown {
  // element.boundingBox() would be a forbidden call, if it were code.
  return element.boundingBox
}

/** The bookmark owns both the number and the comparison. */
export async function unchanged(element: Locator): Promise<void> {
  const top = await watchTop(element)
  await top.unchanged()
}

/** Scroll measurements are outside the box rule. */
export async function scrollRoom(element: Locator): Promise<number> {
  return element.evaluate((root) => root.scrollHeight - root.clientHeight)
}
