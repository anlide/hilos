import { test, expect, type Page } from '@playwright/test'

import {
  openBell,
  unreadBadge,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage } from '../helpers/page'
import { PASSWORD, clickSubmit, signUp, typeInto } from '../helpers/session'

// spec-owner: demo — chat's moderator judging a new name

/** Open rename and pass the password-backed protected-operation step. */
async function openRename(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId('profile-edit'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
}

// Rename moderation on a real call to the stand's model (HIL-928): a refusal
// with a retry, and a model that does not answer. A permitting rename is
// proven end to end by profile.spec.ts (HIL-927); this spec covers the
// negative branches.
//
// The moderator agent asks the stand gateway's model, and the model says only
// what a spec dictated (helpers/moderation.ts). Each test coins its own key
// and puts it into the new name because the moderator prompt carries that
// name verbatim (ModeratorAgent.php), so the key reaches the model; it also
// disturbs no other spec. /test/reset is never called here — it would wipe
// what other workers dictated.
//
// On screen the refusal reason is the backend's words: a human-readable phrase
// ('This name was not accepted because it appears to be spam.', 'Names cannot be checked right now.'),
// replacing raw machine error codes. That is intended.

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
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)

  // Re-submitting the same draft after dictating approval: the loading state was
  // cleared on refusal, the draft differs from the committed name, and sendRename
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

  // A cold load of the chat room proves the event stream received the rename.
  // The filter isolates the event-notice row: the author label on events carries the
  // current name, so the registration notice would also match a key-filtered event row.
  await gotoPage(page, '/')
  await expect(
    page.getByTestId('event-notice').filter({ hasText: key }),
  ).toHaveText(`renamed from ${oldName} to ${newName}`)
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
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)
})

test('a rename refused without a reason shows generic acceptance failure and preserves draft', async ({
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
    'This name was not accepted.',
  )
  await expect(page.getByTestId('profile-rename-error')).not.toContainText(
    'blocked',
  )
  await expect(page.getByTestId('modal')).toBeVisible()
  await expect(page.getByTestId('profile-name-input')).toHaveValue(newName)
  await expect(page.getByTestId('profile-name')).toHaveText(oldName)
})
