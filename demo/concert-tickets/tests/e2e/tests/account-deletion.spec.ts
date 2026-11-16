// A person's own account deletion (HIL-302) at the bottom of /profile:
// the password first, since a password account has something stronger than its
// address, then a code to the address, the warning with the date, and "Keep my
// account" in one press. The code is read from the stand mailbox, never a
// backdoor. Live erasure and loss of gated access in both tabs are covered by
// demo/solar-system-model/tests/e2e/tests/account-deletion.spec.ts.
import type { Page } from '@playwright/test'
import { expect, test } from '../test.js'
import {
  PASSWORD,
  nameFromEmail,
  createTestUser,
  uniqueEmail,
  clickSubmit,
  typeInto,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'

import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

/** The subject AccountDeletionMailTemplate sends the code under. */
const SUBJECT = 'Confirm deleting your account'

/**
 * Schedule deletion through the password and address-code window.
 *
 * @param page Signed-in browser page.
 * @param email Address receiving the deletion code.
 */
async function scheduleDeletion(page: Page, email: string): Promise<void> {
  await gotoPage(expect, page, '/profile')
  await clickSubmit(expect, page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('account-deletion-confirm'))
  await expect(page.getByTestId('account-deletion-modal')).toContainText(
    '30 days',
  )
  await clickSubmit(expect, page.getByTestId('account-deletion-continue'))
  await typeInto(
    page.getByTestId('account-deletion-code'),
    await waitForMailCode(email, SUBJECT),
  )
  await clickSubmit(expect, page.getByTestId('account-deletion-start'))

  await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('account-deletion-close'))
  await expect(page.getByTestId('account-deletion-scheduled')).toContainText(
    'days left',
  )
}

test('starts a deletion after the password and a code, then keeps the account', async ({
  page,
}) => {
  const email = uniqueEmail()
  await createTestUser(expect, page, { email })
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await scheduleDeletion(page, email)

  await clickSubmit(expect, page.getByTestId('account-deletion-manage'))
  await clickSubmit(expect, page.getByTestId('account-deletion-keep'))
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
})
