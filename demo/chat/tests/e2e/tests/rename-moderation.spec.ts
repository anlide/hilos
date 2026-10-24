import { test, expect, type Page } from '@playwright/test'

import {
  openBell,
  unreadBadge,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { gotoPage } from '../helpers/page.js'
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
