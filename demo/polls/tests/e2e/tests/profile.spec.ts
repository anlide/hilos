// The profile root (HIL-1169): the avatar in the header leads to /profile over
// the live socket. The person's own line carries the session name; Name has no
// Change, because this demo hands the page no rename of its own. The framework
// list supplies the confirmed Email row. The rows are the sections of the
// catalog this demo serves, in catalog order — Ways to sign in, Active
// sign-ins, Security, Agreements, then Your data — and each opens its page,
// whose "Profile" crumb now leads back.
import { expect, test, type Page } from '@playwright/test'

import { waitForMailCode, waitForMailTo } from '../helpers/mail.js'
import { gotoPage } from '../helpers/page.js'
import { endResendPause } from '../helpers/resendPause.js'
import {
  PASSWORD,
  changePassword,
  clickSubmit,
  login,
  nameFromEmail,
  openSignIn,
  register,
  signUp,
  typeInto,
  uniqueEmail,
} from '../helpers/session.js'
import {
  addVirtualAuthenticator,
  signInAs,
} from '../../../../../framework/frontend/e2e/index.js'
import { declareOAuthAccount } from '../../../../../framework/frontend/scripts/standOAuth.mjs'
import {
  uniquePhone,
  waitForSmsCode,
} from '../../../../../framework/frontend/scripts/standSms.mjs'

/** Confirm a password-backed operation inside the open profile dialog. */
async function confirmStepUp(page: Page, confirmId: string): Promise<void> {
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId(confirmId))
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
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const loadsAfterSignIn = fullLoads

  await clickSubmit(page.getByTestId('nav-profile-name'))
  await expect(page.getByTestId('profile-identity-name')).toHaveText(name)
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  await expect(page.getByTestId('profile-edit')).toHaveCount(0)
  await expect(page.getByTestId('profile-identity-email')).toHaveText(email)
  await expect(page.getByTestId('profile-email')).toContainText(email)

  const sections = ['sign-in', 'sessions', 'security', 'agreements', 'data']
  await expect(page.getByTestId('profile-section')).toHaveCount(sections.length)
  expect(
    await page
      .getByTestId(/^profile-[a-z-]+-open$/)
      .evaluateAll((links) =>
        links.map((link) => link.getAttribute('data-id')),
      ),
  ).toEqual(sections.map((section) => `profile-${section}-open`))
  for (const section of sections) {
    await clickSubmit(page.getByTestId(`profile-${section}-open`))
    await expect(page).toHaveURL(new RegExp(`/profile/${section}$`))
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await clickSubmit(page.getByTestId('hilos-breadcrumb-hilos_profile'))
    await expect(page.getByTestId('profile-name')).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/profile')
  }
  expect(fullLoads).toBe(loadsAfterSignIn)
})

test('changes the account email in five steps (HIL-1277)', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const was = uniqueEmail()
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, was)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-email')).toContainText(was)
  const loadsBeforeChange = fullLoads

  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(page.getByTestId('profile-email-send-current'))
  await expect(
    page.getByTestId('profile-email-current-send-line'),
  ).toContainText(`Sent to ${was}`)
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-current'))

  const now = uniqueEmail()
  await typeInto(page.getByTestId('profile-email-new'), now)
  await clickSubmit(page.getByTestId('profile-email-send-new'))
  await expect(page.getByTestId('profile-email-new-send-line')).toContainText(
    `Sent to ${now}`,
  )
  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(page.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-new'))

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
  const account = await declareOAuthAccount('github', { email: uniqueEmail() })

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUp(page)
  await gotoPage(page, '/profile/sign-in')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-identities-list')).toBeVisible()
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
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

  expect(new URL(page.url()).pathname).toBe('/profile/sign-in')
  expect(fullLoads).toBe(loadsBeforeLink)
})

for (const signOutOthers of [true, false]) {
  test(`changes the password and ${signOutOthers ? 'ends' : 'keeps'} the other session (HIL-300)`, async ({
    page,
    browser,
  }) => {
    const { email } = await signUp(page)
    const otherContext = await browser.newContext()
    try {
      const other = await otherContext.newPage()
      await gotoPage(other, '/profile/sign-in')
      await login(other, email)
      await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
      await changePassword(page, {
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
        await login(other, email, PASSWORD)
        await expect(other.getByTestId('auth-error')).toContainText(
          'Incorrect password',
        )
        await login(other, email, 'a-fresh-passphrase')
        await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
      } else {
        await expect(other.getByTestId('profile-sign-in-view')).toBeVisible()
        await expect(other.getByTestId('auth-surface')).toHaveCount(0)
      }
      await clickSubmit(page.getByTestId('profile-password-done'))
      await expect(page.getByTestId('profile-password-modal')).toHaveCount(0)
    } finally {
      await otherContext.close()
    }
  })
}

test('keeps password-change input after a wrong code and a common password (HIL-300)', async ({
  page,
}) => {
  const { email } = await signUp(page)
  await gotoPage(page, '/profile/sign-in')
  await clickSubmit(page.getByTestId('profile-password-change'))
  await confirmStepUp(page, 'profile-password-step-up-confirm')
  await clickSubmit(page.getByTestId('profile-password-send-code'))
  await expect(page.getByTestId('profile-password-code')).toBeVisible()
  const code = await waitForMailCode(email, 'Confirm changing your password')
  const wrongCode = (code.startsWith('0') ? '1' : '0') + code.slice(1)
  await typeInto(page.getByTestId('profile-password-code'), wrongCode)
  await clickSubmit(page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-error')).toContainText(
    'Invalid or expired code',
  )
  await expect(page.getByTestId('profile-password-code')).toHaveValue(wrongCode)
  await typeInto(page.getByTestId('profile-password-code'), code)
  await clickSubmit(page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(page.getByTestId('profile-password-new'), '12345678')
  await clickSubmit(page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-error')).toContainText(
    'That password is too common and easy to guess, choose a different one',
  )
  await expect(page.getByTestId('profile-password-new')).toHaveValue('12345678')
  await typeInto(page.getByTestId('profile-password-new'), 'a-fresh-passphrase')
  await clickSubmit(page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-outcome')).toBeVisible()
})

test('says the last code was used, and sends a new one once the pause is over (HIL-1186)', async ({
  page,
}) => {
  const { email } = await signUp(page)
  await changePassword(page, {
    email,
    currentPassword: PASSWORD,
    newPassword: 'first-fresh-passphrase',
  })

  await clickSubmit(page.getByTestId('profile-password-again'))
  await expect(page.getByTestId('profile-password-send-code')).toBeVisible()
  await clickSubmit(page.getByTestId('profile-password-send-code'))
  await expect(page.getByTestId('profile-password-code')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-line')).toContainText(
    'already used',
  )
  await expect(page.getByTestId('profile-password-send-again-in')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-again')).toHaveCount(0)

  await endResendPause(page, email)
  await expect(page.getByTestId('profile-password-send-again')).toBeVisible()
  await expect(page.getByTestId('profile-password-send-again')).toBeEnabled()
  await clickSubmit(page.getByTestId('profile-password-send-again'))
  await expect(page.getByTestId('profile-password-send-line')).toContainText(
    'Sent to',
  )
  const code = await waitForMailCode(email, 'Confirm changing your password', 2)
  await typeInto(page.getByTestId('profile-password-code'), code)
  await clickSubmit(page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(
    page.getByTestId('profile-password-new'),
    'second-fresh-passphrase',
  )
  await clickSubmit(page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-outcome')).toBeVisible()
})

test('continues the email change in another tab of the same session (HIL-1182)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  const { email: was } = await signUp(tabA)
  await gotoPage(tabA, '/profile')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await tabA.getByTestId('profile-email-change').click()
  await confirmStepUp(tabA, 'profile-email-step-up-confirm')
  await clickSubmit(tabA.getByTestId('profile-email-send-current'))
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(tabA.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(tabA.getByTestId('profile-email-confirm-current'))
  await expect(tabA.getByTestId('profile-email-new')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(tabB, '/profile')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await tabB.getByTestId('profile-email-change').click()
  await expect(tabB.getByTestId('profile-email-new')).toBeVisible()

  const now = uniqueEmail()
  await typeInto(tabB.getByTestId('profile-email-new'), now)
  await clickSubmit(tabB.getByTestId('profile-email-send-new'))
  await expect(tabB.getByTestId('profile-email-code-new')).toBeVisible()
  await expect(tabA.getByTestId('profile-email-code-new')).toBeVisible()

  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(tabB.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(tabB.getByTestId('profile-email-confirm-new'))

  await expect(tabB.getByTestId('profile-email-now')).toHaveText(now)
  await expect(tabA.getByTestId('modal')).toBeHidden()
  await expect(tabA.getByTestId('profile-email')).toContainText(now)
  await tabB.getByTestId('profile-email-done').click()
  await expect(tabB.getByTestId('profile-email')).toContainText(now)
})

test('opens the email change on the step reached before a reload (HIL-1182)', async ({
  page,
}) => {
  const { email: was } = await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(page.getByTestId('profile-email-send-current'))
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
  await clickSubmit(page.getByTestId('profile-email-confirm-current'))
  await expect(page.getByTestId('profile-email-new')).toBeVisible()
})

test('saves the new password from another tab of the same session (HIL-1182)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  const { email } = await signUp(tabA)
  await gotoPage(tabA, '/profile/sign-in')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(tabA.getByTestId('profile-password-change'))
  await confirmStepUp(tabA, 'profile-password-step-up-confirm')
  await clickSubmit(tabA.getByTestId('profile-password-send-code'))
  const code = await waitForMailCode(email, 'Confirm changing your password')
  await typeInto(tabA.getByTestId('profile-password-code'), code)
  await clickSubmit(tabA.getByTestId('profile-password-confirm-code'))
  await expect(tabA.getByTestId('profile-password-new')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(tabB, '/profile/sign-in')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(tabB.getByTestId('profile-password-change'))
  await expect(tabB.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(tabB.getByTestId('profile-password-new'), 'a-fresh-passphrase')
  await clickSubmit(tabB.getByTestId('profile-password-save'))

  await expect(tabB.getByTestId('profile-password-outcome')).toBeVisible()
  await expect(tabA.getByTestId('profile-password-modal')).toHaveCount(0)
})

test('adds a phone from another tab of the same session (HIL-1184)', async ({
  context,
}) => {
  const tabA = await context.newPage()
  await signUp(tabA)
  await gotoPage(tabA, '/profile/sign-in')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(tabA.getByTestId('profile-sign-in-add'))
  await confirmStepUp(tabA, 'profile-sign-in-add-step-up-confirm')
  await clickSubmit(tabA.getByTestId('profile-sign-in-choose-phone'))
  const phone = uniquePhone()
  await typeInto(tabA.getByTestId('profile-add-sms-phone'), phone)
  await clickSubmit(tabA.getByTestId('profile-add-sms-request'))
  await expect(tabA.getByTestId('profile-add-sms-code')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(tabB, '/profile/sign-in')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await clickSubmit(tabB.getByTestId('profile-sign-in-add'))
  await expect(tabB.getByTestId('profile-add-sms-code')).toBeVisible()
  await expect(tabB.getByTestId('step-up')).toHaveCount(0)

  await typeInto(
    tabB.getByTestId('profile-add-sms-code'),
    await waitForSmsCode(phone),
  )
  await clickSubmit(tabB.getByTestId('profile-add-sms-confirm'))
  await expect(tabB.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(tabA.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(
    tabA.getByTestId('profile-identity-item').filter({ hasText: phone }),
  ).toHaveCount(1)
  await expect(
    tabB.getByTestId('profile-identity-item').filter({ hasText: phone }),
  ).toHaveCount(1)
})

test('ends one other browser session from the sessions page', async ({
  browser,
}) => {
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  try {
    const pageA = await contextA.newPage()
    const account = await signUp(pageA)
    const pageB = await contextB.newPage()
    await gotoPage(pageB, '/profile/sessions')
    await login(pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    await gotoPage(pageA, '/profile/sessions')
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)
    await expect(pageA.getByTestId('profile-session-this')).toHaveCount(1)
    await expect(
      pageA.getByTestId('profile-session-device').first(),
    ).not.toHaveText('Unknown device')

    await pageA.getByTestId('profile-session-revoke').click()
    await clickSubmit(pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
    await expect(pageB.getByTestId('auth-surface')).toBeVisible()
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
    const account = await signUp(pageA)
    const pageB = await contextB.newPage()
    await gotoPage(pageB, '/profile/sessions')
    await login(pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    await gotoPage(pageA, '/profile/sessions')
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
    await clickSubmit(pageA.getByTestId('profile-sessions-end-others-confirm'))
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
    const account = await signUp(pageA)
    const pageB = await contextB.newPage()
    await gotoPage(pageB, '/profile/sessions')
    await login(pageB, account.email)
    await expect(pageB.getByTestId('profile-sessions')).toBeVisible()

    const pageC = await contextC.newPage()
    await gotoPage(pageC, '/profile/sessions')
    await login(pageC, account.email)
    await expect(pageC.getByTestId('profile-sessions')).toBeVisible()

    await contextB.close()
    await contextC.close()

    await gotoPage(pageA, '/profile/sessions')
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(3)

    await pageA.getByTestId('profile-session-revoke').first().click()
    await clickSubmit(pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(2)

    await pageA.getByTestId('profile-session-revoke').first().click()
    await clickSubmit(pageA.getByTestId('profile-session-end-confirm'))
    await expect(pageA.getByTestId('modal')).toBeHidden()
    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
  } finally {
    await contextA.close()
    await contextB.close()
    await contextC.close()
  }
})

test('updates the sign-in summary when another tab adds a device key', async ({
  page,
  context,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-sign-in-summary')).toHaveText(
    'Password',
  )
  let reloads = 0
  page.on('load', () => {
    reloads += 1
  })
  const other = await context.newPage()
  await addVirtualAuthenticator(other)
  await gotoPage(other, '/profile/sign-in')
  await clickSubmit(other.getByTestId('profile-sign-in-add'))
  await confirmStepUp(other, 'profile-sign-in-add-step-up-confirm')
  await clickSubmit(other.getByTestId('profile-passkey-add'))
  await expect(other.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(other.getByTestId('identity-passkey-added')).toHaveCount(1)
  await expect(page.getByTestId('profile-sign-in-summary')).toHaveText(
    'Password, 1 passkey',
  )
  expect(reloads).toBe(0)
  await other.close()
})
