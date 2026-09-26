// A person's own account deletion (HIL-302): the danger zone at the bottom of the
// profile, a window that asks for the operation's confirmation only when the
// account has something stronger than its address, a code to that address, the
// warning with the date in every open tab, and "Keep my account" in one press.
// The code is read from the stand mailbox, never a backdoor. The erasure itself
// is not driven here: its moment is a month away, and forcing it is HIL-316.
import { expect, test } from '@playwright/test'

import { waitForMailCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  registerEmailOnly,
  signUp,
  typeInto,
} from '../helpers/session'

/** The subject AccountDeletionMailTemplate sends the code under. */
const SUBJECT = 'Confirm deleting your account'

test('starts a deletion with a code and calls it off in one press, in every tab', async ({
  context,
  page,
}) => {
  const email = await registerEmailOnly(page)
  const other = await context.newPage()
  await gotoPage(other, '/profile')
  await expect(other.getByTestId('account-deletion-open')).toBeVisible()

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-modal')).toContainText(
    '30 days',
  )
  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  const code = await waitForMailCode(email, SUBJECT)

  await typeInto(page.getByTestId('account-deletion-code'), '000000')
  await clickSubmit(page.getByTestId('account-deletion-start'))
  await expect(page.getByTestId('account-deletion-error')).toHaveText(
    'Invalid or expired code',
  )

  await typeInto(page.getByTestId('account-deletion-code'), code)
  await clickSubmit(page.getByTestId('account-deletion-start'))
  await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
  await clickSubmit(page.getByTestId('account-deletion-close'))
  await expect(page.getByTestId('account-deletion-scheduled')).toContainText(
    'days left',
  )
  await expect(other.getByTestId('account-deletion-scheduled')).toBeVisible()

  await clickSubmit(page.getByTestId('account-deletion-manage'))
  await clickSubmit(page.getByTestId('account-deletion-keep'))
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
  await expect(other.getByTestId('account-deletion-open')).toBeVisible()
})

test('asks the password of a password account before anything else', async ({
  page,
}) => {
  const user = await signUp(page)
  await gotoPage(page, '/profile')

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('account-deletion-confirm'))
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  await waitForMailCode(user.email, SUBJECT)

  await expect(page.getByTestId('account-deletion-code')).toBeFocused()
  await clickSubmit(page.getByTestId('account-deletion-cancel'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
})
