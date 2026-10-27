import { test, expect, type Page } from '@playwright/test'

import {
  countSentFrames,
  expectProfileNameSnapshot,
  openBell,
  unreadBadge,
  watchProfileRenameFrames,
  type ProfileRenameFrameLog,
} from '../../../../../framework/frontend/e2e/index.js'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { PASSWORD, clickSubmit, signUp, typeInto } from '../helpers/session.js'

// Framework profile-name moderation on a real call to the stand's model: a
// content refusal with an approved retry, an unavailable model, and an invalid
// reason. A permitting rename is also proven by profile.spec.ts.
//
// Framework ModerationAgent checks the PROFILE_NAME kind through the shared
// queue. Each test coins a key and puts it into the new name: the framework's
// name prompt carries that text verbatim, so the key reaches the stand gateway's
// model, which answers only what the spec dictated (helpers/moderation.ts).
// /test/reset is never called here — it would wipe other workers' dictations.
//
// Refusal words come from the backend. A content reason such as spam produces a
// rejection notice; an unavailable model or an invalid reason produces
// 'Names cannot be checked right now.' without a rejection notification.

/** Open rename and pass the password-backed protected-operation step. */
async function openRename(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId('profile-edit'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
}

test('a name the model refuses is not taken, and a retry it permits renames the author', async ({
  page,
}) => {
  const { name: oldName } = await signUp(page)
  // A cold load of /profile joins the notification group before the page
  // subscription, so a refusal notice will be delivered (see signUpJoined).
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  const key = modelKey()
  const newName = `Renamed ${key}`
  await dictateModerationVerdict(key, false, 'spam')

  await openRename(page)
  await typeInto(page.getByTestId('profile-name-input'), newName)
  await clickSubmit(page.getByTestId('profile-rename-save'))

  // The inline error is the settled state: the verdict is applied in one step, and the
  // refusal branch never commits the name — so the card and modal are judged after it.
  await expect(page.getByTestId('profile-rename-error')).toHaveText(
    'This name was not accepted because it appears to be spam.',
  )
  await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  // Re-submitting the same draft after dictating approval: the loading state was
  // cleared on refusal, the draft differs from the committed name, and the flow
  // clears the prior error.
  await dictateModerationVerdict(key, true, 'ok')
  await clickSubmit(page.getByTestId('profile-rename-save'))

  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(newName)

  // The bell is inspected only once the modal unmounts, since the backdrop covers
  // the navbar. Approval does not notify; the bell holds the rejection.
  await expect(unreadBadge(page)).toHaveText(/^1\b/)
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('Your new name was not accepted')
  await expect(menu).toContainText(
    'This name was not accepted because it appears to be spam.',
  )
  await expect(menu.getByTestId(/^hilos-notification-item-/)).toHaveCount(1)

  // A cold load of the chat room proves the event stream received the rename.
  // The filter isolates the event-notice row: the author label on events carries the
  // current name, so the registration notice would also match a key-filtered event row.
  await gotoPage(page, '/')
  const renameNotice = page.getByTestId('event-notice').filter({ hasText: key })
  await expect(renameNotice).toHaveCount(1)
  await expect(renameNotice).toHaveText(`renamed from ${oldName} to ${newName}`)
})

test('a rename nobody dictated a verdict for comes back as moderation unavailable', async ({
  page,
}) => {
  const { name: oldName } = await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  // No verdict is dictated: the model refuses the call with a 503, which for the
  // product is a model that is not answering.
  const key = modelKey()
  const newName = `Unmoderated ${key}`

  await openRename(page)
  await typeInto(page.getByTestId('profile-name-input'), newName)
  await clickSubmit(page.getByTestId('profile-rename-save'))

  await expect(page.getByTestId('profile-rename-error')).toHaveText(
    'Names cannot be checked right now.',
  )
  await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  // Check the draft before discarding it. The settled form can close through
  // Cancel; a fresh subscription then reads the current notification snapshot.
  await clickSubmit(page.getByTestId('profile-rename-cancel'))
  await clickSubmit(page.getByTestId('modal-confirm-discard'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await gotoPage(page, '/profile')
  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
})

test.describe('accepted name attempt lifetime', () => {
  // Four seconds leaves room for F5 and close/reopen, below the stand's
  // 20-second provider cap and 90-second check deadline. No browser sleep.
  const MODEL_DELAY_MS = 4_000
  let wire: ProfileRenameFrameLog
  let sends: () => number
  let starts: () => number

  test.beforeEach(({ page }) => {
    wire = watchProfileRenameFrames(page)
    sends = countSentFrames(page, 'hilos_profile_name_set')
    starts = countSentFrames(page, 'hilos_step_up_start')
  })

  test.afterEach(async () => {
    await test.info().attach('name-attempt-wire', {
      body: JSON.stringify(
        { nameActions: sends(), stepUpStarts: starts(), ...wire },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    wire.stop()
  })

  test('F5 restores an open checking window and the same attempt closes on owner success', async ({
    page,
    context,
  }) => {
    const user = await signUp(page)
    await gotoPage(page, '/profile')
    const key = modelKey()
    const name = `Reload ${key}`
    await dictateModerationVerdict(key, true, 'ok')
    await dictateGatewayBehavior('/model/api/generate', key, {
      delayMs: MODEL_DELAY_MS,
    })
    await openRename(page)
    await typeInto(page.getByTestId('profile-name-input'), name)
    await clickSubmit(page.getByTestId('profile-rename-save'))
    const accepted = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, phase: 'checking' },
    )
    const requestId = accepted.state.change!.requestId
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, requestId, phase: 'checking' },
      accepted.socket,
    )
    await expectPageReady(page)
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('profile-name-input')).toHaveValue(name)
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)

    // A page created by the context has no opener's cloned sessionStorage.
    const fresh = await context.newPage()
    const freshWire = watchProfileRenameFrames(fresh)
    try {
      await gotoPage(fresh, '/profile')
      await expectProfileNameSnapshot(expect, freshWire, user.userId, {
        name,
        requestId,
      })
      await expect(fresh.getByTestId('modal')).toBeHidden()
      await expectProfileNameSnapshot(expect, wire, user.userId, {
        name,
        requestId,
        phase: 'done',
      })
      await expect(page.getByTestId('modal')).toBeHidden()
      await expect(page.getByTestId('profile-name')).toHaveText(name)
      await expectProfileNameSnapshot(expect, freshWire, user.userId, {
        name,
        requestId,
        phase: 'done',
      })
      await expect(fresh.getByTestId('modal')).toBeHidden()
      await test.info().attach('new-tab-wire', {
        body: JSON.stringify(freshWire, null, 2),
        contentType: 'application/json',
      })
    } finally {
      freshWire.stop()
      await fresh.close()
    }
    expect(sends()).toBe(1)
    expect(
      new Set(
        wire.snapshots.flatMap(({ state }) =>
          state.change ? [state.change.requestId] : [],
        ),
      ).size,
    ).toBe(1)
    await gotoPage(page, '/')
    const notice = page.getByTestId('event-notice').filter({ hasText: key })
    await expect(notice).toHaveCount(1)
    await expect(notice).toHaveText(`renamed from ${user.name} to ${name}`)
    await test.info().attach('rename-event', {
      body: await notice.innerText(),
      contentType: 'text/plain',
    })
  })

  test('explicit close survives F5, pending reopen skips step-up, and late success stays closed', async ({
    page,
  }) => {
    const user = await signUp(page)
    await gotoPage(page, '/profile')
    const key = modelKey()
    const name = `Closed ${key}`
    await dictateModerationVerdict(key, true, 'ok')
    await dictateGatewayBehavior('/model/api/generate', key, {
      delayMs: MODEL_DELAY_MS,
    })
    await openRename(page)
    await typeInto(page.getByTestId('profile-name-input'), name)
    await clickSubmit(page.getByTestId('profile-rename-save'))
    const accepted = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, phase: 'checking' },
    )
    const requestId = accepted.state.change!.requestId
    const stepUpStarts = starts()
    await clickSubmit(page.getByTestId('profile-rename-cancel'))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { requestId, phase: 'checking' },
      accepted.socket,
    )
    await expectPageReady(page)
    await expect(page.getByTestId('modal')).toBeHidden()
    await clickSubmit(page.getByTestId('profile-edit'))
    await expect(page.getByTestId('profile-name-input')).toHaveValue(name)
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('step-up-password')).toHaveCount(0)
    expect(starts()).toBe(stepUpStarts)
    await clickSubmit(page.getByTestId('modal-close'))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await expectProfileNameSnapshot(expect, wire, user.userId, {
      requestId,
      phase: 'done',
    })
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('profile-name')).toHaveText(name)
    await clickSubmit(page.getByTestId('profile-edit'))
    await expect(page.getByTestId('profile-name-input')).toHaveValue(name)
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('profile-rename-error')).toBeHidden()
    await clickSubmit(page.getByTestId('profile-rename-cancel'))
    await expect(page.getByTestId('modal')).toBeHidden()
    expect(sends()).toBe(1)
    await gotoPage(page, '/')
    const notice = page.getByTestId('event-notice').filter({ hasText: key })
    await expect(notice).toHaveCount(1)
    await expect(notice).toHaveText(`renamed from ${user.name} to ${name}`)
  })

  test('background refusal stays closed and manual error with its requested name survives F5', async ({
    page,
  }) => {
    const user = await signUp(page)
    await gotoPage(page, '/profile')
    const key = modelKey()
    const name = `Refused ${key}`
    const refusal = 'This name was not accepted because it appears to be spam.'
    // This path only closes before the answer; unlike the F5 cases it needs no
    // four-second hold. Leave the existing poll room for async model delivery.
    const BACKGROUND_REFUSAL_DELAY_MS = 2_000
    await dictateModerationVerdict(key, false, 'spam')
    await dictateGatewayBehavior('/model/api/generate', key, {
      delayMs: BACKGROUND_REFUSAL_DELAY_MS,
    })
    await openRename(page)
    await typeInto(page.getByTestId('profile-name-input'), name)
    await clickSubmit(page.getByTestId('profile-rename-save'))
    const accepted = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, phase: 'checking' },
    )
    const requestId = accepted.state.change!.requestId
    const checkingObservedAt = Date.now()
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await clickSubmit(page.getByTestId('profile-rename-cancel'))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId('modal')).toBeHidden()
    const closedObservedAt = Date.now()
    const closedSnapshotCount = wire.snapshots.length
    // A fast refusal before closure would miss the background path entirely.
    expect(wire.snapshots.at(-1)).toEqual(accepted)
    const refused = await expectProfileNameSnapshot(expect, wire, user.userId, {
      name,
      requestId,
      phase: 'refused',
      refusal,
    })
    const refusalObservedAt = Date.now()
    expect(wire.snapshots.indexOf(refused)).toBeGreaterThanOrEqual(
      closedSnapshotCount,
    )
    await expect(page.getByTestId('modal')).toBeHidden()
    await test.info().attach('background-refusal-order', {
      body: JSON.stringify(
        {
          name,
          requestId,
          delayMs: BACKGROUND_REFUSAL_DELAY_MS,
          checkingObservedAt,
          closedObservedAt,
          refusalObservedAt,
          accepted,
          closedSnapshotCount,
          refusedSnapshotIndex: wire.snapshots.indexOf(refused),
          refused,
        },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    await clickSubmit(page.getByTestId('profile-edit'))
    await expect(page.getByTestId('profile-name-input')).toHaveValue(name)
    await expect(page.getByTestId('profile-rename-error')).toHaveText(refusal)
    await page.reload()
    const restored = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { requestId, phase: 'refused', refusal },
      accepted.socket,
    )
    await expectPageReady(page)
    await expect(page.getByTestId('profile-name-input')).toHaveValue(name)
    await expect(page.getByTestId('profile-rename-error')).toHaveText(refusal)
    await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await clickSubmit(page.getByTestId('profile-rename-cancel'))
    await clickSubmit(page.getByTestId('modal-confirm-discard'))
    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { requestId, phase: 'refused', refusal },
      restored.socket,
    )
    await expectPageReady(page)
    await expect(page.getByTestId('modal')).toBeHidden()
    expect(sends()).toBe(1)
    expect([
      ...new Set(
        wire.snapshots.flatMap(({ state }) =>
          state.change ? [state.change.requestId] : [],
        ),
      ),
    ]).toEqual([requestId])
  })

  test('late own success preserves a newer draft and rebases it without a false conflict', async ({
    page,
  }) => {
    const user = await signUp(page)
    await gotoPage(page, '/profile')
    const key = modelKey()
    const name = `First ${key}`
    const newer = `Unsent ${key}`
    await dictateModerationVerdict(key, true, 'ok')
    await dictateGatewayBehavior('/model/api/generate', key, {
      delayMs: MODEL_DELAY_MS,
    })
    await openRename(page)
    await typeInto(page.getByTestId('profile-name-input'), name)
    await clickSubmit(page.getByTestId('profile-rename-save'))
    const accepted = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, phase: 'checking' },
    )
    const requestId = accepted.state.change!.requestId
    await expect(page.getByTestId('profile-name-input')).toBeEditable()
    await typeInto(page.getByTestId('profile-name-input'), newer)
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await page.getByTestId('profile-name-input').press('Enter')
    await page.reload()
    await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { requestId, phase: 'checking' },
      accepted.socket,
    )
    await expectPageReady(page)
    await expect(page.getByTestId('profile-name-input')).toHaveValue(newer)
    await expectProfileNameSnapshot(expect, wire, user.userId, {
      requestId,
      phase: 'done',
    })
    await expect(page.getByTestId('profile-name')).toHaveText(name)
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(page.getByTestId('profile-name-input')).toHaveValue(newer)
    await expect(page.getByTestId('conflict-badge')).toBeHidden()
    await expect(
      page.getByTestId('profile-edit-notice-slot'),
    ).not.toContainText('Changed elsewhere')
    await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
    expect(sends()).toBe(1)
  })
})

test('a rename with an invalid model reason is unavailable and preserves the draft without a notification', async ({
  page,
}) => {
  const { name: oldName } = await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  const key = modelKey()
  const newName = `Blocked ${key}`
  await dictateModerationVerdict(key, false, 'blocked')

  await openRename(page)
  await typeInto(page.getByTestId('profile-name-input'), newName)
  await clickSubmit(page.getByTestId('profile-rename-save'))

  await expect(page.getByTestId('profile-rename-error')).toHaveText(
    'Names cannot be checked right now.',
  )
  await expect(page.getByTestId('profile-rename-save')).toBeEnabled()
  await expect(page.getByTestId('profile-rename-error')).not.toContainText(
    'blocked',
  )
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  await clickSubmit(page.getByTestId('profile-rename-cancel'))
  await clickSubmit(page.getByTestId('modal-confirm-discard'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await gotoPage(page, '/profile')
  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
})
