import { expect, test } from '@playwright/test'

import {
  connectFirstApp,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { gotoPage } from '../helpers/page.js'
import {
  clickSubmit,
  changePassword,
  enterIdentifierAndPassword,
  PASSWORD,
  signUp,
  typeInto,
} from '../helpers/session.js'

// spec-owner: demo — verifies that the merge reassigns the chat message to the survivor.

/** The survivor's password after setup, distinct from the loser's default. */
const SURVIVOR_PASSWORD = 'the survivor password stays'

// The browser path through account merge (HIL-411): three people occupy three
// independent sessions, the loser publishes project-owned content, and the
// administrator folds it into the survivor from the framework user card. The
// assertions after the click cover both halves of the transaction: framework
// identities/sessions and the chat project's message rows - and the two open
// surfaces of the merged account (HIL-1292): the administrator's second tab on
// its card, and a guest on its public page, both told where it went without a
// reload. Another tab of the same administrator, already on that survivor's
// merge, is told the candidate is no longer available (HIL-1294).
test('merges another account into the user on the admin card', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const survivorContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const loserContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const survivorPage = await survivorContext.newPage()
  const loserPage = await loserContext.newPage()
  const guestPage = await guestContext.newPage()
  // The administrator's second tab, on the card of the account about to be merged.
  const loserCardPage = await page.context().newPage()
  // The same administrator's other tab, on the survivor, held on the merge (HIL-1294).
  const survivorCardPage = await page.context().newPage()

  try {
    await signUpAdmin(page)
    const survivor = await signUp(survivorPage)

    // Give the survivor a secret distinct from the loser's. The post-merge
    // login through the loser's address can then prove which password survived.
    await changePassword(survivorPage, {
      email: survivor.email,
      currentPassword: PASSWORD,
      newPassword: SURVIVOR_PASSWORD,
    })
    await clickSubmit(survivorPage.getByTestId('profile-password-done'))
    await expect(
      survivorPage.getByTestId('profile-password-modal'),
    ).toHaveCount(0)

    const loser = await signUp(loserPage)
    const key = modelKey()
    const message = `message that survives account merge ${key}`
    await dictateModerationVerdict(key, true, 'ok')
    await typeInto(loserPage.getByTestId('message-input'), message)
    await clickSubmit(loserPage.getByTestId('message-send'))
    const loserEvent = loserPage.getByTestId('event').filter({
      has: loserPage.getByTestId('event-text').filter({ hasText: message }),
    })
    await expect(loserEvent).toBeVisible()
    await expect(loserEvent.getByTestId('event-author')).toHaveText(loser.name)

    // Two surfaces of the loser stay open through the merge: the administrator's
    // card of it, with every control, and a guest's public page of it.
    await gotoPage(loserCardPage, `/hilos/user/${loser.userId}`)
    await expect(loserCardPage.getByTestId('hilos-user-edit')).toBeVisible()
    await expect(
      loserCardPage.getByTestId('hilos-user-merge-zone'),
    ).toBeVisible()
    await gotoPage(guestPage, `/user/${loser.userId}`)
    await expect(guestPage.getByTestId('user-name')).toHaveText(loser.name)
    await expect(guestPage.getByTestId('user-sessions')).toBeVisible()

    await gotoPage(page, `/hilos/user/${survivor.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-merge-open'))
    // The merge cannot be undone, so the administrator confirms it is them
    // first, with their own password (HIL-1275).
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(page.getByTestId('hilos-user-merge-step-up-confirm'))
    await typeInto(page.getByTestId('hilos-table-search'), loser.email)
    const loserChoiceId = `hilos-user-merge-row-${loser.userId}`
    const loserChoice = shownByTestId(page, loserChoiceId)
    await expect(loserChoice).toBeVisible()
    await loserChoice.check()
    await clickSubmit(page.getByTestId('hilos-user-merge-next'))

    // The other tab reaches the same step while this one still holds the merge.
    // The confirmation already given on this session is not asked again (HIL-1330).
    await gotoPage(survivorCardPage, `/hilos/user/${survivor.userId}`)
    await clickSubmit(survivorCardPage.getByTestId('hilos-user-merge-open'))
    await expect(survivorCardPage.getByTestId('step-up-password')).toHaveCount(
      0,
    )
    await typeInto(
      survivorCardPage.getByTestId('hilos-table-search'),
      loser.email,
    )
    const survivorTabChoice = shownByTestId(survivorCardPage, loserChoiceId)
    await expect(survivorTabChoice).toBeVisible()
    await survivorTabChoice.check()
    await clickSubmit(survivorCardPage.getByTestId('hilos-user-merge-next'))

    const survivorFate = page.getByTestId('hilos-user-merge-fate-survivor')
    const confirm = page.getByTestId('hilos-user-merge-confirm')
    await expect(survivorFate).toBeVisible()
    await expect(confirm).toBeDisabled()
    await survivorFate.check()
    await expect(confirm).toBeEnabled()

    // Enrollment in the other browser changes the open summary without a reload.
    await gotoPage(loserPage, '/profile/security')
    const app = await connectFirstApp(loserPage, PASSWORD)
    await gotoPage(loserPage, '/')
    const protection = page.getByTestId('hilos-user-merge-second-factor-both')
    await expect(protection).toBeVisible()
    await expect(
      page.getByTestId('hilos-user-merge-second-factor-survivor'),
    ).toHaveCount(0)
    await expect(confirm).toBeDisabled()
    await protection.check()
    await clickSubmit(confirm)

    const outcome = `Merged #${loser.userId} into #${survivor.userId}. Moved: sign-in methods 1, messages 1.`
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(outcome)

    // The other tab was not reloaded: the candidate it had chosen is gone, and
    // Merge cannot be pressed (HIL-1294).
    await expect(
      survivorCardPage.getByTestId('hilos-user-merge-gone'),
    ).toHaveText('No longer available')
    await expect(
      survivorCardPage.getByTestId('hilos-user-merge-confirm'),
    ).toBeDisabled()

    // The open card of the merged account turns without a reload (HIL-1292): the
    // badge says Merged, the notice leads to the survivor's card, and nothing is
    // left to press.
    await expect(loserCardPage.getByTestId('user-standing-badge')).toHaveText(
      'Merged',
    )
    await expect(
      loserCardPage.getByTestId('hilos-user-merged-link'),
    ).toHaveAttribute('href', `/hilos/user/${survivor.userId}`)
    await expect(loserCardPage.getByTestId('hilos-user-edit')).toHaveCount(0)
    await expect(
      loserCardPage.getByTestId('hilos-user-merge-zone'),
    ).toHaveCount(0)
    // So does the guest's public page: the survivor by name and by the way to
    // their page, and no sessions of its own any more.
    await expect(guestPage.getByTestId('user-merged-badge')).toBeVisible()
    await expect(guestPage.getByTestId('user-merged-into-link')).toHaveText(
      survivor.name,
    )
    await expect(
      guestPage.getByTestId('user-merged-into-link'),
    ).toHaveAttribute('href', `/user/${survivor.userId}`)
    await expect(guestPage.getByTestId('user-sessions')).toHaveCount(0)

    // Every live session of the tombstoned account is rebound to anonymous.
    await expect(loserPage.getByTestId('message-signin')).toBeVisible()
    await expect(loserPage.getByTestId('nav-profile')).toHaveCount(0)
    await expect(survivorPage.getByTestId('auth-surface')).toBeVisible()
    await expect(loserPage.getByTestId('self-user')).toHaveCount(0)

    // A fresh candidate subscription and a settled search no longer contain the
    // tombstone, rather than merely retaining a stale row from the first modal.
    await clickSubmit(page.getByTestId('hilos-user-merge-open'))
    await typeInto(page.getByTestId('hilos-table-search'), loser.email)
    await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
    await expect(page.getByTestId(loserChoiceId)).toHaveCount(0)
    await page.getByTestId('hilos-user-merge-cancel').click()
    await expect(page.getByTestId('modal')).toBeHidden()

    // The project-owned row moved with the account and resolves its author from
    // the survivor after a new main-page subscription.
    await gotoPage(page, '/')
    const mergedEvent = page.getByTestId('event').filter({
      has: page.getByTestId('event-text').filter({ hasText: message }),
    })
    await expect(mergedEvent).toBeVisible()
    await expect(mergedEvent.getByTestId('event-author')).toHaveText(
      survivor.name,
    )

    // The loser's proven address moved too, but the chosen survivor password is
    // now the account-wide secret behind either address.
    await loserPage.getByTestId('message-signin').click()
    await enterIdentifierAndPassword(loserPage, loser.email, SURVIVOR_PASSWORD)
    await clickSubmit(loserPage.getByTestId('auth-submit'))
    await expect(loserPage.getByTestId('auth-heading')).toHaveText(
      'Two-step verification',
    )
    await loserPage.getByTestId('auth-backup-toggle').click()
    await typeInto(loserPage.getByTestId('auth-code'), app.backupCodes[0]!)
    await clickSubmit(loserPage.getByTestId('auth-submit'))
    await expect(loserPage.getByTestId('auth-surface')).toHaveCount(0)
    await expect(loserPage.getByTestId('self-user')).toHaveText(survivor.name)
    await expect(loserPage.getByTestId('self-user-id')).toHaveText(
      String(survivor.userId),
    )
  } finally {
    await survivorCardPage.close()
    await loserCardPage.close()
    await guestContext.close()
    await survivorContext.close()
    await loserContext.close()
  }
})
