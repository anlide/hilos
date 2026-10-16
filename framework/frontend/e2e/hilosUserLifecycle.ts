import type { Expect, Page } from '@playwright/test'

/**
 * Asserts that the block confirmation modal is closed and the card shows
 * the expected block button label.
 *
 * @param expect The test runner's assertion function.
 * @param page The page viewing the user card.
 * @param blocked Whether the user is expected to be blocked.
 */
export async function expectHilosUserBlockSettled(
  expect: Expect,
  page: Page,
  blocked: boolean,
): Promise<void> {
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('hilos-user-block-open')).toHaveText(
    blocked ? 'Lift the block' : 'Block',
  )
}
