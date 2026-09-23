import { test, expect, type Locator } from '@playwright/test'

import { waitForMailCode, waitForMailTo } from '../helpers/mail'
import { PASSWORD, clickSubmit, signUp, uniqueEmail } from '../helpers/session'
import { gotoPage } from '../helpers/page'
import { modelKey } from '../helpers/model'
import { dictateModerationVerdict } from '../helpers/moderation'
import { declareOAuthAccount } from '../helpers/oauth'
import { signInAs } from '../helpers/oauth-user'

// Type into a Vue input the way a user does — clear, then key by key — so the
// reactivity a bare fill() can miss actually fires (see helpers/session).
async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
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
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  const loadsBeforeLink = fullLoads

  // A fresh password account offers GitHub to link and has no oauth identity yet.
  const linkButton = page.getByTestId('profile-oauth-link-oauth:github')
  await expect(linkButton).toBeVisible()
  await expect(page.getByTestId('profile-identities-list')).not.toContainText(
    'oauth:github',
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
    'oauth:github',
    { timeout: 30000 },
  )
  await expect(
    page.getByTestId('profile-oauth-link-oauth:github'),
  ).toHaveCount(0)
  await expect(page.getByTestId('auth-oauth-wait')).toHaveCount(0)

  // The whole trip happened beside this page, not through it: still /profile, and
  // not one document load since the profile opened.
  expect(new URL(page.url()).pathname).toBe('/profile')
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
  await tabB.getByTestId('profile-name-input').fill('Tab B Name')

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

test('changes the current user password from the profile (HIL-402)', async ({
  page,
}) => {
  // A registered account already has a password, so the profile shows the Change
  // form (current + new). The change re-auths the current password server-side.
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-password-current')).toBeVisible()

  const newPassword = 'a-fresh-passphrase'

  // The wrong current password is refused with an inline error; no success toast.
  await typeInto(page.getByTestId('profile-password-current'), 'not the password')
  await typeInto(page.getByTestId('profile-password-new'), newPassword)
  await typeInto(page.getByTestId('profile-password-confirm'), newPassword)
  await page.getByTestId('profile-password-save').click()
  await expect(page.getByTestId('profile-set-password-error')).toBeVisible()
  await expect(
    page.getByTestId('hilos-toasts').getByText('Password changed.'),
  ).toHaveCount(0)

  // The correct current password updates it: the success toast lands (over the
  // password_updated signal, not a projection change) and the fields clear.
  await typeInto(page.getByTestId('profile-password-current'), PASSWORD)
  await page.getByTestId('profile-password-save').click()
  await expect(
    page.getByTestId('hilos-toasts').getByText('Password changed.'),
  ).toBeVisible()
  await expect(page.getByTestId('profile-set-password-error')).toHaveCount(0)
  await expect(page.getByTestId('profile-password-new')).toHaveValue('')
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
