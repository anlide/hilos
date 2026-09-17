import type { Locator, Page } from '@playwright/test'

// A declared table stands in the document twice: as a table for a wide screen and
// as a list of cards for a narrow one, both mounted at once and one of them hidden
// by Bootstrap's display utilities (docs/agents/frontend/table-subscription.md,
// "The card a row projects to"). So every `data-id` a page writes into a cell is in
// the document twice, and a strict lookup of one — a click, a read of an attribute,
// a web-first assertion — refuses to choose between the two copies.
//
// The copy a person can press is the one on screen, whatever the width, so that is
// the copy a spec aims at. A view that draws no cards holds one copy, which is also
// the one on screen, so the same lookup reads the same in every demo.
//
// What this is NOT for: saying a control is gone. The copy on screen can be absent
// while the hidden one still stands, so `toHaveCount(0)` is asserted on the plain
// `getByTestId`, where it means both copies.

/**
 * The copies of an element that are on screen, for an element a declared table
 * draws in both its branches.
 *
 * @param scope The page, or a locator the element stands inside.
 * @param testId The element's `data-id`, or a pattern of them.
 * @returns A locator matching only the copies currently shown.
 */
export function shownByTestId(
  scope: Page | Locator,
  testId: string | RegExp,
): Locator {
  return scope.getByTestId(testId).filter({ visible: true })
}
