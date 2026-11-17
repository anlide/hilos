// A person's own account deletion (HIL-302): the danger zone at the bottom of the
// profile, a window that asks for the operation's confirmation only when the
// account has something stronger than its address, a code to that address, the
// warning with the date in every open tab, and "Keep my account" in one press.
// Away from the profile the shell says the same (HIL-945): a strip under the
// navigation with the date and the days left, and the strip's own "Keep my
// account" that calls the deletion off.
// The code is read from the stand mailbox, never a backdoor. Project erasure
// is covered by demo/chat/tests/Integration/AccountErasureTest.php and
// demo/polls/tests/Integration/AccountErasureTest.php. Live erasure and both
// tabs losing gated access are proved in
// demo/solar-system-model/tests/e2e/tests/account-deletion.spec.ts.
// The code-sent step of the window belongs to the session (HIL-1183): its
// tabs move together, and a reload resumes it when the person opens the window.
import { expect, test as base } from '../test.js'
import {
  PASSWORD,
  createTestUser,
  cleanupFixtures,
  type TestCleanup,
  clickSubmit,
  typeInto,
  gotoPage,
  endResendPause,
} from '../../../../../framework/frontend/e2e/index.js'

import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

/** The subject AccountDeletionMailTemplate sends the code under. */
const SUBJECT = 'Confirm deleting your account'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

test('starts, cancels and shares two passwordless deletion cycles', async ({
  context,
  page,
  cleanup,
}) => {
  const { email } = await createTestUser(expect, page, { passwordless: true })
  await gotoPage(expect, page, '/profile')
  const other = await context.newPage()
  cleanup.defer(() => other.close())
  await gotoPage(expect, other, '/profile')
  await expect(other.getByTestId('account-deletion-open')).toBeVisible()

  cleanup.defer(async () => {
    await gotoPage(expect, page, '/profile')
    if (await page.getByTestId('account-deletion-scheduled').isVisible()) {
      await clickSubmit(expect, page.getByTestId('account-deletion-manage'))
      await clickSubmit(expect, page.getByTestId('account-deletion-keep'))
      await expect(page.getByTestId('account-deletion-open')).toBeVisible()
    }
  })
  await test.step('starts a deletion with a code and calls it off in one press, in every tab', async () => {
    await clickSubmit(expect, page.getByTestId('account-deletion-open'))
    await expect(page.getByTestId('account-deletion-modal')).toContainText(
      '30 days',
    )
    await expect(page.getByTestId('step-up')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('account-deletion-continue'))
    const code = await waitForMailCode(email, SUBJECT)

    await typeInto(page.getByTestId('account-deletion-code'), '000000')
    await clickSubmit(expect, page.getByTestId('account-deletion-start'))
    await expect(page.getByTestId('account-deletion-error')).toHaveText(
      'Invalid or expired code',
    )

    await typeInto(page.getByTestId('account-deletion-code'), code)
    await clickSubmit(expect, page.getByTestId('account-deletion-start'))
    await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('account-deletion-close'))
    await expect(page.getByTestId('account-deletion-scheduled')).toContainText(
      'days left',
    )
    await expect(other.getByTestId('account-deletion-scheduled')).toBeVisible()

    // The strip on another page names the day the profile names and the days
    // left.
    const zone = await page
      .getByTestId('account-deletion-scheduled')
      .textContent()
    const date = /deleted on (.+?) —/.exec(zone ?? '')?.[1]
    expect(date).toBeTruthy()
    await gotoPage(expect, other, '/')
    await expect(other.getByTestId('account-deletion-strip-text')).toHaveText(
      `Your account will be deleted on ${date} — 30 days left`,
    )

    // Keep in the strip calls the deletion off: the strip leaves, and the
    // profile in the first tab offers Delete again.
    await clickSubmit(expect, other.getByTestId('account-deletion-strip-keep'))
    await expect(other.getByTestId('account-deletion-strip')).toHaveCount(0)
    await expect(page.getByTestId('account-deletion-open')).toBeVisible()
    await expect(page.getByTestId('account-deletion-strip')).toHaveCount(0)
  })

  await endResendPause(page, email)
  await gotoPage(expect, other, '/profile')

  await test.step('shares the deletion code step and closes other open windows on start and cancellation', async () => {
    await clickSubmit(expect, page.getByTestId('account-deletion-open'))
    await clickSubmit(expect, other.getByTestId('account-deletion-open'))
    await expect(page.getByTestId('account-deletion-continue')).toBeVisible()
    await expect(other.getByTestId('account-deletion-continue')).toBeVisible()

    await clickSubmit(expect, other.getByTestId('account-deletion-continue'))
    await expect(page.getByTestId('account-deletion-code')).toBeVisible()
    const code = await waitForMailCode(email, SUBJECT, 2)
    await typeInto(page.getByTestId('account-deletion-code'), code)
    await clickSubmit(expect, page.getByTestId('account-deletion-start'))
    await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
    await expect(other.getByTestId('account-deletion-modal')).toHaveCount(0)
    await expect(other.getByTestId('account-deletion-scheduled')).toBeVisible()

    await clickSubmit(expect, other.getByTestId('account-deletion-manage'))
    await expect(other.getByTestId('account-deletion-keep')).toBeVisible()
    await clickSubmit(expect, other.getByTestId('account-deletion-keep'))
    await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
    await expect(page.getByTestId('account-deletion-open')).toBeVisible()
    await expect(other.getByTestId('account-deletion-open')).toBeVisible()
  })
})

test('asks the password of a password account before anything else', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')

  await clickSubmit(expect, page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('account-deletion-confirm'))
  await clickSubmit(expect, page.getByTestId('account-deletion-continue'))
  await waitForMailCode(user.email, SUBJECT)

  await expect(page.getByTestId('account-deletion-code')).toBeFocused()
  await clickSubmit(expect, page.getByTestId('account-deletion-cancel'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
})

test('resumes the deletion code step after reload and discards it on Cancel', async ({
  page,
}) => {
  await createTestUser(expect, page, { passwordless: true })
  await gotoPage(expect, page, '/profile')
  await clickSubmit(expect, page.getByTestId('account-deletion-open'))
  await clickSubmit(expect, page.getByTestId('account-deletion-continue'))
  await expect(page.getByTestId('account-deletion-code')).toBeVisible()

  await page.reload()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await clickSubmit(expect, page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-code')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('account-deletion-cancel'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)

  await clickSubmit(expect, page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-continue')).toBeVisible()
})
