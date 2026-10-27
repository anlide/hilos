import type { Expect, Locator } from '@playwright/test'

/**
 * Clear an input, then type key by key so the view observes each input event.
 *
 * @param field The input locator.
 * @param value The value to type.
 */
export async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
}

/**
 * Prepare an actionable submit button, then click it once. A render can replace
 * the DOM during scrolling or focus, so preparation retries independently of the
 * click. The caller waits for its own form's success or refusal afterwards.
 *
 * @param expect The calling spec's Playwright expect.
 * @param button The submit-button locator.
 */
export async function clickSubmit(
  expect: Expect,
  button: Locator,
): Promise<void> {
  await expect(async () => {
    await button.scrollIntoViewIfNeeded()
    await expect(button).toBeVisible()
    await expect(button).toBeEnabled()
    await button.focus()
  }).toPass()
  await button.click()
}
