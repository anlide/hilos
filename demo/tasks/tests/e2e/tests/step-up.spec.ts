// Fresh confirmation before a protected profile operation (HIL-495): the
// application chooses the account's strongest available proof, the modal keeps
// that proof as its first step, and an administrator may narrow the declared
// operation list. Mail codes are read from the stand mailbox, never a backdoor.
import { expect, test } from '@playwright/test'

import {
  PASSWORD,
  createTestUser,
  addVirtualAuthenticator,
  dismissToasts,
  shownByTestId,
  clickSubmit,
  typeInto,
  connectFirstApp,
  gotoPage,
  PAGE_READY,
  createTestAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  nextTotpCode,
  totpStep,
} from '../../../../../framework/frontend/scripts/totp.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

test('confirms an email change with the account password', async ({ page }) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await clickSubmit(expect, page.getByTestId('profile-email-change'))

  await typeInto(page.getByTestId('step-up-password'), 'wrong password')
  await clickSubmit(expect, page.getByTestId('profile-email-step-up-confirm'))
  await expect(page.getByTestId('step-up-error')).toHaveText(
    'Incorrect password',
  )

  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-email-step-up-confirm'))
  await expect(page.getByTestId('profile-email-send-current')).toBeVisible()
})

test('confirms data export with a code sent to the verified email', async ({
  page,
}) => {
  const { email } = await createTestUser(expect, page, { passwordless: true })
  await gotoPage(expect, page, '/profile/data')
  await expect(page.getByTestId('profile-data-view')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('data-export-prepare'))

  await typeInto(
    page.getByTestId('step-up-code'),
    await waitForMailCode(email, 'Confirm it is you'),
  )
  await clickSubmit(expect, page.getByTestId('data-export-confirm'))
  await expect(page.getByTestId('data-export-ready')).toBeVisible()
})

test('confirms an email change with the connected authenticator app', async ({
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/security')
  const app = await connectFirstApp(page, PASSWORD)
  await gotoPage(expect, page, '/profile')
  await clickSubmit(expect, page.getByTestId('profile-email-change'))

  const { code } = await nextTotpCode(app.secret, app.spent)
  await typeInto(page.getByTestId('step-up-code'), code)
  await clickSubmit(expect, page.getByTestId('profile-email-step-up-confirm'))
  await expect(page.getByTestId('profile-email-send-current')).toBeVisible()
})

test('does not ask twice when email change starts with its own address code', async ({
  page,
}) => {
  await createTestUser(expect, page, { passwordless: true })
  await gotoPage(expect, page, '/profile')
  await clickSubmit(expect, page.getByTestId('profile-email-change'))

  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await expect(page.getByTestId('profile-email-send-current')).toBeVisible()
})

test('confirms adding a way in once and lets the next add through without a step', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/sign-in')

  // Adding a way in is a protected operation (HIL-1138): the dialog opens on
  // the confirmation step, and the device key is offered only past it.
  await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))
  await expect(page.getByTestId('profile-passkey-add')).toHaveCount(0)
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(
    expect,
    page.getByTestId('profile-sign-in-add-step-up-confirm'),
  )
  await clickSubmit(expect, page.getByTestId('profile-passkey-add'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()

  // The confirmation lives on the operation for its lifetime: the next add in
  // this browser opens straight at the chooser.
  await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))
  await expect(page.getByTestId('profile-sign-in-choose-phone')).toBeVisible()
  await expect(page.getByTestId('step-up')).toHaveCount(0)
})

test('confirms the first authenticator app with the password and lets a second prove itself', async ({
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/security')

  // The first app is the operation's own step (HIL-1138): the password first,
  // then the name.
  await clickSubmit(expect, page.getByTestId('profile-2fa-add'))
  await expect(page.getByTestId('profile-2fa-enroll-label')).toHaveCount(0)
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  await typeInto(page.getByTestId('profile-2fa-enroll-label'), 'Work phone')
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  const secret = (
    await page.getByTestId('profile-2fa-enroll-secret').textContent()
  )?.trim()
  const { code } = await nextTotpCode(secret ?? '', totpStep() - 1)
  await typeInto(page.getByTestId('profile-2fa-enroll-code'), code)
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  await page.getByTestId('backup-codes-saved').check()
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-enroll-more')).toBeVisible()

  // "Connect another": the app just connected is the proof the operation's own
  // step asks for, so no confirmation stands before the name - and after the
  // name comes that app's code, as before.
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-enroll-label')).toBeVisible()
  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await typeInto(page.getByTestId('profile-2fa-enroll-label'), 'Tablet')
  await clickSubmit(expect, page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-proof')).toBeVisible()
})

test('opens the add-a-way-in dialog at the chooser once an administrator switched its operation off', async ({
  page,
}) => {
  await createTestAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/security/step-up')
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(1)
  const operation = shownByTestId(
    page,
    'hilos-step-up-switch-add_sign_in_method',
  )
  await expect(operation).toBeChecked()
  let switched = false
  try {
    await operation.click()
    switched = true
    await expect(operation).not.toBeChecked()

    await gotoPage(expect, page, '/profile/sign-in')
    await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))
    await expect(page.getByTestId('profile-sign-in-choose-phone')).toBeVisible()
    await expect(page.getByTestId('step-up')).toHaveCount(0)
  } finally {
    if (switched) {
      await gotoPage(expect, page, '/hilos/security/step-up')
      await operation.click()
      await expect(operation).toBeChecked()
    }
  }
})

test('skips a protected operation disabled by an administrator', async ({
  page,
}) => {
  await createTestAdmin(expect, page)
  // Step-up is a first-level Security page (HIL-1669): the way in is the child
  // card the Security page draws from the catalog.
  await gotoPage(expect, page, '/hilos/security')
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(0)
  await page.getByTestId('hilos-admin-child-hilos_security_step_up').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Operations that ask for confirmation',
  )
  expect(new URL(page.url()).pathname).toBe('/hilos/security/step-up')
  await expect(
    page.getByRole('table', {
      name: 'Operations that ask for confirmation',
    }),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(1)
  const operation = shownByTestId(page, 'hilos-step-up-switch-export_data')
  await expect(operation).toBeChecked()
  let switched = false
  try {
    await operation.click()
    switched = true
    await expect(operation).not.toBeChecked()

    await gotoPage(expect, page, '/profile/data')
    await expect(page.getByTestId('profile-data-view')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('data-export-prepare'))
    await expect(page.getByTestId('data-export-step-up')).toHaveCount(0)
    await expect(page.getByTestId('data-export-ready')).toBeVisible()
  } finally {
    if (switched) {
      await gotoPage(expect, page, '/hilos/security/step-up')
      await operation.click()
      await expect(operation).toBeChecked()
    }
  }
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
    await createTestAdmin(expect, page)
    const person = await createTestUser(expect, personPage)
    await gotoPage(expect, page, '/hilos/security/step-up')
    await expect(revokeSwitch).not.toBeChecked()
    await revokeSwitch.click()
    switched = true
    await expect(revokeSwitch).toBeChecked()

    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    await clickSubmit(expect, page.getByTestId('hilos-user-admin-open'))
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-user-lifecycle-step-up-confirm'),
    )
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()

    await dismissToasts(page)
    await clickSubmit(expect, page.getByTestId('hilos-user-admin-open'))
    await expect(page.getByTestId('modal')).toContainText("Confirm it's you")
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-user-lifecycle-step-up-confirm'),
    )
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Admin rights removed',
    )
  } finally {
    if (switched) {
      await gotoPage(expect, page, '/hilos/security/step-up')
      await revokeSwitch.click()
      await expect(revokeSwitch).not.toBeChecked()
    }
    await personContext.close()
  }
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest sees the open step-up switch but cannot change it', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(expect, page, '/hilos/security/step-up', PAGE_READY)
    const enabled = shownByTestId(
      page,
      'hilos-step-up-switch-add_sign_in_method',
    )
    await expect(enabled).toBeVisible()
    await expect(enabled).toBeDisabled()
    await expect(
      shownByTestId(page, 'hilos-table-row-add_sign_in_method').getByTestId(
        'hilos-hidden',
      ),
    ).toHaveCount(0)
  })
})
