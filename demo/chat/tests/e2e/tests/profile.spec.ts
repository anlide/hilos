import { test, expect, type Locator } from '@playwright/test'

import { PASSWORD, signUp } from '../helpers/session'

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
// data and closes the modal; the test moderation client always approves, so a
// reject path is covered by the backend integration test, not here.

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
  await page.goto('/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  await page.getByTestId('profile-edit').click()
  await page.getByTestId('profile-name-input').fill('Renamed Person')
  await page.getByTestId('profile-rename-save').click()

  // The backend moderates (approved) and renames; the committed name lands over
  // the self-connection data, which closes the modal and updates the card.
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText('Renamed Person')
})

test('links a GitHub account to the current profile (HIL-401)', async ({
  page,
}) => {
  // Link mode reuses the whole OAuth flow but attaches the identity to the
  // already-signed-in account instead of resolving one. With no real GitHub app
  // configured the offline stub answers under `oauth:github`: its authorize URL
  // bounces straight back to /auth/callback, the framework agent binds the
  // identity, and the explicit link-ok result returns the user to their profile.
  await signUp(page)
  await page.goto('/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  // A fresh password account offers GitHub to link and has no oauth identity yet.
  const linkButton = page.getByTestId('profile-oauth-link-oauth:github')
  await expect(linkButton).toBeVisible()
  await expect(page.getByTestId('profile-identities-list')).not.toContainText(
    'oauth:github',
  )

  await linkButton.click()

  // The link resolves out of band (link-ok signal) and navigates back to /profile;
  // the new method now shows and GitHub is no longer offered to link.
  await expect(page).toHaveURL(/\/profile$/)
  await expect(page.getByTestId('profile-identities-list')).toContainText(
    'oauth:github',
  )
  await expect(
    page.getByTestId('profile-oauth-link-oauth:github'),
  ).toHaveCount(0)
})

test('surfaces a conflict when the name changes in another tab', async ({
  context,
}) => {
  // Two tabs of the same user share the session cookie within one context, so
  // registering in the first tab signs both in.
  const tabA = await context.newPage()
  await signUp(tabA)
  await tabA.goto('/profile')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabA.getByTestId('profile-name')).toBeVisible()

  const tabB = await context.newPage()
  await tabB.goto('/profile')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('profile-name')).toBeVisible()

  // Tab B starts editing with a divergent draft, but does not submit.
  await tabB.getByTestId('profile-edit').click()
  await tabB.getByTestId('profile-name-input').fill('Tab B Name')

  // Tab A renames the same user.
  await tabA.getByTestId('profile-edit').click()
  await tabA.getByTestId('profile-name-input').fill('Tab A Name')
  await tabA.getByTestId('profile-rename-save').click()
  await expect(tabA.getByTestId('profile-name')).toHaveText('Tab A Name')

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
  await page.goto('/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-password-current')).toBeVisible()

  const newPassword = 'a-fresh-passphrase'

  // The wrong current password is refused with an inline error; no success toast.
  await typeInto(page.getByTestId('profile-password-current'), 'not the password')
  await typeInto(page.getByTestId('profile-password-new'), newPassword)
  await typeInto(page.getByTestId('profile-password-confirm'), newPassword)
  await page.getByTestId('profile-password-save').click()
  await expect(page.getByTestId('profile-set-password-error')).toBeVisible()
  await expect(page.getByTestId('profile-password-updated')).toHaveCount(0)

  // The correct current password updates it: the success toast lands (over the
  // password_updated signal, not a projection change) and the fields clear.
  await typeInto(page.getByTestId('profile-password-current'), PASSWORD)
  await page.getByTestId('profile-password-save').click()
  await expect(page.getByTestId('profile-password-updated')).toBeVisible()
  await expect(page.getByTestId('profile-set-password-error')).toHaveCount(0)
  await expect(page.getByTestId('profile-password-new')).toHaveValue('')
})
