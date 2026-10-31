import { expect, test, type Page } from '@playwright/test'
import {
  PASSWORD,
  nameFromEmail,
  uniqueEmail,
  clickSubmit,
  typeInto,
  gotoPage,
  countSentFrames,
  deliveryObject,
  expectProfileNameSnapshot,
  watchProfileRenameFrames,
  expectPageReady,
  createTestUser,
} from '../../../../../framework/frontend/e2e/index.js'

import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'
import {
  dictateModelAnswer,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'

// spec-owner: demo — tasks' React profile mounts the shared name edit flow.

// The profile root (HIL-1169): the avatar in the header leads to /profile over
// the live socket. The Name row exposes Change through the shared rename flow.
// The framework list supplies the confirmed Email row. The rows are the
// sections of the catalog this demo serves, in catalog order — Ways to sign in,
// Security and Your data — each opens its own page, with Overview leading back.

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
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  await expect(page.getByTestId('profile-edit')).toBeVisible()
  await expect(page.getByTestId('profile-edit')).toBeEnabled()
  await expect(page.getByTestId('profile-identity-email')).toHaveText(email)
  await expect(page.getByTestId('profile-email')).toContainText(email)

  const sections = ['sign-in', 'security', 'data']
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

test('publishes a profile name only after the model approves it', async ({
  page,
}) => {
  const key = modelKey()
  const wire = watchProfileRenameFrames(page)
  const sends = countSentFrames(page, 'hilos_profile_name_set')
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
    await typeInto(page.getByTestId('profile-name-input'), newName)
    await dictateModelAnswer(key, JSON.stringify({ allow: true, reason: 'ok' }))
    // Keep the provider answer pending while checking the published identity.
    await dictateGatewayBehavior('/model/api/generate', key, { delayMs: 3000 })
    await clickSubmit(expect, page.getByTestId('profile-rename-save'))

    const checking = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      {
        name: newName,
        phase: 'checking',
      },
    )
    await expect
      .poll(() => readModelRequests(key))
      .toEqual([{ ordinal: 1, key, imageHashes: [] }])
    await expect(
      page
        .getByTestId('modal')
        .getByRole('status')
        .filter({ hasText: 'Checking your name…' }),
    ).toHaveText('Checking your name…')
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(user.name)
    await expect(page.getByTestId('nav-profile-name')).toHaveAccessibleName(
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
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(newName)
    await expect(page.getByTestId('nav-profile-name')).toHaveAccessibleName(
      newName,
    )

    const attempt = wire.snapshots.filter(
      ({ state }) => state.change?.name === newName,
    )
    const requestId = done.state.change!.requestId
    expect(sends()).toBe(1)
    expect(attempt.length).toBeGreaterThan(0)
    expect([
      ...new Set(attempt.map(({ state }) => state.change?.requestId)),
    ]).toEqual([requestId])
    expect(requestId).toBe(checking.state.change!.requestId)
    expect(attempt.map(({ state }) => state.change?.phase)).toContain(
      'checking',
    )
    expect(attempt.at(-1)?.state.change?.phase).toBe('done')
    expect(await readModelRequests(key)).toEqual([
      { ordinal: 1, key, imageHashes: [] },
    ])
  } finally {
    wire.stop()
    try {
      await test.info().attach('profile-name-wire', {
        body: JSON.stringify({ nameActions: sends(), ...wire }, null, 2),
        contentType: 'application/json',
      })
    } finally {
      await forgetModelKey(key)
    }
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
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(expect, page.getByTestId('profile-email-confirm-current'))

  const now = uniqueEmail()
  await typeInto(page.getByTestId('profile-email-new'), now)
  await clickSubmit(expect, page.getByTestId('profile-email-send-new'))
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
