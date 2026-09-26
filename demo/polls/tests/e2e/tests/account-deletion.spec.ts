// A person's own account deletion (HIL-302) at the bottom of /profile/security:
// the password first, since a password account has something stronger than its
// address, then a code to the address, the warning with the date, and "Keep my
// account" in one press. The code is read from the stand mailbox, never a
// backdoor. The erasure itself is not driven here: forcing it is HIL-316.
import { expect, test } from '@playwright/test'

import { waitForMailCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  nameFromEmail,
  openSignIn,
  register,
  typeInto,
  uniqueEmail,
} from '../helpers/session'

/** The subject AccountDeletionMailTemplate sends the code under. */
const SUBJECT = 'Confirm deleting your account'

test('starts a deletion after the password and a code, then keeps the account', async ({
  page,
}) => {
  const email = uniqueEmail()
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await gotoPage(page, '/profile/security')

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('account-deletion-confirm'))
  await expect(page.getByTestId('account-deletion-modal')).toContainText(
    '30 days',
  )
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  await typeInto(
    page.getByTestId('account-deletion-code'),
    await waitForMailCode(email, SUBJECT),
  )
  await clickSubmit(page.getByTestId('account-deletion-start'))

  await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
  await clickSubmit(page.getByTestId('account-deletion-close'))
  await expect(page.getByTestId('account-deletion-scheduled')).toContainText(
    'days left',
  )

  await clickSubmit(page.getByTestId('account-deletion-manage'))
  await clickSubmit(page.getByTestId('account-deletion-keep'))
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
})
