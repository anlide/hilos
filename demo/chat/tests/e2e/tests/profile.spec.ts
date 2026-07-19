import { test, expect } from '@playwright/test'

import { signUp } from '../helpers/session'

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
