// Fresh confirmation before a protected profile operation (HIL-495): the
// application chooses the account's strongest available proof, the modal keeps
// that proof as its first step, and an administrator may narrow the declared
// operation list. Mail codes are read from the stand mailbox, never a backdoor.
import { expect, test as base } from '../test.js'

import {
  cleanupFixtures,
  type TestCleanup,
  watchSocketFrames,
  deliveryObject,
  PASSWORD,
  createTestUser,
  dismissToasts,
  shownByTestId,
  clickSubmit,
  typeInto,
  connectFirstApp,
  gotoPage,
  createTestAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  resolveHilosSettingRow,
  type HilosSettingRow,
} from '../../../../../framework/frontend/core/src/admin/settings/hilosSettings.js'
import { HilosPages } from '../../../../../framework/frontend/core/src/routing/hilosPages.js'
import {
  SIGNAL_TYPE_TABLE_WINDOW,
  SIGNAL_TYPE_TABLE_VIEWPORT_DELTA,
} from '../../../../../framework/frontend/core/src/protocol/constants.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import {
  nextTotpCode,
  totpStep,
} from '../../../../../framework/frontend/scripts/totp.mjs'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

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

test('disables export and adding a sign-in method independently, restoring the policy', async ({
  page,
  cleanup,
}) => {
  await createTestAdmin(expect, page)
  const settingsReader = await page.context().newPage()
  cleanup.defer(() => settingsReader.close())
  const key = 'auth.step_up.disabled'
  let setting: HilosSettingRow | undefined
  const decodingFailures: unknown[] = []
  const observer = watchSocketFrames(settingsReader, ({ wire, failure }) => {
    if (failure) decodingFailures.push(failure)
    const envelope = deliveryObject(wire)
    const data = deliveryObject(envelope.data)
    if (data.page !== HilosPages.SETTINGS || data.tableKey !== 'settings')
      return
    const rows =
      envelope.type === SIGNAL_TYPE_TABLE_WINDOW && Array.isArray(data.rows)
        ? data.rows
        : envelope.type === SIGNAL_TYPE_TABLE_VIEWPORT_DELTA
          ? [data.row]
          : []
    for (const raw of rows) {
      const row = deliveryObject(raw)
      if (row.rowKey === key) {
        setting = resolveHilosSettingRow({
          rowKey: key,
          slots: deliveryObject(row.slots),
        })
      }
    }
  })
  cleanup.defer(() => observer.stop())
  await gotoPage(expect, settingsReader, '/hilos/settings')
  await typeInto(settingsReader.getByTestId('hilos-table-search'), key)
  await expect.poll(() => setting).toBeDefined()
  expect(decodingFailures).toEqual([])
  const original = setting!
  const originalOverride = original.overrideValue
  if (originalOverride !== null && typeof originalOverride !== 'string') {
    throw new Error(
      'The administrator must be able to read the setting override',
    )
  }
  const sendCommand = createStandCommandChannel({ timeoutMs: 5000 })
  const restore = async () => {
    if (originalOverride === null) {
      await sendCommand('settings:reset', { key })
    } else {
      await sendCommand('settings:set', { key, value: originalOverride })
    }
    // A fresh window proves both the raw override and its effective source.
    setting = undefined
    await gotoPage(expect, settingsReader, '/hilos/settings')
    await typeInto(settingsReader.getByTestId('hilos-table-search'), key)
    await expect.poll(() => setting).toEqual(original)
    expect(decodingFailures).toEqual([])
  }
  cleanup.defer(restore)

  await test.step('skips a protected operation disabled by an administrator', async () => {
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
    await operation.click()
    await expect(operation).not.toBeChecked()

    await gotoPage(expect, page, '/profile/data')
    await expect(page.getByTestId('profile-data-view')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('data-export-prepare'))
    await expect(page.getByTestId('data-export-step-up')).toHaveCount(0)
    await expect(page.getByTestId('data-export-ready')).toBeVisible()
    await restore()
  })

  await test.step('opens the add-a-way-in dialog at the chooser once an administrator switched its operation off', async () => {
    await gotoPage(expect, page, '/hilos/security/step-up')
    await expect(page.getByTestId('hilos-step-up-table')).toHaveCount(1)
    const operation = shownByTestId(
      page,
      'hilos-step-up-switch-add_sign_in_method',
    )
    await expect(operation).toBeChecked()
    await operation.click()
    await expect(operation).not.toBeChecked()

    await gotoPage(expect, page, '/profile/sign-in')
    await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))
    await expect(page.getByTestId('profile-sign-in-choose-phone')).toBeVisible()
    await expect(page.getByTestId('step-up')).toHaveCount(0)
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  })
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
