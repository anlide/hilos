// The profile root (HIL-1169): the avatar in the header leads to /profile over
// the live socket. The person's own line carries the session name; Name has no
// Change, because this demo hands the page no rename of its own; there is no
// Email row, because it keeps no list of ways in. The rows are the sections of
// the catalog this demo serves, in catalog order — Security, then Your data —
// and each opens its page, whose "Profile" crumb now leads back.
import { expect, test } from '@playwright/test'

import { gotoPage } from '../helpers/page.js'
import {
  clickSubmit,
  nameFromEmail,
  openSignIn,
  register,
  uniqueEmail,
} from '../helpers/session.js'

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
  await expect(page.getByTestId('profile-email')).toHaveCount(0)

  const sections = ['security', 'data']
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
