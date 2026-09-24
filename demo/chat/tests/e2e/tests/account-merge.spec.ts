import { expect, test } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage } from '../helpers/page'
import {
  clickSubmit,
  login,
  PASSWORD,
  signUp,
  typeInto,
} from '../helpers/session'

/** The survivor's password after setup, distinct from the loser's default. */
const SURVIVOR_PASSWORD = 'the survivor password stays'

// The browser path through account merge (HIL-411): three people occupy three
// independent sessions, the loser publishes project-owned content, and the
// administrator folds it into the survivor from the framework user card. The
// assertions after the click cover both halves of the transaction: framework
// identities/sessions and the chat project's message rows.
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
  const survivorPage = await survivorContext.newPage()
  const loserPage = await loserContext.newPage()

  try {
    await signUpAdmin(page)
    const survivor = await signUp(survivorPage)

    // Give the survivor a secret distinct from the loser's. The post-merge
    // login through the loser's address can then prove which password survived.
    await gotoPage(survivorPage, '/profile')
    await typeInto(
      survivorPage.getByTestId('profile-password-current'),
      PASSWORD,
    )
    await typeInto(
      survivorPage.getByTestId('profile-password-new'),
      SURVIVOR_PASSWORD,
    )
    await typeInto(
      survivorPage.getByTestId('profile-password-confirm'),
      SURVIVOR_PASSWORD,
    )
    await clickSubmit(survivorPage.getByTestId('profile-password-save'))
    await expect(
      survivorPage.getByTestId('hilos-toast-success'),
    ).toContainText('Password changed.')
    await expect(
      survivorPage.getByTestId('profile-password-new'),
    ).toHaveValue('')

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

    await gotoPage(page, `/hilos/user/${survivor.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-merge-open'))
    await typeInto(page.getByTestId('hilos-table-search'), loser.email)
    const loserChoiceId = `hilos-user-merge-row-${loser.userId}`
    const loserChoice = shownByTestId(page, loserChoiceId)
    await expect(loserChoice).toBeVisible()
    await loserChoice.check()
    await clickSubmit(page.getByTestId('hilos-user-merge-next'))

    const survivorFate = page.getByTestId(
      'hilos-user-merge-fate-survivor',
    )
    const confirm = page.getByTestId('hilos-user-merge-confirm')
    await expect(survivorFate).toBeVisible()
    await expect(confirm).toBeDisabled()
    await survivorFate.check()
    await clickSubmit(confirm)

    const outcome = `Merged #${loser.userId} into #${survivor.userId}. Moved: sign-in methods 1, messages 1.`
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-toast-success')).toContainText(outcome)

    // Every live session of the tombstoned account is rebound to anonymous.
    await expect(loserPage.getByTestId('message-signin')).toBeVisible()
    await expect(loserPage.getByTestId('nav-profile')).toHaveCount(0)
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
    await login(loserPage, loser.email, SURVIVOR_PASSWORD)
    await expect(loserPage.getByTestId('self-user')).toHaveText(survivor.name)
    await expect(loserPage.getByTestId('self-user-id')).toHaveText(
      String(survivor.userId),
    )
  } finally {
    await survivorContext.close()
    await loserContext.close()
  }
})
