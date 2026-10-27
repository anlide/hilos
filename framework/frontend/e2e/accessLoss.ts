import type { Expect, Page } from '@playwright/test'

/** Check the single visible notice, or its removal once access loss is cleared. */
export async function expectAccessLossNotice(
  expect: Expect,
  page: Page,
  reason: 'session_ended' | 'account_blocked' | null,
): Promise<void> {
  const notice = page.getByTestId('access-loss-notice')
  if (reason === null) {
    await expect(notice).toHaveCount(0)
    return
  }

  await expect(notice).toHaveCount(1)
  await expect(notice).toBeVisible()
  await expect(notice).toHaveText(
    reason === 'session_ended'
      ? 'Your session has ended. Any unsaved changes were lost.'
      : 'Any unsaved changes were lost.',
  )
  if (reason === 'account_blocked') {
    await expect(
      page.getByTestId('account-blocked').getByTestId('access-loss-notice'),
    ).toBeVisible()
  }
}
