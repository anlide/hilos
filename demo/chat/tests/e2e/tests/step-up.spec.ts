// Fresh confirmation before a protected profile operation (HIL-495): the
// application chooses the account's strongest available proof, the modal keeps
// that proof as its first step, and an administrator may narrow the declared
// operation list. Mail codes are read from the stand mailbox, never a backdoor.
import { expect, test, type Page } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant'
import { waitForMailCode } from '../helpers/mail'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage } from '../helpers/page'
import { connectFirstApp } from '../helpers/secondFactor'
import {
  PASSWORD,
  clickSubmit,
  registerEmailOnly,
  signUp,
  typeInto,
} from '../helpers/session'
import { nextTotpCode } from '../helpers/totp'

/** Complete the rename after its confirmation step has passed or been skipped. */
async function rename(page: Page): Promise<string> {
  const key = modelKey()
  const name = `Step up ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await typeInto(page.getByTestId('profile-name-input'), name)
  await clickSubmit(page.getByTestId('profile-rename-save'))
  await expect(page.getByTestId('profile-name')).toHaveText(name)

  return name
}

test('confirms a name change with the account password', async ({ page }) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await clickSubmit(page.getByTestId('profile-edit'))

  await typeInto(page.getByTestId('step-up-password'), 'wrong password')
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
  await expect(page.getByTestId('step-up-error')).toHaveText(
    'Incorrect password',
  )

  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
  await expect(page.getByTestId('profile-name-input')).toBeFocused()
  await rename(page)
})

test('confirms a name change with a code sent to the verified email', async ({
  page,
}) => {
  const email = await registerEmailOnly(page)
  await clickSubmit(page.getByTestId('profile-edit'))

  await typeInto(
    page.getByTestId('step-up-code'),
    await waitForMailCode(email, 'Confirm it is you'),
  )
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
  await rename(page)
})

test('confirms a name change with the connected authenticator app', async ({
  page,
}) => {
  await signUp(page)
  const app = await connectFirstApp(page)
  await gotoPage(page, '/profile')
  await clickSubmit(page.getByTestId('profile-edit'))

  const { code } = await nextTotpCode(app.secret, app.spent)
  await typeInto(page.getByTestId('step-up-code'), code)
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
  await rename(page)
})

test('does not ask twice when email change starts with its own address code', async ({
  page,
}) => {
  await registerEmailOnly(page)
  await clickSubmit(page.getByTestId('profile-email-change'))

  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await expect(page.getByTestId('profile-email-send-current')).toBeVisible()
})

test('skips a protected operation disabled by an administrator', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/security/2fa')
  await expect(
    page.getByRole('table', {
      name: 'Operations that ask for confirmation',
    }),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(1)
  const operation = shownByTestId(page, 'hilos-step-up-switch-change_name')
  await expect(operation).toBeChecked()
  await operation.click()
  await expect(operation).not.toBeChecked()

  await gotoPage(page, '/profile')
  await clickSubmit(page.getByTestId('profile-edit'))
  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await expect(page.getByTestId('profile-name-input')).toBeFocused()
  await rename(page)
})
