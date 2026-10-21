import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test'

import {
  clearCustomSetting,
  enableEmailChannel,
  openBell,
  setCustomSetting,
  shownByTestId,
  unreadBadge,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { waitForMailTo } from '../helpers/mail.js'
import { emitNotification } from '../helpers/notifications.js'
import { gotoPage } from '../helpers/page.js'
import { signUp, typeInto } from '../helpers/session.js'

// Notification-center e2e for the online-testing demo: the Angular half of the center
// coverage binance-btc-tracker and ecommerce-shop carry. A notification is emitted
// through the live daemon over its command channel (helpers/notifications.ts), so the
// row is written and the in-app signal is fanned exactly as a product caller's emit
// would do it — the browser is then asserted on what the server actually sent, never
// on a fixture the test planted.
//
// Every wait is a web-first assertion on the id the emit replied with, so the
// suite never sleeps and never guesses which row it is looking at. Each test runs
// in its own browser context, and each context is given an account of its own
// (openJoined below), so a notification belongs to one recipient and the tests
// stay isolated on the shared database.
//
// The delivery channels and their journal are activated in this demo (HIL-1687),
// so notifications emitted over the command channel are asserted both in the
// in-app notification center and through email delivery and preferences.

/** The email channel's opt-out switch in the profile's notifications section. */
function emailPreference(page: Page): Locator {
  return page.getByTestId('hilos-notification-preference-toggle-email')
}

/**
 * Sign in an account the email channel can address (with a verified email), and make it admin.
 *
 * @param page Page starting anonymous.
 * @returns The account's proven email and durable user id.
 */
async function signInAddressableAdmin(
  page: Page,
): Promise<{ email: string; userId: number }> {
  const email = await signUp(page)
  const userId = await grantAdminToSelf(page)

  return { email, userId }
}

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
 * since HIL-611 a visitor has none. So the browser is granted one over the command
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

test('an emitted notification reaches the bell as an unread row', async ({
  page,
}) => {
  const userId = await openJoined(page)

  const { notificationId } = await emitNotification(userId, {
    type: 'e2e_center',
    title: 'Deploy finished',
    body: 'The nightly deploy completed.',
    severity: 'info',
  })

  // The badge is fed by the live signal, so it turns without the menu ever
  // being opened.
  await expect(unreadBadge(page)).toHaveText(/^1\b/)

  await openBell(page)
  const row = page.getByTestId(`hilos-notification-item-${notificationId}`)
  await expect(row).toBeVisible()
  await expect(row).toContainText('Deploy finished')
  await expect(row).toContainText('The nightly deploy completed.')
  await expect(page.getByTestId('hilos-notification-empty')).toHaveCount(0)
})

test('marking a row read clears the badge', async ({ page }) => {
  const userId = await openJoined(page)
  const { notificationId } = await emitNotification(userId, {
    type: 'e2e_mark_read',
    title: 'One to read',
  })
  await expect(unreadBadge(page)).toHaveText(/^1\b/)

  await openBell(page)
  await page
    .getByTestId(`hilos-notification-mark-read-${notificationId}`)
    .click()

  // The store never turns read optimistically: it turns when the server fans the
  // read signal back, so the badge going and the row's own mark-read control
  // going are both proof the round trip landed.
  await expect(unreadBadge(page)).toHaveCount(0)
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
  await expect(unreadBadge(page)).toHaveText(/^2\b/)

  await openBell(page)
  await page.getByTestId('hilos-notification-mark-all').click()

  await expect(unreadBadge(page)).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-mark-read-${first.notificationId}`),
  ).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-notification-mark-read-${second.notificationId}`),
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

  const { notificationId } = await emitNotification(userId, {
    type: 'e2e_across_tabs',
    title: 'Seen from both tabs',
  })
  await expect(unreadBadge(page)).toHaveText(/^1\b/)
  await expect(unreadBadge(tabB)).toHaveText(/^1\b/)

  await openBell(page)
  await openBell(tabB)
  await page
    .getByTestId(`hilos-notification-mark-read-${notificationId}`)
    .click()

  // The read is fanned to every connection of the recipient, so tab B settles
  // without asking for anything.
  await expect(unreadBadge(tabB)).toHaveCount(0)
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
  await typeInto(page.getByTestId('hilos-table-search'), 'example_boolean')
  await expect(
    page.getByTestId('hilos-table-row-example_boolean'),
  ).toBeVisible()

  const save = page.getByTestId('hilos-settings-edit-save')
  await setCustomSetting(page, 'example_boolean', true)

  // The dialog closes on the action's own `::success` reply, so its going is the
  // action settling — asserted before the toast, which the same reply raises.
  await expect(save).toHaveCount(0)
  const toast = page.getByTestId('hilos-toast-success')
  await expect(toast).toBeVisible()
  await expect(page.getByTestId('hilos-toasts')).toBeVisible()

  // The life bar is drawn only for a measured card that is not an error, so its
  // presence is the one automatic sign that the Angular card was redrawn and
  // that the measurement and the countdown behind it are alive — this SDK has
  // no component test of its own (ng test is blocked upstream, HIL-491).
  await expect(page.getByTestId('hilos-toast-life')).toBeVisible()

  // Dismissed by hand, well inside the stack's own expiry — the close button is
  // what is under test, so the toast has to still be there to close.
  await page.getByTestId('hilos-toast-close').click()
  await expect(toast).toHaveCount(0)

  // Reset the key back to its catalog default.
  await clearCustomSetting(page, 'example_boolean')
  await expect(save).toHaveCount(0)
})

test('an emitted notification is delivered by email', async ({ page }) => {
  const { email, userId } = await signInAddressableAdmin(page)

  const title = `Delivery for user ${userId}`
  const body = 'This line rode the email channel out of the node.'
  const { queuedChannels } = await emitNotification(userId, {
    type: 'e2e_delivery',
    title,
    body,
  })
  expect(queuedChannels).toContain('email')

  const mail = await waitForMailTo(email, title)
  expect(mail.text).toContain(body)
})

test('muting the email channel keeps the next emit off it', async ({
  page,
}) => {
  const { userId } = await signInAddressableAdmin(page)

  await gotoPage(page, '/profile/notifications')
  const toggle = emailPreference(page)
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeChecked()

  const allowed = await emitNotification(userId, {
    type: 'e2e_channel_allowed',
    title: 'Emitted while the channel is allowed',
  })
  expect(allowed.queuedChannels).toContain('email')

  const tabB = await page.context().newPage()
  await gotoPage(tabB, '/profile/notifications')
  await expect(emailPreference(tabB)).toBeChecked()

  await toggle.click()
  await expect(emailPreference(tabB)).not.toBeChecked()

  await gotoPage(page, '/profile/notifications')
  await expect(emailPreference(page)).not.toBeChecked()

  const muted = await emitNotification(userId, {
    type: 'e2e_channel_muted',
    title: 'Emitted while the channel is muted',
  })
  expect(muted.queuedChannels).not.toContain('email')

  await tabB.close()
})

test('disabling the email channel globally persists across page reload and can be re-enabled', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications')
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // Ensure channel is enabled at start.
  await enableEmailChannel(page)

  const toggle = shownByTestId(page, 'hilos-channel-enabled-email')
  await expect(toggle).toBeVisible()
  await expect(toggle).toBeChecked()

  // Switching off persists false over the default=true.
  await toggle.click()
  await expect(toggle).not.toBeChecked()

  await gotoPage(page, '/hilos/communications')
  const reloadedToggle = shownByTestId(page, 'hilos-channel-enabled-email')
  await expect(reloadedToggle).toBeVisible()
  await expect(reloadedToggle).not.toBeChecked()

  // Clean up: restore the channel to enabled so other tests see email=true.
  await enableEmailChannel(page)
  await gotoPage(page, '/hilos/communications')
  await expect(shownByTestId(page, 'hilos-channel-enabled-email')).toBeChecked()
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

  await expect(unreadBadge(page)).toHaveText(/^1\b/)

  // The product emit never hands the id out, so the row is found by its own
  // title rather than by hilos-notification-item-<id>.
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('An administrator renamed your account')
  await expect(menu).toContainText(`Your name is now ${newName}`)

  await adminPage.context().close()
})
