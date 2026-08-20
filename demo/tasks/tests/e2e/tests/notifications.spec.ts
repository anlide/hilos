import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test'

import { grantAdminToSelf } from '../helpers/adminGrant'
import { emitNotification } from '../helpers/notifications'
import { gotoPage } from '../helpers/page'

// Notification-center e2e for the tasks demo (HIL-558), the React half of the
// same coverage the chat and poll demos carry. A notification is emitted through
// the live daemon over its command channel (helpers/notifications.ts), so the row
// is written and the in-app signal is fanned exactly as a product caller's emit
// would do it — the browser is then asserted on what the server actually sent,
// never on a fixture the test planted.
//
// Every wait is a web-first assertion on the id the emit replied with, so the
// suite never sleeps and never guesses which row it is looking at. Each test runs
// in its own browser context, and each context is given an account of its own
// (openJoined below), so a notification belongs to one recipient and the tests
// stay isolated on the shared database.
//
// This demo activates NOTIFICATIONS without NOTIFICATION_DELIVERY, so the
// coverage stops at the center and the toast: the channel and delivery halves
// have no surface here, and they are the chat demo's to prove.

/**
 * Open the app and learn who this browser is, on a page whose socket has already
 * joined the recipient's notification group.
 *
 * The join is the one ordering this suite depends on: a `notification_created`
 * signal fans to the group, so an emit that overtook the join would be delivered
 * to nobody and the row would never appear. bootHilos binds the notification
 * scope BEFORE it holds the page subscribe, and both wait on the same handshake
 * response, so the group join is written to the socket ahead of the page
 * subscribe — which makes the page reporting `ready` proof that the daemon has
 * already processed the join.
 *
 * A recipient has to be an ACCOUNT: a notification is addressed to a user id, and
 * since HIL-610 a visitor has none. So the browser is granted one over the command
 * channel, and the id comes from that reply rather than off the page - the id
 * marker is empty for a guest, and this page stops being one only because of the
 * grant. The grant also re-sends the handshake response, which is what makes the
 * client join its group: the helper waits for the gear that same response draws.
 *
 * @param page Page that has not opened the app yet.
 * @returns The recipient's durable user id.
 */
async function openJoined(page: Page): Promise<number> {
  const userId = await grantAdminToSelf(page)
  await expect(page.getByTestId('self-user')).not.toBeEmpty()

  return userId
}

/** Open the bell's dropdown so its rows are on screen. */
async function openBell(page: Page): Promise<void> {
  await page.getByTestId('hilos-notification-toggle').click()
  await expect(page.getByTestId('hilos-notification-menu')).toBeVisible()
}

/**
 * The unread badge. Its label carries a visually-hidden suffix — unread is never
 * signalled by color alone — so a count is matched at the front of the text.
 *
 * @param page The page whose bell is read.
 */
function badge(page: Page): Locator {
  return page.getByTestId('hilos-notification-badge')
}

test('an emitted notification reaches the bell as an unread row', async ({
  page,
}) => {
  const userId = await openJoined(page)

  const notificationId = await emitNotification(userId, {
    type: 'e2e_center',
    title: 'Deploy finished',
    body: 'The nightly deploy completed.',
    severity: 'info',
  })

  // The badge is fed by the live signal, so it turns without the menu ever
  // being opened.
  await expect(badge(page)).toHaveText(/^1\b/)

  await openBell(page)
  const row = page.getByTestId(`hilos-notification-item-${notificationId}`)
  await expect(row).toBeVisible()
  await expect(row).toContainText('Deploy finished')
  await expect(row).toContainText('The nightly deploy completed.')
  await expect(page.getByTestId('hilos-notification-empty')).toHaveCount(0)
})

test('marking a row read clears the badge', async ({ page }) => {
  const userId = await openJoined(page)
  const notificationId = await emitNotification(userId, {
    type: 'e2e_mark_read',
    title: 'One to read',
  })
  await expect(badge(page)).toHaveText(/^1\b/)

  await openBell(page)
  await page.getByTestId(`hilos-notification-mark-read-${notificationId}`).click()

  // The store never turns read optimistically: it turns when the server fans the
  // read signal back, so the badge going and the row's own mark-read control
  // going are both proof the round trip landed.
  await expect(badge(page)).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-mark-read-${notificationId}`),
  ).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-item-${notificationId}`),
  ).toBeVisible()
})

test('mark-all read clears a badge carrying several', async ({ page }) => {
  const userId = await openJoined(page)
  const first = await emitNotification(userId, {
    type: 'e2e_mark_all',
    title: 'First',
  })
  const second = await emitNotification(userId, {
    type: 'e2e_mark_all',
    title: 'Second',
  })
  await expect(badge(page)).toHaveText(/^2\b/)

  await openBell(page)
  await page.getByTestId('hilos-notification-mark-all').click()

  await expect(badge(page)).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-mark-read-${first}`),
  ).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-mark-read-${second}`),
  ).toHaveCount(0)
  // With nothing left unread the header control disables itself.
  await expect(page.getByTestId('hilos-notification-mark-all')).toBeDisabled()
})

test('a read in one tab reaches the other tab of the same user', async ({
  page,
}) => {
  const userId = await openJoined(page)

  // Tab B joins the group before the emit, so it receives the notification live
  // rather than picking it up from a reconnect snapshot. It shares the context's
  // session cookie, so the handshake names it the same user.
  const tabB = await page.context().newPage()
  let tabBLoads = 0
  tabB.on('load', () => {
    tabBLoads += 1
  })
  await gotoPage(tabB, '/')
  const loadsAfterColdLoad = tabBLoads

  const notificationId = await emitNotification(userId, {
    type: 'e2e_across_tabs',
    title: 'Seen from both tabs',
  })
  await expect(badge(page)).toHaveText(/^1\b/)
  await expect(badge(tabB)).toHaveText(/^1\b/)

  await openBell(page)
  await openBell(tabB)
  await page.getByTestId(`hilos-notification-mark-read-${notificationId}`).click()

  // The read is fanned to every connection of the recipient, so tab B settles
  // without asking for anything.
  await expect(badge(tabB)).toHaveCount(0)
  await expect(
    tabB.getByTestId(`hilos-notification-mark-read-${notificationId}`),
  ).toHaveCount(0)
  expect(tabBLoads).toBe(loadsAfterColdLoad)

  await tabB.close()
})

test('saving a setting raises a toast the close button dismisses', async ({
  page,
}) => {
  // The toast host is mounted once by the admin shell (HilosLayout), and a
  // tracked action toasts its own outcome — so the settings page is simply the
  // nearest surface that raises one for real. example_boolean is this spec's own
  // catalog key (settings.spec.ts mutates example_integer), and it is reset to
  // the catalog default afterwards so the suite stays idempotent.
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await page.getByTestId('hilos-table-search').fill('example_boolean')
  await expect(page.getByTestId('hilos-table-row-example_boolean')).toBeVisible()

  const save = page.getByTestId('hilos-settings-edit-save')
  await page.getByTestId('hilos-settings-edit-example_boolean').click()
  await page.getByTestId('hilos-settings-edit-custom').check()
  await page.getByTestId('hilos-settings-edit-value').check()
  await save.click()

  // The dialog closes on the action's own `::success` reply, so its going is the
  // action settling — asserted before the toast, which the same reply raises.
  await expect(save).toHaveCount(0)
  const toast = page.getByTestId('hilos-toast-success')
  await expect(toast).toBeVisible()
  await expect(page.getByTestId('hilos-toasts')).toBeVisible()

  // Dismissed by hand, well inside the stack's own expiry — the close button is
  // what is under test, so the toast has to still be there to close.
  await page.getByTestId('hilos-toast-close').click()
  await expect(toast).toHaveCount(0)

  // Reset the key back to its catalog default.
  await page.getByTestId('hilos-settings-edit-example_boolean').click()
  await page.getByTestId('hilos-settings-edit-custom').uncheck()
  await save.click()
  await expect(save).toHaveCount(0)
})

// The product half of the line (HIL-557): every row above was planted by the
// command channel, which proves the center but not that anything in this demo
// ever raises a notification. There is no domain content here yet, so the first
// event that does is an account-level one - an administrator renaming somebody -
// and it needs two accounts, which in this demo means two browser contexts.

/**
 * Open a second visitor in a context of its own.
 *
 * Identity here is the session cookie, so two pages of one context are one
 * browser and one account. A context made off the browser fixture
 * inherits none of the project's `use` options - including the tolerance for the
 * self-signed certificate the test nginx serves - so they are passed on by hand.
 *
 * @param browser The browser the test runs in.
 * @returns A page belonging to a fresh visitor.
 */
async function openSecondVisitor(browser: Browser): Promise<Page> {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })

  return context.newPage()
}

/**
 * Type a value the way a user does: clear, then key by key. A bare `fill(value)`
 * dispatches one synthetic `input`, which can slip past the view's reactivity and
 * leave the surface holding a stale value.
 *
 * @param field The input locator.
 * @param value The value to type.
 */
async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
}

test('an administrator renaming somebody reaches the renamed visitor', async ({
  page,
  browser,
}) => {
  // This browser is the account about to be renamed; its socket joins the
  // notification group first, so the row arrives over the live signal.
  const userId = await openJoined(page)

  const adminPage = await openSecondVisitor(browser)
  await grantAdminToSelf(adminPage)
  // Straight to the detail page: the users list pages, and this account is not
  // guaranteed to be on the first page of a database every test writes to.
  await gotoPage(adminPage, `/hilos/user/${userId}`)
  await expect(adminPage.getByTestId('hilos-user-detail')).toBeVisible()

  const newName = `Renamed visitor ${userId}`
  await adminPage.getByTestId('hilos-user-edit').click()
  await typeInto(adminPage.getByTestId('hilos-user-name-input'), newName)
  await adminPage.getByTestId('hilos-user-save').click()
  // The rename settles when the committed name returns over the live table.
  await expect(adminPage.getByTestId('hilos-user-name')).toHaveText(newName)

  await expect(badge(page)).toHaveText(/^1\b/)

  // The product emit never hands the id out, so the row is found by its own
  // title rather than by hilos-notification-item-<id>.
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('An administrator renamed your account')
  await expect(menu).toContainText(`Your name is now ${newName}`)

  await adminPage.context().close()
})
