import { test, expect, type Locator, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  enableEmailChannel,
  openBell,
  setCustomSetting,
  shownByTestId,
  unreadBadge,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf, setAdmin } from '../helpers/adminGrant'
import { waitForMailTo } from '../helpers/mail'
import { emitNotification, signUpJoined } from '../helpers/notifications'
import { gotoPage } from '../helpers/page'
import { login, openSignIn, typeInto } from '../helpers/session'
import { tableRowKeys } from '../helpers/table'

// Notification-center e2e (HIL-558), moved here from chat with the communications
// section it drives (HIL-1224); chat keeps only the product half, a mention and a
// rename raising a notification. A notification is emitted through the live
// daemon over its command channel (helpers/notifications.ts), so the row is
// written, the in-app signal is fanned, and the channels are dispatched exactly as
// a product caller's emit would do it — the browser is then asserted on what the
// server actually sent, never on a fixture the test planted.
//
// Every wait is a web-first assertion on the id the emit replied with, so the
// suite never sleeps and never guesses which row it is looking at. Each test
// signs up its own account, and a notification belongs to one recipient, so the
// tests stay isolated on the shared database.
//
// The delivery tests need an account the email channel can address — a verified
// email, which this demo's registration hands out itself: the code it mails also
// proves the address (HIL-825). They switch the channel on from the admin hub as
// an operator would, and read the result from both ends: the delivery journal,
// which is the node's own account of the send, and the stand's mail interceptor,
// which is the independent one.

test('the seeded recipient sees the bounded menu and full unread badge', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await openSignIn(page)
  await login(page, 'seed-001@example.test')

  await expect(unreadBadge(page)).toHaveText(/^22\b/)

  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu.getByTestId(/^hilos-notification-item-/)).toHaveCount(20)
})

/** How long one journal read is given before it is taken again from the top. */
const JOURNAL_READ_MS = 5_000

/** The email channel's opt-out switch in the profile's notifications section. */
function emailPreference(page: Page): Locator {
  return page.getByTestId('hilos-notification-preference-toggle-email')
}

/**
 * Sign in an account the email channel can actually address, and make it admin.
 *
 * The two halves the delivery tests need: a verified email (which is what
 * MailDeliveryChannel resolves an address from — the registration code proves
 * it, see signUpJoined) and the admin flag, since the channel hub lives behind
 * the framework admin surface.
 *
 * @param page Page starting anonymous.
 * @returns The account's proven email and durable user id.
 */
async function signInAddressableAdmin(
  page: Page,
): Promise<{ email: string; userId: number }> {
  const { email, userId } = await signUpJoined(page)
  await setAdmin(userId, true)
  // The daemon re-sends the handshake response to the granted user's live
  // connections, so the admin entry appearing is the grant reaching this page.
  await expect(page.getByTestId('nav-admin')).toBeVisible()

  return { email, userId }
}

/**
 * Wait until the admin delivery journal shows this notification's delivery sent.
 *
 * The journal is served straight from SQL and has no live per-row deltas, so a
 * row's progress only shows on a fresh read — which is why the page is re-opened
 * until it does rather than watched in place.
 *
 * @param page Page of a signed-in admin.
 * @param title Notification title, which is also the journal's search term.
 */
async function expectJournalSent(page: Page, title: string): Promise<void> {
  await expect(async () => {
    await gotoPage(page, '/hilos/communications/email/deliveries')
    await typeInto(page.getByTestId('hilos-table-search'), title)
    const row = page.getByTestId(/^hilos-table-row-/)
    await expect(row).toHaveCount(1, { timeout: JOURNAL_READ_MS })
    await expect(row).toContainText('sent', { timeout: JOURNAL_READ_MS })
  }).toPass()
}

test('an emitted notification reaches the bell as an unread row', async ({
  page,
}) => {
  const { userId } = await signUpJoined(page)

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
  const { userId } = await signUpJoined(page)
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
  const { userId } = await signUpJoined(page)
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
  const { userId } = await signUpJoined(page)

  // Tab B joins the group before the emit, so it receives the notification live
  // rather than picking it up from a reconnect snapshot.
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
  // catalog key (settings.spec.ts owns the other example_* keys), and it is reset
  // to the catalog default afterwards so the suite stays idempotent.
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

  // Dismissed by hand, well inside the stack's own expiry — the close button is
  // what is under test, so the toast has to still be there to close.
  await page.getByTestId('hilos-toast-close').click()
  await expect(toast).toHaveCount(0)

  // Reset the key back to its catalog default.
  await clearCustomSetting(page, 'example_boolean')
  await expect(save).toHaveCount(0)
})

test('muting the email channel keeps the next emit off it', async ({
  page,
}) => {
  const { userId } = await signInAddressableAdmin(page)
  await gotoPage(page, '/hilos/communications')
  await enableEmailChannel(page)

  await gotoPage(page, '/profile/notifications')
  const toggle = emailPreference(page)
  // A channel with no address for it is shown disabled, so the switch being
  // usable at all is the proof this account carries a verified email — and an
  // opt-out is sparse, so an untouched channel starts allowed.
  await expect(toggle).toBeEnabled()
  await expect(toggle).toBeChecked()

  const allowed = await emitNotification(userId, {
    type: 'e2e_channel_allowed',
    title: 'Emitted while the channel is allowed',
  })
  expect(allowed.queuedChannels).toContain('email')

  // A second tab is what makes the mute observable. The switch is never set
  // optimistically, yet the row it redraws from looks the same before and after
  // the server answers — while the preferences signal fans to every connection
  // of the user, so tab B flipping IS the write landing. Without that wait the
  // emit below would be racing it.
  const tabB = await page.context().newPage()
  await gotoPage(tabB, '/profile/notifications')
  await expect(emailPreference(tabB)).toBeChecked()

  await toggle.click()
  await expect(emailPreference(tabB)).not.toBeChecked()

  // Durable, not merely live: a cold load reads the preference back from the DB.
  await gotoPage(page, '/profile/notifications')
  await expect(emailPreference(page)).not.toBeChecked()

  const muted = await emitNotification(userId, {
    type: 'e2e_channel_muted',
    title: 'Emitted while the channel is muted',
  })
  expect(muted.queuedChannels).not.toContain('email')

  await tabB.close()
})

test('an emitted notification is mailed out and journaled as sent', async ({
  page,
}) => {
  const { email, userId } = await signInAddressableAdmin(page)
  await gotoPage(page, '/hilos/communications')
  await enableEmailChannel(page)

  // The title doubles as the journal's search term and as the subject the
  // interceptor is asked for, so it carries the recipient's own id to stay
  // unique on a database every test shares.
  const title = `Delivery for user ${userId}`
  const body = 'This line rode the email channel out of the node.'
  const { queuedChannels } = await emitNotification(userId, {
    type: 'e2e_delivery',
    title,
    body,
  })
  expect(queuedChannels).toContain('email')

  // The journal is the node's own account of the send...
  await expectJournalSent(page, title)

  // ...and the interceptor is the independent one: a message really arrived, and
  // it is this notification (GenericNotificationMailTemplate makes the title the
  // subject and the body the text).
  const mail = await waitForMailTo(email, title)
  expect(mail.text).toContain(body)
})

test('the journal keeps the reason of a delivery in a panel the row expands into', async ({
  page,
}) => {
  const { userId } = await signInAddressableAdmin(page)
  await gotoPage(page, '/hilos/communications')
  await enableEmailChannel(page)

  const title = `Expandable delivery for user ${userId}`
  await emitNotification(userId, {
    type: 'e2e_delivery',
    title,
    body: 'This line waits in the panel under its row.',
  })
  await expectJournalSent(page, title)

  // The assertion above left exactly this delivery on the screen, and nothing is
  // expanded yet, so the one row-shaped handle in the table is the row itself.
  const rowId = await page
    .getByTestId(/^hilos-table-row-/)
    .getAttribute('data-id')
  const key = String(rowId).replace('hilos-table-row-', '')
  const row = page.getByTestId(`hilos-table-row-${key}`)
  // The control and the panel are drawn by the row and by its card alike, so what
  // the reader sees is the copy on screen, and a closed panel is closed in both.
  const panel = page.getByTestId(`hilos-table-row-detail-${key}`)
  const shownPanel = shownByTestId(page, `hilos-table-row-detail-${key}`)
  const control = shownByTestId(page, `hilos-table-expand-${key}`)

  // The notification title took no column of its own: it waits in the panel, and
  // the row says nothing of it until the reader opens one.
  await expect(row).not.toContainText(title)
  await expect(panel).toHaveCount(0)
  await expect(control).toHaveAttribute('aria-expanded', 'false')

  await control.click()

  await expect(shownPanel).toBeVisible()
  await expect(shownPanel).toContainText(title)
  await expect(control).toHaveAttribute('aria-expanded', 'true')

  await control.click()

  await expect(panel).toHaveCount(0)

  // A change of window closes what the reader opened, and it closes it even where
  // the row itself stays. The window is changed by SORTING rather than by dropping
  // the search: the search is what left this one delivery on the screen, and this
  // suite resets its database once per run, so without the filter the row would
  // stand among every other test's deliveries and the assertion would be measuring
  // paging instead of the panel.
  await control.click()
  await expect(shownPanel).toBeVisible()
  await page.getByTestId('hilos-table-sort-createdAt').click()
  await expect(row).toBeVisible()
  await expect(panel).toHaveCount(0)
})

test("an operator reaches a channel's delivery journal by clicks alone", async ({
  page,
}) => {
  // The journal is the channel page's child: the shell draws its card above the
  // channel's own fields, so no address is typed past the hub. The channel stays
  // as it is — the door does not depend on whether it is switched on.
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications')

  // The hub is a declared table drawn both as rows and as cards, so the button
  // is aimed at through the copy on screen.
  await shownByTestId(page, 'hilos-channel-configure-email').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Channel')
  expect(new URL(page.url()).pathname).toBe('/hilos/communications/email')

  await page
    .getByTestId('hilos-admin-child-hilos_communications_deliveries')
    .click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Deliveries')
  expect(new URL(page.url()).pathname).toBe(
    '/hilos/communications/email/deliveries',
  )
})

// Table pager on an inexact count (HIL-1077): composer test:db-prepare seeds 501
// notifications with email delivery rows for seed-002 (501 = TableConstants::COUNT_CEILING + 1),
// crossing the count ceiling where the total stops being exact. When the count is
// inexact, the pager draws no page numbers and walks page-by-page via Next and Previous.
// FIXME(P-386): red on every run since 23.09.2026 and green before it (run 0454): after
// Next the count reads "26 – 50 of 500+" while the rows stay those of the first window.
// Parked by the owner until R2-6 without a diagnosis — see
// hilos-ops/proposals/P-386-deliveries-next-label-rows-stale.md.
test.fixme('paginates the delivery journal across windows when the count exceeds the ceiling', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications/email/deliveries')

  const count = page.getByTestId('hilos-table-count')
  const prev = page.getByTestId('hilos-table-prev')
  const next = page.getByTestId('hilos-table-next')
  const pageNumbers = page.locator('[data-id^="hilos-table-page-"]')

  await expect(count).toHaveText('1 – 25 of 500+')
  await expect(pageNumbers).toHaveCount(0)
  await expect(prev).toBeDisabled()
  await expect(next).toBeEnabled()

  const pageOneKeys = await tableRowKeys(page)

  await next.click()
  await expect(count).toHaveText('26 – 50 of 500+')
  await expect(prev).toBeEnabled()
  await expect(pageNumbers).toHaveCount(0)

  const pageTwoKeys = await tableRowKeys(page)
  for (const key of pageOneKeys) {
    expect(pageTwoKeys).not.toContain(key)
  }

  await prev.click()
  await expect(count).toHaveText('1 – 25 of 500+')
  await expect(prev).toBeDisabled()
})
