import { test, expect } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { addToCircle, clearCircle } from '../helpers/protectedMode'
import { signUpWithVerifiedEmail } from '../helpers/session'

// Maintenance section e2e (/hilos/maintenance, HIL-1119): the one thing the section
// promises on top of the list itself — the mark of whether a named person is signed in
// is live. The seam it crosses is the one no unit test does: the member's socket closes
// in one process, the section is served by the hilos index agent in another, and the
// row has to be re-drawn on the administrator's open page from that closing alone.
//
// The circle is still named and emptied on the backup page, where its add and remove
// modals live until HIL-1120 and HIL-1121 bring them here.

const BACKUP_URL = '/hilos/backup'

const MAINTENANCE_URL = '/hilos/maintenance'

// The two words of the section's presence column.
const CIRCLE_ONLINE = 'signed in'
const CIRCLE_OFFLINE = 'not signed in'

test('the signed-in mark of a circle member follows their tab live', async ({
  page,
  browser,
}) => {
  // The circle is a durable list that outlives a case, so the case owns it first: a
  // member some other case left named would be one more row to read around.
  await signUpAdmin(page)
  await gotoPage(page, BACKUP_URL)
  await clearCircle(page)

  // Named with a PROVEN address: naming resolves the address against the confirmed
  // identities, and plain registration leaves it unverified.
  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const { email: memberEmail } = await signUpWithVerifiedEmail(member)
  await addToCircle(page, memberEmail)

  await gotoPage(member, '/')
  await expect(member.getByTestId('conn-state')).toHaveText('connected')

  await gotoPage(page, MAINTENANCE_URL)
  await expect(
    page.getByTestId('hilos-maintenance-circle-panel'),
  ).toBeVisible()
  await expect(
    page.getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()
  const mark = page.getByTestId(
    `hilos-maintenance-circle-online-${memberEmail}`,
  )
  await expect(mark).toHaveText(CIRCLE_ONLINE)

  // The member leaves, and the administrator's page is not touched at all: no
  // navigation and no reload. Whatever turns the mark now arrived over the socket,
  // re-drawn from the closing of the member's connection.
  await memberContext.close()
  await expect(mark).toHaveText(CIRCLE_OFFLINE)

  await gotoPage(page, BACKUP_URL)
  await clearCircle(page)
})
