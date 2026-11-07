import type { Expect, Locator } from '@playwright/test'

/**
 * Compare ordered breadcrumb labels without their hidden decorations.
 *
 * @param expect The spec's assertion function.
 * @param items The breadcrumb items selected by the caller.
 * @param labels The exact ordered labels, including their cardinality.
 * @param normalizeLabel Optional additional normalization owned by the caller.
 */
export async function expectBreadcrumbLabels(
  expect: Expect,
  items: Locator,
  labels: readonly string[],
  normalizeLabel?: (label: string) => string,
): Promise<void> {
  await expect
    .poll(async () => {
      const actual = await items.evaluateAll((elements) =>
        elements.map((element) => {
          const words = element.cloneNode(true) as Element
          for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
            hidden.remove()
          return words.textContent?.trim()
        }),
      )
      return actual.map((label) =>
        normalizeLabel && label !== undefined ? normalizeLabel(label) : label,
      )
    })
    .toEqual(labels)
}
