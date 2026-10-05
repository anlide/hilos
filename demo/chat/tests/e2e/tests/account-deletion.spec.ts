// A person's own account deletion (HIL-302): the danger zone at the bottom of the
// profile, a window that asks for the operation's confirmation only when the
// account has something stronger than its address, a code to that address, the
// warning with the date in every open tab, and "Keep my account" in one press.
// Away from the profile the shell says the same (HIL-945): a strip under the
// navigation with the date and the days left, a ring by the header avatar, and
// the strip's own "Keep my account" that takes both away.
// The code is read from the stand mailbox, never a backdoor. Erasure in chat
// is covered by demo/chat/tests/Integration/AccountErasureTest.php; live erasure
// runs in the polls and tasks e2e suites through test:account:force-purge.
// The code-sent step of the window belongs to the session (HIL-1183): its
// tabs move together, and a reload resumes it when the person opens the window.
import { expect, test } from '@playwright/test'

import { waitForMailCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  registerEmailOnly,
  signUp,
  typeInto,
} from '../helpers/session'

/** The subject AccountDeletionMailTemplate sends the code under. */
const SUBJECT = 'Confirm deleting your account'

test('starts a deletion with a code and calls it off in one press, in every tab', async ({
  context,
  page,
}) => {
  const email = await registerEmailOnly(page)
  const other = await context.newPage()
  await gotoPage(other, '/profile')
  await expect(other.getByTestId('account-deletion-open')).toBeVisible()

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-modal')).toContainText(
    '30 days',
  )
  await expect(page.getByTestId('step-up')).toHaveCount(0)
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  const code = await waitForMailCode(email, SUBJECT)

  await typeInto(page.getByTestId('account-deletion-code'), '000000')
  await clickSubmit(page.getByTestId('account-deletion-start'))
  await expect(page.getByTestId('account-deletion-error')).toHaveText(
    'Invalid or expired code',
  )

  await typeInto(page.getByTestId('account-deletion-code'), code)
  await clickSubmit(page.getByTestId('account-deletion-start'))
  await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
  await clickSubmit(page.getByTestId('account-deletion-close'))
  await expect(page.getByTestId('account-deletion-scheduled')).toContainText(
    'days left',
  )
  await expect(other.getByTestId('account-deletion-scheduled')).toBeVisible()

  // The strip on another page names the day the profile names and the days
  // left, and the ring by the header avatar carries the trash in the strip's
  // color.
  const zone = await page
    .getByTestId('account-deletion-scheduled')
    .textContent()
  const date = /deleted on (.+?) —/.exec(zone ?? '')?.[1]
  expect(date).toBeTruthy()
  await gotoPage(other, '/')
  await expect(other.getByTestId('account-deletion-strip-text')).toHaveText(
    `Your account will be deleted on ${date} — 30 days left`,
  )
  const ring = other.getByTestId('nav-profile').getByTestId('avatar-mark')
  await expect(ring).toHaveClass(/\bbi-trash\b/)
  await expect(ring).toHaveClass(/\btext-warning-emphasis\b/)

  // Keep in the strip calls the deletion off: the strip and the ring leave, and
  // the profile in the first tab is back to the plain danger zone.
  await clickSubmit(other.getByTestId('account-deletion-strip-keep'))
  await expect(other.getByTestId('account-deletion-strip')).toHaveCount(0)
  await expect(ring).toHaveCount(0)
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
  await expect(page.getByTestId('account-deletion-strip')).toHaveCount(0)
})

test('asks the password of a password account before anything else', async ({
  page,
}) => {
  const user = await signUp(page)
  await gotoPage(page, '/profile')

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('account-deletion-confirm'))
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  await waitForMailCode(user.email, SUBJECT)

  await expect(page.getByTestId('account-deletion-code')).toBeFocused()
  await clickSubmit(page.getByTestId('account-deletion-cancel'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
})

test('shares the deletion code step and closes other open windows on start and cancellation', async ({
  context,
  page,
}) => {
  const email = await registerEmailOnly(page)
  const other = await context.newPage()
  await gotoPage(other, '/profile')

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await clickSubmit(other.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-continue')).toBeVisible()
  await expect(other.getByTestId('account-deletion-continue')).toBeVisible()

  await clickSubmit(other.getByTestId('account-deletion-continue'))
  await expect(page.getByTestId('account-deletion-code')).toBeVisible()
  const code = await waitForMailCode(email, SUBJECT)
  await typeInto(page.getByTestId('account-deletion-code'), code)
  await clickSubmit(page.getByTestId('account-deletion-start'))
  await expect(page.getByTestId('account-deletion-keep')).toBeVisible()
  await expect(other.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(other.getByTestId('account-deletion-scheduled')).toBeVisible()

  await clickSubmit(other.getByTestId('account-deletion-manage'))
  await expect(other.getByTestId('account-deletion-keep')).toBeVisible()
  await clickSubmit(other.getByTestId('account-deletion-keep'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
  await expect(other.getByTestId('account-deletion-open')).toBeVisible()
})

test('resumes the deletion code step after reload and discards it on Cancel', async ({
  page,
}) => {
  await registerEmailOnly(page)
  await clickSubmit(page.getByTestId('account-deletion-open'))
  await clickSubmit(page.getByTestId('account-deletion-continue'))
  await expect(page.getByTestId('account-deletion-code')).toBeVisible()

  await page.reload()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await clickSubmit(page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-code')).toBeVisible()
  await clickSubmit(page.getByTestId('account-deletion-cancel'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)

  await clickSubmit(page.getByTestId('account-deletion-open'))
  await expect(page.getByTestId('account-deletion-continue')).toBeVisible()
})
