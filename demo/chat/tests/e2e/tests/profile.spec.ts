import { test, expect, type Locator, type Page } from '@playwright/test'

import { waitForMailCode, waitForMailTo } from '../helpers/mail'
import {
  PASSWORD,
  clickSubmit,
  changePassword,
  login,
  register,
  signUp,
  uniqueEmail,
} from '../helpers/session'
import { gotoPage } from '../helpers/page'
import { dictateModerationVerdict } from '../helpers/moderation'
import {
  addVirtualAuthenticator,
  sidewaysOverflow,
  signInAs,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { declareOAuthAccount } from '../../../../../framework/frontend/scripts/standOAuth.mjs'

// Type into a Vue input the way a user does — clear, then key by key — so the
// reactivity a bare fill() can miss actually fires (see helpers/session).
async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
}

/** Confirm a password-backed protected operation inside an open profile modal. */
async function confirmStepUp(page: Page, confirmId: string): Promise<void> {
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId(confirmId))
}

// Profile e2e: the framework-owned profile page (hilos_profile) reached from the
// navbar, and the edit-in-modal rename. The profile is a signed-in-only surface
// (AUTHENTICATED page guard), so each test establishes a user first; an anonymous
// visitor gets the sign-in surface in place instead (covered by auth.spec.ts).
// Success is state-driven — the committed name arrives over the self-connection
// data and closes the modal. A rename is moderated by the stand's model, so each
// one first dictates a permitting verdict under a key the new name carries
// (helpers/moderation.ts). A refused name and an unanswering model are proved by
// rename-moderation.spec.ts.

test('the navbar links the current user to the profile page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUp(page)
  const loadsAfterColdLoad = fullLoads

  await expect(page.getByTestId('nav-profile')).toBeVisible()
  await page.getByTestId('nav-profile').click()

  // Reached over the live socket with no document reload.
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  const sections = [
    'sign-in',
    'notifications',
    'sessions',
    'devices',
    'security',
    'agreements',
    'data',
  ]
  await expect(page.getByTestId('profile-section')).toHaveCount(sections.length)
  expect(
    await page
      .getByTestId(
        /^profile-(sign-in|notifications|sessions|devices|security|agreements|data)-open$/,
      )
      .evaluateAll((links) =>
        links.map((link) => link.getAttribute('data-id')),
      ),
  ).toEqual(sections.map((section) => `profile-${section}-open`))
  for (const section of sections) {
    await clickSubmit(page.getByTestId(`profile-${section}-open`))
    expect(new URL(page.url()).pathname).toBe(`/profile/${section}`)
    await expect(
      page.getByTestId('hilos-breadcrumb-hilos_profile'),
    ).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await clickSubmit(page.getByTestId('hilos-breadcrumb-hilos_profile'))
    await expect(page.getByTestId('profile-name')).toBeVisible()
  }
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('renames the current user through the edit modal', async ({ page }) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  const key = modelKey()
  const newName = `Renamed ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await page.getByTestId('profile-edit').click()
  await confirmStepUp(page, 'profile-name-step-up-confirm')
  await typeInto(page.getByTestId('profile-name-input'), newName)
  await page.getByTestId('profile-rename-save').click()

  // The backend moderates (approved) and renames; the committed name lands over
  // the self-connection data, which closes the modal and updates the card.
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(newName)
})

// HIL-401, un-quarantined by HIL-633. The link used to navigate the document off
// /profile, which killed the connection holding the profileIdentities
// subscription: the live DB_SYNC re-projection had no subscriber left to push to,
// and the refresh fell back to the post-callback re-subscribe snapshot, read from a
// worker that had not necessarily applied the identity's DB_SYNC_CREATED yet. The
// trip now runs in a separate window, so the subscription never dies and the new
// row arrives on it — the race the quarantine waited on a propagation barrier for
// is not raced any more, it is simply not entered.
test('links a GitHub account to the current profile (HIL-401)', async ({
  page,
}) => {
  // Link mode reuses the whole OAuth flow but attaches the identity to the
  // already-signed-in account instead of resolving one. The stand's provider
  // emulator plays GitHub (HIL-923, HIL-924): the opened window shows its consent
  // screen, the person confirms the declared account, the provider sends the
  // window back to /auth/callback, that window couriers the code home and closes,
  // and this page does the exchange. The agent then reads GitHub's userinfo, which
  // the emulator refuses with 403 when the request names no User-Agent, as the
  // live api.github.com does — so this link also proves the transport sends one.
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
  const loadsBeforeLink = fullLoads

  // A fresh password account offers GitHub to link and has no oauth identity yet.
  const linkButton = page.getByTestId('profile-oauth-link-oauth:github')
  await expect(linkButton).toBeVisible()
  await expect(page.getByTestId('profile-identities-list')).not.toContainText(
    'GitHub',
  )

  // The wait for the provider's window starts before the click, which opens it
  // synchronously.
  const linking = signInAs(page, account)
  await linkButton.click()
  await linking

  // The link resolves out of band (link-ok signal) on THIS page's own connection,
  // so the identities projection re-emits into the list that is already on screen
  // and GitHub stops being offered.
  await expect(page.getByTestId('profile-identities-list')).toContainText(
    'GitHub',
    { timeout: 30000 },
  )
  await expect(page.getByTestId('profile-oauth-link-oauth:github')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('auth-oauth-wait')).toHaveCount(0)

  // The whole trip happened beside this page, not through it: still /profile/sign-in, and
  // not one document load since the profile opened.
  expect(new URL(page.url()).pathname).toBe('/profile/sign-in')
  expect(fullLoads).toBe(loadsBeforeLink)
})

test('surfaces a conflict when the name changes in another tab', async ({
  context,
}) => {
  // Two tabs of the same user share the session cookie within one context, so
  // registering in the first tab signs both in.
  const tabA = await context.newPage()
  await signUp(tabA)
  await gotoPage(tabA, '/profile')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabA.getByTestId('profile-name')).toBeVisible()

  const tabB = await context.newPage()
  await gotoPage(tabB, '/profile')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('profile-name')).toBeVisible()

  // Tab B starts editing with a divergent draft, but does not submit.
  await tabB.getByTestId('profile-edit').click()
  await confirmStepUp(tabB, 'profile-name-step-up-confirm')
  await typeInto(tabB.getByTestId('profile-name-input'), 'Tab B Name')

  // Tab A renames the same user. Only its name goes to moderation — tab B never
  // submits — so only its name carries a key and a dictated permission.
  const key = modelKey()
  const tabAName = `Tab A ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await tabA.getByTestId('profile-edit').click()
  await typeInto(tabA.getByTestId('profile-name-input'), tabAName)
  await tabA.getByTestId('profile-rename-save').click()
  await expect(tabA.getByTestId('profile-name')).toHaveText(tabAName)

  // Tab B's open modal sees the incoming change and flags a conflict.
  await expect(tabB.getByTestId('conflict-badge')).toBeVisible()

  // Taking theirs adopts Tab A's name and clears the conflict.
  await tabB.getByTestId('conflict-accept-theirs').click()
  await expect(tabB.getByTestId('conflict-badge')).toBeHidden()
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

test('changes the account email in five steps (HIL-299)', async ({ page }) => {
  // Registration proves the address it was made with, so a fresh account has the
  // Email row and a current mailbox to prove. Both codes are read from the stand's
  // mail interceptor, the way a person reads them out of two inboxes.
  const { email: was } = await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-email')).toContainText(was)

  // Steps 1 and 2: the current address answers for itself first.
  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(page.getByTestId('profile-email-send-current'))
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-current'))

  // Steps 3 and 4: the new address is proven before anything moves.
  const now = uniqueEmail()
  await typeInto(page.getByTestId('profile-email-new'), now)
  await clickSubmit(page.getByTestId('profile-email-send-new'))
  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(page.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-new'))

  // Step 5 says what changed; the card follows over the identities projection.
  await expect(page.getByTestId('profile-email-was')).toHaveText(was)
  await expect(page.getByTestId('profile-email-now')).toHaveText(now)
  await page.getByTestId('profile-email-done').click()
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-email')).toContainText(now)

  // The notice went to both mailboxes.
  await waitForMailTo(was, 'Your email address was changed')
  await waitForMailTo(now, 'Your email address was changed')
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
    await clickSubmit(pageA.getByTestId('profile-sessions-end-others'))

    await expect(pageA.getByTestId('profile-session-row')).toHaveCount(1)
    await expect(pageB.getByTestId('auth-surface')).toBeVisible()
  } finally {
    await contextA.close()
    await contextB.close()
  }
})

test('opens the push devices page', async ({ page }) => {
  await signUp(page)
  await gotoPage(page, '/profile/devices')

  await expect(page.getByTestId('profile-devices')).toBeVisible()
  await expect(page.getByTestId('profile-devices-heading')).toHaveText(
    'Devices',
  )
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
  await clickSubmit(other.getByTestId('profile-passkey-add'))
  await expect(other.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(other.getByTestId('identity-passkey-added')).toHaveCount(1)
  await expect(page.getByTestId('profile-sign-in-summary')).toHaveText(
    'Password, 1 passkey',
  )
  expect(reloads).toBe(0)
  await other.close()
})

test('keeps long profile addresses inside a narrow screen', async ({
  page,
}) => {
  const email = `wide${Date.now()}@${'x'.repeat(50)}.example.test`
  await gotoPage(page, '/profile')
  await register(page, email)
  await expect(page.getByTestId('profile-email')).toContainText(email)
  await page.setViewportSize({ width: 375, height: 812 })
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await clickSubmit(page.getByTestId('profile-sign-in-open'))
  await expect(page.getByTestId('identity-identifier')).toHaveText(email)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
})
