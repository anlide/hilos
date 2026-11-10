import type { Expect, Page } from '@playwright/test'

const noticeText = {
  session_ended: 'Your session has ended. Any unsaved changes were lost.',
  account_blocked: 'Any unsaved changes were lost.',
  ended_by_administrator:
    'Your session was ended by an administrator. Any unsaved changes were lost.',
  ended_from_another_browser:
    'Your session was ended from another browser. Any unsaved changes were lost.',
  password_changed:
    'Your session has ended because the password was changed. Any unsaved changes were lost.',
  password_recovered:
    'Your session has ended because the password was reset. Any unsaved changes were lost.',
} as const

/** Check the single visible notice, or its removal once access loss is cleared. */
export async function expectAccessLossNotice(
  expect: Expect,
  page: Page,
  reason: keyof typeof noticeText | null,
): Promise<void> {
  const notice = page.getByTestId('access-loss-notice')
  if (reason === null) {
    await expect(notice).toHaveCount(0)
    return
  }

  await expect(notice).toHaveCount(1)
  await expect(notice).toBeVisible()
  await expect(notice).toHaveText(noticeText[reason])
  if (reason === 'account_blocked') {
    await expect(
      page.getByTestId('account-blocked').getByTestId('access-loss-notice'),
    ).toBeVisible()
  }
}
