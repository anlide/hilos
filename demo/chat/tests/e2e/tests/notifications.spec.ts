import { test, expect, type Browser, type Page } from '@playwright/test'

import {
  openBell,
  unreadBadge,
} from '../../../../../framework/frontend/e2e/index.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant'
import { dictateModerationVerdict } from '../helpers/moderation'
import { gotoPage } from '../helpers/page'
import { clickSubmit, signUp, typeInto } from '../helpers/session'

// spec-owner: demo — notifications raised by chat's own events

// The product half of the notification line in chat (HIL-557, HIL-1196): a
// domain event of this demo raises a notification — a mention in a message, an
// administrator's rename that the room's feed also shows. The notification
// center itself, the delivery channels and their journal are the framework's and
// are proven in binance-btc-tracker (HIL-1224), where a notification is planted
// over the command channel; here every row is raised by the product.

/**
 * Open a second person in a browser context of its own.
 *
 * Identity rides the session cookie, so a second tab of one context is the same
 * account, and two different people therefore need two contexts. A context made
 * off the browser fixture inherits none of the project's `use` options, including
 * the tolerance for the self-signed certificate the test nginx serves, so they
 * are passed by hand.
 *
 * @param browser The browser the test runs in.
 * @returns A page belonging to a fresh, anonymous visitor.
 */
async function openSecondPerson(browser: Browser): Promise<Page> {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })

  return context.newPage()
}

test('a mention reaches the named user in another window', async ({
  page,
  browser,
}) => {
  const author = await signUp(page)

  // The recipient signs in and joins its notification group BEFORE the message is
  // published, so the mention arrives over the live signal (see signUpJoined in
  // helpers/notifications.ts for why a cold load is what makes the join waitable).
  const recipient = await openSecondPerson(browser)
  const { name: recipientName } = await signUp(recipient)
  await gotoPage(recipient, '/')

  // The message is moderated by the stand's model, which answers only what a spec
  // dictated: the key rides in the text, and the permission is ordered up front.
  const key = modelKey()
  await dictateModerationVerdict(key, true, 'ok')

  await typeInto(
    page.getByTestId('message-input'),
    `@${recipientName} could you look at this? ${key}`,
  )
  await clickSubmit(page.getByTestId('message-send'))

  // The message is published only once moderation allows it, and the mention is
  // raised inside that same write - so the author's own feed row and the
  // recipient's badge are the two ends of one round trip.
  await expect(
    page.getByTestId('event-text').filter({ hasText: recipientName }),
  ).toBeVisible()
  await expect(unreadBadge(recipient)).toHaveText(/^1\b/)

  // The product emit never hands the id out, so the row is found by its own
  // title rather than by hilos-notification-item-<id>.
  await openBell(recipient)
  const menu = recipient.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText(`${author.name} mentioned you`)
  await expect(menu).toContainText(`@${recipientName} could you look at this?`)

  await recipient.context().close()
})

test('an administrator renaming somebody reaches the renamed person and the room', async ({
  page,
  browser,
}) => {
  // This browser is the account about to be renamed; its socket joins the
  // notification group first, so the row arrives over the live signal (the cold
  // load after the sign-up is what signUpJoined makes waitable).
  const { userId, name: oldName } = await signUp(page)
  await gotoPage(page, '/')

  const adminPage = await openSecondPerson(browser)
  await signUpAdmin(adminPage)
  // Straight to the detail page: the users list pages, and this account is not
  // guaranteed to be on the first page of a database every test writes to.
  await gotoPage(adminPage, `/hilos/user/${userId}`)
  await expect(adminPage.getByTestId('hilos-user-name')).toHaveText(oldName)

  const newName = `Renamed person ${userId}`
  await adminPage.getByTestId('hilos-user-edit').click()
  await typeInto(adminPage.getByTestId('hilos-user-name-input'), newName)
  await clickSubmit(adminPage.getByTestId('hilos-user-save'))
  // The rename settles when the committed name returns over the live table.
  await expect(adminPage.getByTestId('hilos-user-name')).toHaveText(newName)

  // The rename is the framework's, so the renamed person is told - new for chat.
  await expect(unreadBadge(page)).toHaveText(/^1\b/)
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('An administrator renamed your account')
  await expect(menu).toContainText(`Your name is now ${newName}`)

  // The room shows the rename as before: the feed line now reads the journal row
  // it is linked to. A cold load proves the event stream holds it; the filter
  // isolates the event-notice row, as the author label carries the current name.
  await gotoPage(page, '/')
  await expect(
    page.getByTestId('event-notice').filter({ hasText: newName }),
  ).toHaveText(`renamed by admin from ${oldName} to ${newName}`)

  await adminPage.context().close()
})
