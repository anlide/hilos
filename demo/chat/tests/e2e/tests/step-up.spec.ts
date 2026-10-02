// Fresh confirmation before a protected profile operation (HIL-495): the
// application chooses the account's strongest available proof, the modal keeps
// that proof as its first step, and an administrator may narrow the declared
// operation list. Mail codes are read from the stand mailbox, never a backdoor.
import { expect, test, type Page } from '@playwright/test'

import {
  addVirtualAuthenticator,
  dismissToasts,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { waitForMailCode } from '../helpers/mail'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage, PAGE_READY } from '../helpers/page'
import { connectFirstApp } from '../helpers/secondFactor'
import {
  PASSWORD,
  clickSubmit,
  registerEmailOnly,
  signUp,
  typeInto,
} from '../helpers/session'
import { nextTotpCode, totpStep } from '../helpers/totp'

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

test('confirms adding a way in once and lets the next add through without a step', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  await signUp(page)
  await gotoPage(page, '/profile/sign-in')

  // Adding a way in is a protected operation (HIL-1138): the dialog opens on
  // the confirmation step, and the device key is offered only past it.
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
  await expect(page.getByTestId('profile-passkey-add')).toHaveCount(0)
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-sign-in-add-step-up-confirm'))
  await clickSubmit(page.getByTestId('profile-passkey-add'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()

  // The confirmation lives on the operation for its lifetime: the next add in
  // this browser opens straight at the chooser.
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
  await expect(page.getByTestId('profile-sign-in-choose-phone')).toBeVisible()
  await expect(page.getByTestId('step-up')).toHaveCount(0)
})

test('confirms the first authenticator app with the password and lets a second prove itself', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile/security')

  // The first app is the operation's own step (HIL-1138): the password first,
  // then the name.
  await clickSubmit(page.getByTestId('profile-2fa-add'))
  await expect(page.getByTestId('profile-2fa-enroll-label')).toHaveCount(0)
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await typeInto(page.getByTestId('profile-2fa-enroll-label'), 'Work phone')
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  const secret = (
    await page.getByTestId('profile-2fa-enroll-secret').textContent()
  )?.trim()
  const { code } = await nextTotpCode(secret ?? '', totpStep() - 1)
  await typeInto(page.getByTestId('profile-2fa-enroll-code'), code)
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await page.getByTestId('backup-codes-saved').check()
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-enroll-more')).toBeVisible()

  // "Connect another": the app just connected is the proof the operation's own
  // step asks for, so no confirmation stands before the name - and after the
  // name comes that app's code, as before.
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-enroll-label')).toBeVisible()
  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await typeInto(page.getByTestId('profile-2fa-enroll-label'), 'Tablet')
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-proof')).toBeVisible()
})

test('opens the add-a-way-in dialog at the chooser once an administrator switched its operation off', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/security/2fa/step-up')
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(1)
  const operation = shownByTestId(
    page,
    'hilos-step-up-switch-add_sign_in_method',
  )
  await expect(operation).toBeChecked()
  await operation.click()
  await expect(operation).not.toBeChecked()

  await gotoPage(page, '/profile/sign-in')
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
  await expect(page.getByTestId('profile-sign-in-choose-phone')).toBeVisible()
  await expect(page.getByTestId('step-up')).toHaveCount(0)
})

test('skips a protected operation disabled by an administrator', async ({
  page,
}) => {
  await signUpAdmin(page)
  // The list lives on its own page under two-factor (HIL-1204): the way in is
  // the child card the two-factor page draws from the catalog.
  await gotoPage(page, '/hilos/security/2fa')
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(0)
  await page.getByTestId('hilos-admin-child-hilos_security_step_up').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Operations that ask for confirmation',
  )
  expect(new URL(page.url()).pathname).toBe('/hilos/security/2fa/step-up')
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

// An operation declared off asks once an administrator switches it on
// (HIL-1275). Removing rights is switched here rather than blocking: no other spec
// of this demo opens that window, so the switch cannot reach a test running beside
// it. It is switched back off whatever happens.
test('asks before removing rights once the administrator switches it on', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  const revokeSwitch = shownByTestId(page, 'hilos-step-up-switch-revoke_admin')
  let switched = false
  try {
    await signUpAdmin(page)
    const person = await signUp(personPage)
    await gotoPage(page, '/hilos/security/2fa/step-up')
    await expect(revokeSwitch).not.toBeChecked()
    await revokeSwitch.click()
    switched = true
    await expect(revokeSwitch).toBeChecked()

    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-admin-open'))
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-step-up-confirm'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()

    await dismissToasts(page)
    await clickSubmit(page.getByTestId('hilos-user-admin-open'))
    await expect(page.getByTestId('modal')).toContainText("Confirm it's you")
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-step-up-confirm'))
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Admin rights removed',
    )
  } finally {
    if (switched) {
      await gotoPage(page, '/hilos/security/2fa/step-up')
      await revokeSwitch.click()
      await expect(revokeSwitch).not.toBeChecked()
    }
    await personContext.close()
  }
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens step-up operations and finds the switches replaced with hidden marks', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/security/2fa/step-up', PAGE_READY)
    await expect(
      page.getByTestId('hilos-step-up-switch-change_name'),
    ).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-table-row-change_name')
        .getByTestId('hilos-hidden')
        .first(),
    ).toBeVisible()
  })
})
