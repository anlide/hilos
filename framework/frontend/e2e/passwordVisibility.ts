import type { Expect, Page } from '@playwright/test'

/**
 * Check that a password input paired with a visibility toggle button can be
 * toggled via keyboard (Enter to reveal, Space to hide) while preserving focus
 * and maintaining input value and proper accessibility attributes.
 *
 * @param expect Playwright expect assertion passed from the caller spec.
 * @param page The page containing the password field and toggle button.
 * @param inputId The data-id of the password input element.
 * @param toggleId The data-id of the toggle visibility button.
 */
export async function expectPasswordVisibility(
  expect: Expect,
  page: Page,
  inputId: string,
  toggleId: string,
): Promise<void> {
  const input = page.getByTestId(inputId)
  const toggle = page.getByTestId(toggleId)

  await expect(input).toBeVisible()
  await expect(input).toHaveAttribute('type', 'password')
  const initialValue = await input.inputValue()
  await expect(toggle).toBeVisible()
  await expect(toggle).toHaveAttribute('type', 'button')
  await expect(toggle).toHaveAttribute('aria-label', 'Show password')
  await expect(toggle).toHaveAttribute('title', 'Show password')
  await expect(toggle.locator('i.bi-eye')).toBeVisible()

  await toggle.focus()
  await expect(toggle).toBeFocused()

  await page.keyboard.press('Enter')
  await expect(input).toHaveAttribute('type', 'text')
  await expect(input).toHaveValue(initialValue)
  await expect(toggle).toHaveAttribute('aria-label', 'Hide password')
  await expect(toggle).toHaveAttribute('title', 'Hide password')
  await expect(toggle.locator('i.bi-eye-slash')).toBeVisible()
  await expect(toggle).toBeFocused()

  await page.keyboard.press('Space')
  await expect(input).toHaveAttribute('type', 'password')
  await expect(input).toHaveValue(initialValue)
  await expect(toggle).toHaveAttribute('aria-label', 'Show password')
  await expect(toggle).toHaveAttribute('title', 'Show password')
  await expect(toggle.locator('i.bi-eye')).toBeVisible()
  await expect(toggle).toBeFocused()
}
