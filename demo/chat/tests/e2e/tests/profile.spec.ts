import { test, expect, type Locator, type Page } from '@playwright/test'

import { PASSWORD, clickSubmit, register, signUp } from '../helpers/session'
import { gotoPage } from '../helpers/page'
import { dictateModerationVerdict } from '../helpers/moderation'
import { sidewaysOverflow } from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'

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
// rename-moderation.spec.ts. The push devices page remains here — push devices
// ride notification delivery.

test('the navbar links the current user to the profile page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const { name } = await signUp(page)
  const loadsAfterColdLoad = fullLoads

  await expect(page.getByTestId('nav-profile')).toBeVisible()
  await expect(
    page.getByTestId('nav-profile').getByTestId('hilos-avatar'),
  ).toHaveText(name.charAt(0).toUpperCase())
  await expect(page.getByTestId('nav-profile')).toHaveAccessibleName(name)
  await expect(page.getByTestId('nav-profile')).toHaveAttribute('title', name)
  await page.getByTestId('nav-profile').click()

  // Reached over the live socket with no document reload.
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(
    page.getByTestId('profile-identity').getByTestId('hilos-avatar'),
  ).toHaveText(name.charAt(0).toUpperCase())
  await expect(page.getByTestId('profile-identity-name')).toHaveText(name)
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
  // Nothing to save until the name differs from the live one.
  await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
  await expect(page.getByTestId('profile-rename-cancel')).toBeVisible()
  await typeInto(page.getByTestId('profile-name-input'), newName)
  await page.getByTestId('profile-rename-save').click()

  // The backend moderates (approved) and renames; the committed name lands over
  // the self-connection data, which closes the modal and updates the card.
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(newName)
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

  // Tab B's open modal sees the incoming change, flags a conflict, and says it on
  // its one line of messages; Merge is not offered for a name.
  await expect(tabB.getByTestId('conflict-badge')).toBeVisible()
  await expect(tabB.getByTestId('profile-edit-notice')).toContainText(
    'Changed elsewhere to',
  )
  await expect(tabB.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(tabB.getByTestId('profile-rename-save')).toBeDisabled()

  // Taking theirs adopts Tab A's name, clears the conflict, and leaves nothing to
  // save.
  await tabB.getByTestId('conflict-accept-theirs').click()
  await expect(tabB.getByTestId('conflict-badge')).toBeHidden()
  await expect(tabB.getByTestId('profile-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(tabB.getByTestId('profile-rename-save')).toBeDisabled()

  await tabB.getByTestId('profile-rename-cancel').click()
  await expect(tabB.getByTestId('modal')).toBeHidden()
})

test('opens the push devices page', async ({ page }) => {
  await signUp(page)
  await gotoPage(page, '/profile/devices')

  await expect(page.getByTestId('profile-devices')).toBeVisible()
  await expect(page.getByTestId('profile-devices-heading')).toHaveText(
    'Devices',
  )
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
