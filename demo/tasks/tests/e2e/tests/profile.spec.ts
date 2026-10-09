// The profile root (HIL-1169): the avatar in the header leads to /profile over
// the live socket. The person's own line carries the session name; Name has no
// Change, because this demo hands the page no rename of its own. The framework
// list supplies the confirmed Email row. The rows are the sections of the
// catalog this demo serves, in catalog order — Ways to sign in, Security, then
// Your data — and each opens its page, whose "Profile" crumb now leads back.
import { expect, test, type Page } from '@playwright/test'

import { waitForMailCode, waitForMailTo } from '../helpers/mail.js'
import { gotoPage } from '../helpers/page.js'
import {
  PASSWORD,
  clickSubmit,
  nameFromEmail,
  openSignIn,
  register,
  typeInto,
  uniqueEmail,
} from '../helpers/session.js'

/** Confirm a password-backed operation inside the open profile dialog. */
async function confirmStepUp(page: Page, confirmId: string): Promise<void> {
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId(confirmId))
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
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const loadsAfterSignIn = fullLoads

  await clickSubmit(page.getByTestId('nav-profile-name'))
  await expect(page.getByTestId('profile-identity-name')).toHaveText(name)
  expect(new URL(page.url()).pathname).toBe('/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  await expect(page.getByTestId('profile-edit')).toHaveCount(0)
  await expect(page.getByTestId('profile-identity-email')).toHaveText(email)
  await expect(page.getByTestId('profile-email')).toContainText(email)

  const sections = ['sign-in', 'security', 'data']
  await expect(page.getByTestId('profile-section')).toHaveCount(sections.length)
  expect(
    await page
      .getByTestId(/^profile-[a-z-]+-open$/)
      .evaluateAll((links) =>
        links.map((link) => link.getAttribute('data-id')),
      ),
  ).toEqual(sections.map((section) => `profile-${section}-open`))
  for (const section of sections) {
    await clickSubmit(page.getByTestId(`profile-${section}-open`))
    await expect(page).toHaveURL(new RegExp(`/profile/${section}$`))
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await clickSubmit(page.getByTestId('hilos-breadcrumb-hilos_profile'))
    await expect(page.getByTestId('profile-name')).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/profile')
  }
  expect(fullLoads).toBe(loadsAfterSignIn)
})

test('changes the account email in five steps (HIL-1277)', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const was = uniqueEmail()
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, was)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-email')).toContainText(was)
  const loadsBeforeChange = fullLoads

  await page.getByTestId('profile-email-change').click()
  await confirmStepUp(page, 'profile-email-step-up-confirm')
  await clickSubmit(page.getByTestId('profile-email-send-current'))
  const currentCode = await waitForMailCode(
    was,
    'Confirm it is you to change your email address',
  )
  await typeInto(page.getByTestId('profile-email-code-current'), currentCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-current'))

  const now = uniqueEmail()
  await typeInto(page.getByTestId('profile-email-new'), now)
  await clickSubmit(page.getByTestId('profile-email-send-new'))
  const newCode = await waitForMailCode(now, 'Confirm your new email address')
  await typeInto(page.getByTestId('profile-email-code-new'), newCode)
  await clickSubmit(page.getByTestId('profile-email-confirm-new'))

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
