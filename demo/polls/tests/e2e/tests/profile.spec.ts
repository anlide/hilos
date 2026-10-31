import { expect, test, type Page } from '@playwright/test'

import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'
import { endResendPause } from '../helpers/resendPause.js'

import {
  PASSWORD,
  changePassword,
  login,
  nameFromEmail,
  createTestUser,
  uniqueEmail,
  addVirtualAuthenticator,
  expectAccessLossNotice,
  signInAs,
  clickSubmit,
  typeInto,
  gotoPage,
  countSentFrames,
  deliveryObject,
  expectProfileNameSnapshot,
  watchProfileRenameFrames,
  expectPageReady,
  expectProfilePasswordCaption,
  watchDeliveryFrames,
  waitDeliveryBoundary,
} from '../../../../../framework/frontend/e2e/index.js'
import { declareOAuthAccount } from '../../../../../framework/frontend/scripts/standOAuth.mjs'
import {
  uniquePhone,
  waitForSmsCode,
} from '../../../../../framework/frontend/scripts/standSms.mjs'
import {
  dictateModelAnswer,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'

// The profile root (HIL-1169): the avatar in the header leads to /profile over
// the live socket. The Name row exposes Change through the shared rename flow.
// The framework list supplies the confirmed Email row. The rows are the
// sections of the catalog this demo serves, in catalog order — Ways to sign in,
// Active sign-ins, Security, Agreements, then Your data — and each opens its
// page, whose Overview tab leads back.

/** Confirm a password-backed operation inside the open profile dialog. */
async function confirmStepUp(page: Page, confirmId: string): Promise<void> {
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId(confirmId))
}

test('the header avatar leads to the profile root and its sections', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const email = uniqueEmail()
  const name = nameFromEmail(email)
  await createTestUser(expect, page, { email })
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const loadsAfterSignIn = fullLoads

  await clickSubmit(expect, page.getByTestId('nav-profile-name'))
  await expect(page.getByTestId('profile-identity-name')).toHaveText(name)
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  await expect(page.getByTestId('profile-edit')).toBeVisible()
  await expect(page.getByTestId('profile-edit')).toBeEnabled()
  await expect(page.getByTestId('profile-identity-email')).toHaveText(email)
  await expect(page.getByTestId('profile-email')).toContainText(email)
  await expect(page.getByTestId('profile-photo-open')).toBeVisible()

  const sections = ['sign-in', 'sessions', 'security', 'agreements', 'data']
  for (const section of sections) {
    await clickSubmit(
      expect,
      page.getByTestId(
        `hilos-page-tab-hilos_profile_${section.replaceAll('-', '_')}`,
      ),
    )
    await expect(page).toHaveURL(new RegExp(`/profile/${section}$`))
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await clickSubmit(expect, page.getByTestId('hilos-page-tab-hilos_profile'))
    await expect(page.getByTestId('profile-name')).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/profile')
  }
  expect(fullLoads).toBe(loadsAfterSignIn)
  await gotoPage(expect, page, '/profile/data')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  await expect(page.getByTestId('data-export-prepare')).toBeVisible()
  await page.reload()
  await expectPageReady(expect, page)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  await expect(page.getByTestId('profile-identity-email')).toHaveText(email)
  await clickSubmit(expect, page.getByTestId('hilos-page-tab-hilos_profile'))
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  await page.goBack()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/profile\/data$/)
  await expect(page.getByTestId('data-export-prepare')).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
})

test('publishes a profile name only after model approval and preserves it after refusal', async ({
  page,
}) => {
  const wire = watchProfileRenameFrames(page)
  const sends = countSentFrames(page, 'hilos_profile_name_set')
  const key = modelKey()
  try {
    const user = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await expectProfileNameSnapshot(expect, wire, user.userId, null)
    expect(sends()).toBe(0)

    await clickSubmit(expect, page.getByTestId('profile-edit'))
    await confirmStepUp(page, 'profile-name-step-up-confirm')
    const newName = `Updated ${key}`
    await dictateModelAnswer(key, JSON.stringify({ allow: true, reason: 'ok' }))
    await dictateGatewayBehavior('/model/api/generate', key, { delayMs: 3000 })
    await typeInto(page.getByTestId('profile-name-input'), newName)
    await clickSubmit(expect, page.getByTestId('profile-rename-save'))

    await expect.poll(async () => (await readModelRequests(key)).length).toBe(1)
    await expectProfileNameSnapshot(expect, wire, user.userId, {
      name: newName,
      phase: 'checking',
    })
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await expect(page.getByTestId('profile-identity-name')).toHaveText(
      user.name,
    )
    const done = await expectProfileNameSnapshot(expect, wire, user.userId, {
      name: newName,
      phase: 'done',
    })
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect
      .poll(
        () =>
          wire.frames
            .map(({ wire: frame }) => deliveryObject(frame))
            .filter(({ type }) => type === 'handshake_response')
            .at(-1)?.data,
      )
      .toMatchObject({
        entities: { currentUser: { id: user.userId, name: newName } },
      })
    await expect(page.getByTestId('profile-name')).toHaveText(newName)
    await expect(page.getByTestId('profile-identity-name')).toHaveText(newName)

    const attempt = wire.snapshots.filter(
      ({ state }) => state.change?.name === newName,
    )
    const requestId = done.state.change!.requestId
    expect(sends()).toBe(1)
    expect(attempt.length).toBeGreaterThan(0)
    expect([
      ...new Set(attempt.map(({ state }) => state.change?.requestId)),
    ]).toEqual([requestId])
    expect(attempt.map(({ state }) => state.change?.phase)).toContain(
      'checking',
    )
    expect(attempt.at(-1)?.state.change?.phase).toBe('done')

    await clickSubmit(expect, page.getByTestId('profile-edit'))
    const refusedName = `Refused ${key}`
    await dictateModelAnswer(
      key,
      JSON.stringify({ allow: false, reason: 'insult' }),
    )
    await typeInto(page.getByTestId('profile-name-input'), refusedName)
    await clickSubmit(expect, page.getByTestId('profile-rename-save'))
    await expectProfileNameSnapshot(expect, wire, user.userId, {
      name: refusedName,
      phase: 'refused',
    })
    await expect(page.getByTestId('profile-rename-error')).toHaveText(
      'This name was not accepted because it appears to contain an insult.',
    )
    await expect(page.getByTestId('profile-name-input')).toHaveValue(
      refusedName,
    )
    await expect(page.getByTestId('profile-name')).toHaveText(newName)
    await expect(page.getByTestId('profile-identity-name')).toHaveText(newName)
    expect(sends()).toBe(2)
  } finally {
    await test.info().attach('profile-name-wire', {
      body: JSON.stringify({ nameActions: sends(), ...wire }, null, 2),
      contentType: 'application/json',
    })
    wire.stop()
    await forgetModelKey(key)
  }
})

test('F5 restores an open unsent name draft and a closed modal', async ({
  page,
}) => {
  const wire = watchProfileRenameFrames(page)
  const sends = countSentFrames(page, 'hilos_profile_name_set')
  try {
    const user = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)

    await clickSubmit(expect, page.getByTestId('profile-edit'))
    await confirmStepUp(page, 'profile-name-step-up-confirm')
    await typeInto(page.getByTestId('profile-name-input'), 'X')
    const beforeReload = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      null,
    )

    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      null,
      beforeReload.socket,
    )
    await expectPageReady(expect, page)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('profile-name-input')).toHaveValue('X')
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)

    await clickSubmit(expect, page.getByTestId('profile-rename-cancel'))
    await expect(page.getByTestId('modal-confirm')).toHaveRole('alertdialog')
    await clickSubmit(expect, page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId('modal')).toHaveCount(0)
    const closed = wire.snapshots.at(-1)!

    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      null,
      closed.socket,
    )
    await expectPageReady(expect, page)
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    expect(sends()).toBe(0)
  } finally {
    await test.info().attach('unsent-profile-name-wire', {
      body: JSON.stringify({ nameActions: sends(), ...wire }, null, 2),
      contentType: 'application/json',
    })
    wire.stop()
  }
})

test('changes the account email in five steps (HIL-1277)', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const was = uniqueEmail()
  await createTestUser(expect, page, { email: was })
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-email')).toContainText(was)
  const loadsBeforeChange = fullLoads

  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(expect, page.getByTestId('profile-email-send-current'))
  await expect(
    page.getByTestId('profile-email-current-send-line'),
  ).toContainText(`Sent to ${was}`)
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(expect, page.getByTestId('profile-email-confirm-current'))

  const now = uniqueEmail()
  await typeInto(page.getByTestId('profile-email-new'), now)
  await clickSubmit(expect, page.getByTestId('profile-email-send-new'))
  await expect(page.getByTestId('profile-email-new-send-line')).toContainText(
    `Sent to ${now}`,
  )
  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(page.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(expect, page.getByTestId('profile-email-confirm-new'))

  await expect(page.getByTestId('profile-email-was')).toHaveText(was)
  await expect(page.getByTestId('profile-email-now')).toHaveText(now)
  await page.getByTestId('profile-email-done').click()
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-email')).toContainText(now)
  await expect(page.getByTestId('profile-identity-email')).toHaveText(now)
  expect(fullLoads).toBe(loadsBeforeChange)

  await waitForMailTo(was, 'Your email address was changed')
  await waitForMailTo(now, 'Your email address was changed')
})

test('links a GitHub account to the current profile (HIL-401)', async ({
  page,
}) => {
  const providerName = 'profile-metadata-github-login'
  const account = await declareOAuthAccount('github', {
    email: uniqueEmail(),
    login: providerName,
    name: 'A different provider display name',
  })

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/sign-in')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-identities-list')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))
  await confirmStepUp(page, 'profile-sign-in-add-step-up-confirm')
  const loadsBeforeLink = fullLoads

  const linkButton = page.getByTestId('profile-oauth-link-oauth:github')
  await expect(linkButton).toBeVisible()
  await expect(page.getByTestId('profile-identities-list')).not.toContainText(
    'GitHub',
  )

  const linking = signInAs(page, account)
  await linkButton.click()
  await linking

  await expect(page.getByTestId('profile-identities-list')).toContainText(
    'GitHub',
    { timeout: 30000 },
  )
  await expect(page.getByTestId('profile-oauth-link-oauth:github')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('auth-oauth-wait')).toHaveCount(0)
  const github = page.getByTestId('profile-identity-item').filter({
    has: page
      .getByTestId('identity-type')
      .and(page.getByText('GitHub', { exact: true })),
  })
  await expect(github).toHaveCount(1)
  await expect(github.getByTestId('identity-identifier')).toHaveText(
    providerName,
  )

  expect(new URL(page.url()).pathname).toBe('/profile/sign-in')
  expect(fullLoads).toBe(loadsBeforeLink)
})

for (const signOutOthers of [true, false]) {
  test(`changes the password and ${signOutOthers ? 'ends' : 'keeps'} the other session (HIL-300)`, async ({
    page,
    browser,
  }) => {
    const { email } = await createTestUser(expect, page)
    const otherContext = await browser.newContext()
    try {
      const other = await otherContext.newPage()
      await gotoPage(expect, other, '/profile/sign-in')
      await login(expect, other, email)
      await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
      await changePassword(expect, page, {
        email,
        currentPassword: PASSWORD,
        newPassword: 'a-fresh-passphrase',
        signOutOthers,
      })
      await expect(
        page.getByTestId('profile-password-outcome-sessions'),
      ).toHaveText(
        signOutOthers
          ? 'Other sessions were signed out.'
          : 'Other sessions stay signed in.',
      )
      await expect(page.getByTestId('profile-password-outcome')).toContainText(
        'Password reset codes, if any were sent, no longer work.',
      )
      if (signOutOthers) {
        await expect(other.getByTestId('auth-surface')).toBeVisible()
        await login(expect, other, email, PASSWORD)
        await expect(other.getByTestId('auth-error')).toContainText(
          'Incorrect password',
        )
        await login(expect, other, email, 'a-fresh-passphrase')
        await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
      } else {
        await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
        await expect(other.getByTestId('auth-surface')).toHaveCount(0)
      }
      await clickSubmit(expect, page.getByTestId('profile-password-done'))
      await expect(page.getByTestId('profile-password-modal')).toHaveCount(0)
    } finally {
      await otherContext.close()
    }
  })
}

test('keeps password-change input after a wrong code and a common password (HIL-300)', async ({
  page,
}) => {
  const { email } = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/sign-in')
  await clickSubmit(expect, page.getByTestId('profile-password-change'))
  await confirmStepUp(page, 'profile-password-step-up-confirm')
  await clickSubmit(expect, page.getByTestId('profile-password-send-code'))
  await expect(page.getByTestId('profile-password-code')).toBeVisible()
  const code = await waitForMailCode(email, 'Confirm changing your password')
  const wrongCode = (code.startsWith('0') ? '1' : '0') + code.slice(1)
  await typeInto(page.getByTestId('profile-password-code'), wrongCode)
  await clickSubmit(expect, page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-error')).toContainText(
    'Invalid or expired code',
  )
  await expect(page.getByTestId('profile-password-code')).toHaveValue(wrongCode)
  await typeInto(page.getByTestId('profile-password-code'), code)
  await clickSubmit(expect, page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(page.getByTestId('profile-password-new'), '12345678')
  await clickSubmit(expect, page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-error')).toContainText(
    'That password is too common and easy to guess, choose a different one',
  )
  await expect(page.getByTestId('profile-password-new')).toHaveValue('12345678')
  await typeInto(page.getByTestId('profile-password-new'), 'a-fresh-passphrase')
  await clickSubmit(expect, page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-outcome')).toBeVisible()
})

test('says the last code was used, and sends a new one once the pause is over (HIL-1186)', async ({
  page,
}) => {
  const { email } = await createTestUser(expect, page)
  await changePassword(expect, page, {
    email,
    currentPassword: PASSWORD,
    newPassword: 'first-fresh-passphrase',
  })

  await clickSubmit(expect, page.getByTestId('profile-password-again'))
  await expect(page.getByTestId('profile-password-send-code')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('profile-password-send-code'))
  await expect(page.getByTestId('profile-password-code')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-line')).toContainText(
    'already used',
  )
  await expect(page.getByTestId('profile-password-send-again-in')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-again')).toHaveCount(0)

  await endResendPause(page, email)
  await expect(page.getByTestId('profile-password-send-again')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-again')).toBeEnabled()
  await clickSubmit(expect, page.getByTestId('profile-password-send-again'))
  await expect(page.getByTestId('profile-password-send-line')).toContainText(
    'Sent to',
  )
  const code = await waitForMailCode(email, 'Confirm changing your password', 2)
  await typeInto(page.getByTestId('profile-password-code'), code)
  await clickSubmit(expect, page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(
    page.getByTestId('profile-password-new'),
    'second-fresh-passphrase',
  )
  await clickSubmit(expect, page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-outcome')).toBeVisible()
})

test('continues the email change in another tab of the same session (HIL-1182)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  const { email: was } = await createTestUser(expect, tabA)
  await gotoPage(expect, tabA, '/profile')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await tabA.getByTestId('profile-email-change').click()
  await confirmStepUp(tabA, 'profile-email-step-up-confirm')
  await clickSubmit(expect, tabA.getByTestId('profile-email-send-current'))
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(tabA.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(expect, tabA.getByTestId('profile-email-confirm-current'))
  await expect(tabA.getByTestId('profile-email-new')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(expect, tabB, '/profile')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await tabB.getByTestId('profile-email-change').click()
  await expect(tabB.getByTestId('profile-email-new')).toBeVisible()

  const now = uniqueEmail()
  await typeInto(tabB.getByTestId('profile-email-new'), now)
  await clickSubmit(expect, tabB.getByTestId('profile-email-send-new'))
  await expect(tabB.getByTestId('profile-email-code-new')).toBeVisible()
  await expect(tabA.getByTestId('profile-email-code-new')).toBeVisible()

  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(tabB.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(expect, tabB.getByTestId('profile-email-confirm-new'))

  await expect(tabB.getByTestId('profile-email-now')).toHaveText(now)
  await expect(tabA.getByTestId('modal')).toBeHidden()
  await expect(tabA.getByTestId('profile-email')).toContainText(now)
  await tabB.getByTestId('profile-email-done').click()
  await expect(tabB.getByTestId('profile-email')).toContainText(now)
})

test('opens the email change on the step reached before a reload (HIL-1182)', async ({
  page,
}) => {
  const { email: was } = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(expect, page.getByTestId('profile-email-send-current'))
  await expect(page.getByTestId('profile-email-code-current')).toBeVisible()
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )

  await page.reload()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await page.getByTestId('profile-email-change').click()
  await expect(page.getByTestId('profile-email-code-current')).toBeVisible()

  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(expect, page.getByTestId('profile-email-confirm-current'))
  await expect(page.getByTestId('profile-email-new')).toBeVisible()
})

test('saves the new password from another tab of the same session (HIL-1182)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  const tabB = await context.newPage()
  const logs = [watchDeliveryFrames(tabA), watchDeliveryFrames(tabB)]
  const subscribes = [tabA, tabB].map((tab) =>
    countSentFrames(tab, 'page_subscribe'),
  )
  let fullLoads = 0
  for (const tab of [tabA, tabB]) {
    tab.on('load', () => {
      fullLoads += 1
    })
  }
  try {
    const { email } = await createTestUser(expect, tabA)
    await gotoPage(expect, tabA, '/profile/sign-in')
    await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
    const beforeA = await expectProfilePasswordCaption(expect, tabA, logs[0]!)
    await clickSubmit(expect, tabA.getByTestId('profile-password-change'))
    await confirmStepUp(tabA, 'profile-password-step-up-confirm')
    await clickSubmit(expect, tabA.getByTestId('profile-password-send-code'))
    const code = await waitForMailCode(email, 'Confirm changing your password')
    await typeInto(tabA.getByTestId('profile-password-code'), code)
    await clickSubmit(expect, tabA.getByTestId('profile-password-confirm-code'))
    await expect(tabA.getByTestId('profile-password-new')).toBeVisible()

    await gotoPage(expect, tabB, '/profile/sign-in')
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    const beforeB = await expectProfilePasswordCaption(expect, tabB, logs[1]!)
    expect(beforeB.changedAt).toBe(beforeA.changedAt)
    expect(beforeB.caption).toBe(beforeA.caption)
    await clickSubmit(expect, tabB.getByTestId('profile-password-change'))
    await expect(tabB.getByTestId('profile-password-new')).toBeVisible()
    await typeInto(
      tabB.getByTestId('profile-password-new'),
      'a-fresh-passphrase',
    )

    // A server stamp predates its observed packet. Crossing its remaining
    // second on the monotonic clock guarantees a visibly different caption.
    await waitDeliveryBoundary(
      beforeA.packet.at + 1000 - (beforeA.changedAt % 1000),
    )
    const loadsBeforeSave = fullLoads
    const subscriptionsBeforeSave = subscribes.map((count) => count())
    const socketsBeforeSave = logs.map((log) => log.sockets())
    await clickSubmit(expect, tabB.getByTestId('profile-password-save'))

    await expect(tabB.getByTestId('profile-password-outcome')).toBeVisible()
    await expect(tabA.getByTestId('profile-password-modal')).toHaveCount(0)
    const afterA = await expectProfilePasswordCaption(
      expect,
      tabA,
      logs[0]!,
      beforeA.changedAt,
    )
    const afterB = await expectProfilePasswordCaption(
      expect,
      tabB,
      logs[1]!,
      beforeB.changedAt,
    )
    expect(afterA.identity.id).toBe(beforeA.identity.id)
    expect(afterB.identity.id).toBe(beforeB.identity.id)
    expect(afterA.caption).not.toBe(beforeA.caption)
    expect(afterB.caption).not.toBe(beforeB.caption)
    expect(afterB.changedAt).toBe(afterA.changedAt)
    expect(afterB.caption).toBe(afterA.caption)
    await expect(tabB.getByTestId('profile-password-outcome')).toBeVisible()
    expect(fullLoads).toBe(loadsBeforeSave)
    expect(subscribes.map((count) => count())).toEqual(subscriptionsBeforeSave)
    expect(logs.map((log) => log.sockets())).toEqual(socketsBeforeSave)
    await expect(tabA).toHaveURL(/\/profile\/sign-in$/)
    await expect(tabB).toHaveURL(/\/profile\/sign-in$/)
  } finally {
    for (const [index, log] of logs.entries()) {
      await test.info().attach(`password-list-tab-${index}`, {
        body: JSON.stringify(log, null, 2),
        contentType: 'application/json',
      })
      log.stop()
    }
  }
})

test('adds a phone from another tab of the same session (HIL-1184)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  await createTestUser(expect, tabA)
  await gotoPage(expect, tabA, '/profile/sign-in')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(expect, tabA.getByTestId('profile-sign-in-add'))
  await confirmStepUp(tabA, 'profile-sign-in-add-step-up-confirm')
  await clickSubmit(expect, tabA.getByTestId('profile-sign-in-choose-phone'))
  const phone = uniquePhone('GB')
  // UK mobiles have a four-digit group followed by six national digits.
  const subtitle = `+44 ${phone.slice(3, 7)} ${phone.slice(7)} · verified`
  await typeInto(tabA.getByTestId('profile-add-sms-phone'), phone)
  await clickSubmit(expect, tabA.getByTestId('profile-add-sms-request'))
  await expect(tabA.getByTestId('profile-add-sms-code')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(expect, tabB, '/profile/sign-in')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(expect, tabB.getByTestId('profile-sign-in-add'))
  await expect(tabB.getByTestId('profile-add-sms-code')).toBeVisible()
  await expect(tabB.getByTestId('step-up')).toHaveCount(0)

  await typeInto(
    tabB.getByTestId('profile-add-sms-code'),
    await waitForSmsCode(phone),
  )
  await clickSubmit(expect, tabB.getByTestId('profile-add-sms-confirm'))
  await expect(tabB.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(tabA.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  for (const tab of [tabA, tabB]) {
    const identity = tab.getByTestId('profile-identity-item').filter({
      has: tab
        .getByTestId('identity-type')
        .and(tab.getByText('Phone', { exact: true })),
    })
    await expect(identity).toHaveCount(1)
    await expect(identity.getByTestId('identity-identifier')).toHaveText(
      subtitle,
    )
  }
})

test('ends one other browser session from the sessions page', async ({
  browser,
}) => {
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  try {
    const pageA = await contextA.newPage()
    const account = await createTestUser(expect, pageA)
    const pageB = await contextB.newPage()
    await gotoPage(expect, pageB, '/profile/sessions')
    await login(expect, pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    await gotoPage(expect, pageB, '/profile/sign-in')
    await clickSubmit(expect, pageB.getByTestId('profile-sign-in-add'))
    await confirmStepUp(pageB, 'profile-sign-in-add-step-up-confirm')
    await clickSubmit(expect, pageB.getByTestId('profile-sign-in-choose-phone'))
    const phone = uniquePhone()
    await typeInto(pageB.getByTestId('profile-add-sms-phone'), phone)
    await expect(pageB.getByTestId('profile-add-sms-phone')).toHaveValue(phone)

    const publicTabB = await contextB.newPage()
    await gotoPage(expect, publicTabB, '/')
    await expect(publicTabB.getByTestId('self-user-id')).toHaveText(
      String(account.userId),
    )
    await expect(publicTabB.getByTestId('self-user')).toHaveText(account.name)
    const documents = await Promise.all(
      [pageB, publicTabB].map(async (target) => ({
        page: target,
        url: target.url(),
        timeOrigin: await target.evaluate(() => performance.timeOrigin),
      })),
    )

    await gotoPage(expect, pageA, '/profile/sessions')
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)
    await expect(pageA.getByTestId('profile-session-this')).toHaveCount(1)
    await expect(
      pageA.getByTestId('profile-session-device').first(),
    ).not.toHaveText('Unknown device')

    await pageA.getByTestId('profile-session-revoke').click()
    await clickSubmit(expect, pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
    for (const document of documents) {
      const target = document.page
      await expect(target.getByTestId('auth-surface')).toHaveCount(1)
      await expect(target.getByTestId('auth-surface')).toBeVisible()
      await expectAccessLossNotice(expect, target, 'session_ended')
      await expect(target.getByTestId('modal')).toHaveCount(0)
      await expect(target.getByTestId('modal-confirm')).toHaveCount(0)
      await expect(target).toHaveURL(document.url)
      expect(await target.evaluate(() => performance.timeOrigin)).toBe(
        document.timeOrigin,
      )
    }
    await expect(pageB.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
    await expect(pageB.getByTestId('profile-add-sms-phone')).toHaveCount(0)
    expect(
      await pageB.evaluate(() =>
        Array.from(document.querySelectorAll('input'), (input) => input.value),
      ),
    ).not.toContain(phone)
    await expectAccessLossNotice(expect, pageA, null)
  } finally {
    await contextA.close()
    await contextB.close()
  }
})

test('signs out every other browser session at once', async ({ browser }) => {
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  try {
    const pageA = await contextA.newPage()
    const account = await createTestUser(expect, pageA)
    const pageB = await contextB.newPage()
    await gotoPage(expect, pageB, '/profile/sessions')
    await login(expect, pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    await gotoPage(expect, pageA, '/profile/sessions')
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)

    await pageA.getByTestId('profile-sessions-end-others').click()
    const modal = pageA.getByTestId('modal')
    await expect(modal).toBeVisible()
    await expect(
      pageA.getByTestId('profile-sessions-end-others-count'),
    ).toHaveText('1')
    await modal.getByRole('button', { name: 'Cancel' }).click()
    await expect(modal).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)
    await expect(pageB.getByTestId('auth-surface')).toBeHidden()

    await pageA.getByTestId('profile-sessions-end-others').click()
    await expect(modal).toBeVisible()
    await clickSubmit(
      expect,
      pageA.getByTestId('profile-sessions-end-others-confirm'),
    )
    await expect(modal).toBeHidden()

    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
    await expect(pageB.getByTestId('auth-surface')).toBeVisible()
  } finally {
    await contextA.close()
    await contextB.close()
  }
})

test('ends sessions of closed browsers without a reload (HIL-1180)', async ({
  browser,
}) => {
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  const contextC = await browser.newContext()
  try {
    const pageA = await contextA.newPage()
    const account = await createTestUser(expect, pageA)
    const pageB = await contextB.newPage()
    await gotoPage(expect, pageB, '/profile/sessions')
    await login(expect, pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    const pageC = await contextC.newPage()
    await gotoPage(expect, pageC, '/profile/sessions')
    await login(expect, pageC, account.email)
    await expect(pageC.getByTestId('profile-sessions')).toBeVisible()

    await contextB.close()
    await contextC.close()

    await gotoPage(expect, pageA, '/profile/sessions')
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(3)

    await pageA.getByTestId('profile-session-revoke').first().click()
    await clickSubmit(expect, pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)

    await pageA.getByTestId('profile-session-revoke').first().click()
    await clickSubmit(expect, pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
  } finally {
    await contextA.close()
    await contextB.close()
    await contextC.close()
  }
})

test('updates the sign-in methods when another tab adds a device key', async ({
  page,
  context,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile/sign-in')
  await expect(page.getByTestId('profile-password-change')).toBeVisible()
  await expect(page.getByTestId('profile-identity-item')).toHaveCount(1)
  let reloads = 0
  page.on('load', () => {
    reloads += 1
  })
  const other = await context.newPage()
  await addVirtualAuthenticator(other)
  await gotoPage(expect, other, '/profile/sign-in')
  await clickSubmit(expect, other.getByTestId('profile-sign-in-add'))
  await confirmStepUp(other, 'profile-sign-in-add-step-up-confirm')
  await clickSubmit(expect, other.getByTestId('profile-passkey-add'))
  await expect(other.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(other.getByTestId('identity-passkey-added')).toHaveCount(1)
  await expect(page.getByTestId('profile-identity-item')).toHaveCount(2)
  await expect(page.getByTestId('identity-passkey-added')).toHaveCount(1)
  expect(reloads).toBe(0)
  await other.close()
})
