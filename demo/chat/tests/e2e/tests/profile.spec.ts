import { test, expect, type Page } from '@playwright/test'
import {
  PASSWORD,
  clickSubmit,
  typeInto,
  gotoPage,
  expectPageReady,
} from '../../../../../framework/frontend/e2e/index.js'

import { signUp } from '../helpers/session.js'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import {
  countSentFrames,
  expectProfileNameSnapshot,
  watchProfileRenameFrames,
} from '../../../../../framework/frontend/e2e/index.js'

/** Confirm a password-backed protected operation inside an open profile modal. */
async function confirmStepUp(page: Page, confirmId: string): Promise<void> {
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId(confirmId))
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

  const { name } = await signUp(expect, page)
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
    await clickSubmit(expect, page.getByTestId(`profile-${section}-open`))
    expect(new URL(page.url()).pathname).toBe(`/profile/${section}`)
    await expect(
      page.getByTestId('hilos-breadcrumb-hilos_profile'),
    ).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-breadcrumb-hilos_profile'),
    )
    await expect(page.getByTestId('profile-name')).toBeVisible()
  }
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('renames the current user through the edit modal', async ({ page }) => {
  await signUp(expect, page)
  await gotoPage(expect, page, '/profile')
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

for (const draft of ['Unsent name', 'X']) {
  test(`F5 restores the unsent ${draft.length === 1 ? 'invalid' : 'valid'} name draft without a name action`, async ({
    page,
  }) => {
    const wire = watchProfileRenameFrames(page)
    const sends = countSentFrames(page, 'hilos_profile_name_set')
    try {
      const user = await signUp(expect, page)
      await gotoPage(expect, page, '/profile')
      await clickSubmit(expect, page.getByTestId('profile-edit'))
      await confirmStepUp(page, 'profile-name-step-up-confirm')
      await typeInto(page.getByTestId('profile-name-input'), draft)
      const before = await expectProfileNameSnapshot(
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
        before.socket,
      )
      await expectPageReady(expect, page)
      await expect(page.getByTestId('profile-name-input')).toHaveValue(draft)
      await expect(page.getByTestId('profile-name')).toHaveText(user.name)
      await expect(page.getByTestId('conflict-badge')).toBeHidden()
      if (draft.length === 1)
        await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
      else await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
      await clickSubmit(expect, page.getByTestId('profile-rename-cancel'))
      await clickSubmit(expect, page.getByTestId('modal-confirm-discard'))
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
      await expect(page.getByTestId('modal')).toBeHidden()
      expect(sends()).toBe(0)
    } finally {
      await test.info().attach('unsent-name-wire', {
        body: JSON.stringify({ nameActions: sends(), ...wire }, null, 2),
        contentType: 'application/json',
      })
      wire.stop()
    }
  })
}

test('surfaces a conflict when the name changes in another tab', async ({
  context,
}) => {
  // Two tabs of the same user share the session cookie within one context, so
  // registering in the first tab signs both in.
  const tabA = await context.newPage()
  const wireA = watchProfileRenameFrames(tabA)
  const user = await signUp(expect, tabA)
  await gotoPage(expect, tabA, '/profile')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabA.getByTestId('profile-name')).toBeVisible()

  const tabB = await context.newPage()
  const wireB = watchProfileRenameFrames(tabB)
  await gotoPage(expect, tabB, '/profile')
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
  const done = await expectProfileNameSnapshot(expect, wireA, user.userId, {
    name: tabAName,
    phase: 'done',
  })
  await expectProfileNameSnapshot(expect, wireB, user.userId, {
    requestId: done.state.change!.requestId,
    phase: 'done',
  })
  await test.info().attach('two-tab-name-wire', {
    body: JSON.stringify({ tabA: wireA, tabB: wireB }, null, 2),
    contentType: 'application/json',
  })
  wireA.stop()
  wireB.stop()

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
  await signUp(expect, page)
  await gotoPage(expect, page, '/profile/devices')

  await expect(page.getByTestId('profile-devices')).toBeVisible()
  await expect(page.getByTestId('profile-devices-heading')).toHaveText(
    'Devices',
  )
})
