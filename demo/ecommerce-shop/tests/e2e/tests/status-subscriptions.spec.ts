import type { Page } from '@playwright/test'
import { expect, test as base } from '../test.js'

import {
  PAGE_READY,
  STATUS_SUBSCRIBERS_PATH,
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  expectStatusSubscriberCounts,
  expectStatusSubscriberDeparture,
  expectTableTotal,
  expectTrackedActionReply,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  openMountedStatusMailLink,
  removeStatusSubscriber,
  removeStatusSubscribersIfAny,
  shownByTestId,
  statusSubscriberId,
  subscribeToStatus,
  typeInto,
  uniqueEmail,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'

// Status subscriptions as this project builds them in React: the public modal
// and its mounted controller, the delivered fragment links and the Subscribers
// table over the real daemon, SMTP and Mailpit. The SDK's unit tests drive each
// piece from a fixture; only the built artifact can bind the modal to the wrong
// controller, lose a fragment before it is dispatched or drop the removal ack.
// Every step reads the server's answer back against the screen. Throttling,
// SMTP failures and retries are the server's and are proved once, by the Vue
// spec of this feature; they are not repeated here.

const test = base.extend<{ cleanup: TestCleanup }>({
  cleanup: cleanupFixtures,
})

// Picked at random from the IANA list when this spec was written: the stand runs
// this project on UTC, so a reader-zone slip cannot hide behind the project's.
// Contexts opened by the test inherit it with the rest of the configured options.
test.use({ timezoneId: 'America/Grenada' })

const SUBSCRIBED =
  'You are subscribed. Letters come on outages and scheduled maintenance.'
const UNSUBSCRIBED = 'You are unsubscribed. No more letters will come.'

/** The payloads of every action of this name the page sent, in order. */
function sentData(log: DeliveryFrameLog, action: string) {
  return log.sent
    .map(({ wire }) => deliveryObject(wire))
    .filter((frame) => frame.type === 'action' && frame.action === action)
    .map((frame) => frame.data)
}

/** The names of every action the page sent, in order. */
function sentActions(log: DeliveryFrameLog) {
  return log.sent
    .map(({ wire }) => deliveryObject(wire))
    .filter((frame) => frame.type === 'action')
    .map((frame) => frame.action)
}

/**
 * The guest's log fit for the report: the frames that carried a capability
 * token are left out, so no token reaches a report file; the rest stays as
 * observed.
 */
function reportLog(log: DeliveryFrameLog) {
  return {
    ...log,
    sent: log.sent.filter(
      ({ wire }) => !('token' in deliveryObject(deliveryObject(wire).data)),
    ),
  }
}

/** The capability a delivered link carries in its fragment. */
function tokenOf(link: string): string {
  return new URL(link).hash.split('=')[1]
}

/** The counts of the latest Subscribers page answer: the server's own word. */
function pageCounts(log: DeliveryFrameLog) {
  const counts = log.packets
    .flatMap((packet) => packet.frames)
    .map(({ wire }) => deliveryObject(wire))
    .filter((frame) => frame.type === 'page_response')
    .map((frame) => deliveryObject(frame.data))
    .filter((data) => data.page === 'hilos_status_subscribers')
    .map((data) => deliveryObject(deliveryObject(data.payload).data))
    .at(-1)?.statusSubscribers
  const { confirmedCount, pendingCount } = deliveryObject(counts)
  expect(confirmedCount).toEqual(expect.any(Number))
  expect(pendingCount).toEqual(expect.any(Number))
  return {
    confirmed: Number(confirmedCount),
    pending: Number(pendingCount),
  }
}

/** Everything the browser keeps for the origin, to look a capability up in. */
function browserStorage(page: Page): Promise<string> {
  return page.evaluate(() =>
    JSON.stringify({
      local: { ...localStorage },
      session: { ...sessionStorage },
    }),
  )
}

test('a guest confirms an address from its letter, two admin windows and a viewer follow it, and its removal takes it out of the set', async ({
  page,
  browser,
  cleanup,
}) => {
  const guestContext = await browser.newContext()
  const guest = await guestContext.newPage()
  // The same guest session looks at the admin section once the view mode is on.
  const viewer = await guestContext.newPage()
  const observer = await page.context().newPage()
  const admin = watchDeliveryFrames(page)
  const second = watchDeliveryFrames(observer)
  const reader = watchDeliveryFrames(guest)
  const looker = watchDeliveryFrames(viewer)
  const email = uniqueEmail()
  try {
    await grantAdminToSelf(expect, page)
    cleanup.defer(() => removeStatusSubscribersIfAny(expect, page, [email]))

    const links =
      await test.step('the guest subscribes through the modal and the page slot says to check the inbox', async () => {
        await gotoPublicStatus(expect, guest)
        await expect(guest.getByTestId('conn-state')).toHaveText('connected')
        await subscribeToStatus(expect, guest, reader, email)
        expect(sentData(reader, 'status_subscribe')).toEqual([{ email }])
        const delivered = statusMailLinks(
          await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
        )
        expect(delivered.confirm).toEqual(expect.any(String))
        return delivered
      })

    const { id, counts } =
      await test.step('two admin windows find the pending address, one by search and one by the Confirmed filter', async () => {
        await gotoPage(expect, page, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
        const answered = pageCounts(admin)
        expect(answered.pending).toBeGreaterThan(0)
        await expectStatusSubscriberCounts(
          expect,
          page,
          admin,
          answered.confirmed,
          answered.pending,
        )
        await typeInto(page.getByTestId('hilos-table-search'), email)
        await expectTableTotal(expect, page, 1)
        const subscriberId = await statusSubscriberId(expect, page, email)
        await expect(
          shownByTestId(
            page,
            `hilos-status-subscriber-confirmed-${subscriberId}`,
          ),
        ).toHaveText('Awaiting confirmation')

        await gotoPage(expect, observer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
        const filter = observer.getByTestId('hilos-table-filter-confirmed')
        await filter.getByTestId('hilos-dropdown-toggle').click()
        await filter.getByTestId('hilos-dropdown-option-1').click()
        await expect(filter.getByTestId('hilos-dropdown-toggle')).toHaveText(
          'Confirmed: No',
        )
        await typeInto(observer.getByTestId('hilos-table-search'), email)
        await expectTableTotal(expect, observer, 1)
        await expect(
          shownByTestId(
            observer,
            `hilos-status-subscriber-email-${subscriberId}`,
          ),
        ).toHaveText(email)
        return { id: subscriberId, counts: answered }
      })

    await test.step('the letter confirms in the same document, the page slot says subscribed, and both windows follow at once', async () => {
      const since = reader.sent.length
      // Same document, and the fragment is gone from the address.
      await openMountedStatusMailLink(expect, guest, links.confirm!)
      await expect(
        guest.getByTestId('public-status-subscription-notice'),
      ).toHaveText(SUBSCRIBED)
      // A confirmation opens no window: it answers in the page's slot.
      await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
      await expectTrackedActionReply(
        expect,
        reader,
        'status_subscription_confirm',
        since,
        { status: 'subscribed' },
      )
      expect(sentData(reader, 'status_subscription_confirm')).toEqual([
        { token: tokenOf(links.confirm!) },
      ])
      expect(await browserStorage(guest)).not.toContain(tokenOf(links.confirm!))

      await expect(
        shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
      ).toHaveText('Yes')
      for (const [surface, log] of [
        [page, admin],
        [observer, second],
      ] as const)
        await expectStatusSubscriberCounts(
          expect,
          surface,
          log,
          counts.confirmed + 1,
          counts.pending - 1,
        )
      // The filtered window holds the confirmed row until Apply takes it out.
      await expectStatusSubscriberDeparture(
        expect,
        observer,
        second,
        id,
        'left_set',
      )
    })

    await test.step('a viewer sees the row with its address hidden and nothing that writes', async () => {
      cleanup.defer(() => setAdminViewMode(false))
      await setAdminViewMode(true)
      await gotoPage(expect, viewer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
      await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
      await expectStatusSubscriberCounts(
        expect,
        viewer,
        looker,
        counts.confirmed + 1,
        counts.pending - 1,
      )
      await expect(
        shownByTestId(
          viewer,
          `hilos-status-subscriber-email-${id}`,
        ).getByTestId('hilos-hidden'),
      ).toHaveText('Hidden')
      await expect(
        shownByTestId(viewer, `hilos-status-subscriber-confirmed-${id}`),
      ).toHaveText('Yes')
      const trash = shownByTestId(
        viewer,
        `hilos-status-subscriber-remove-${id}`,
      )
      await expect(trash).toBeDisabled()
      await expect(trash).toHaveAccessibleDescription(
        'View mode · You can look around, but not change anything.',
      )
      // Searching a personal column is not offered to a viewer.
      await expect(viewer.getByTestId('hilos-table-search')).toHaveCount(0)
      expect(JSON.stringify(looker.packets)).not.toContain(email)
      expect(await viewer.locator('body').textContent()).not.toContain(email)
    })

    await test.step('the admin removes the address through its confirmation and it leaves every window and the set', async () => {
      await removeStatusSubscriber(expect, page, admin, id)
      for (const [surface, log] of [
        [page, admin],
        [observer, second],
        [viewer, looker],
      ] as const)
        await expectStatusSubscriberCounts(
          expect,
          surface,
          log,
          counts.confirmed,
          counts.pending - 1,
        )
      await expectStatusSubscriberDeparture(
        expect,
        viewer,
        looker,
        id,
        'deleted',
      )
      expect(sentActions(looker)).toEqual([])
      // A new document's search reads the set itself, not a held window.
      await gotoPage(expect, page, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
      await typeInto(page.getByTestId('hilos-table-search'), email)
      await expect(
        shownByTestId(
          page.getByTestId('hilos-status-subscribers-table'),
          'hilos-table-no-matches',
        ),
      ).toBeVisible()
    })
  } finally {
    await test.info().attach('status-subscription-admin-wire', {
      body: JSON.stringify({
        admin,
        second,
        reader: reportLog(reader),
        looker,
      }),
      contentType: 'application/json',
    })
    for (const log of [admin, second, reader, looker]) log.stop()
    await Promise.all([observer.close(), guestContext.close()])
  }
})

test('a second address confirmed from its letter unsubscribes only after its masked confirmation, and leaves the admin window', async ({
  page,
  browser,
  cleanup,
}) => {
  const guestContext = await browser.newContext()
  const guest = await guestContext.newPage()
  const admin = watchDeliveryFrames(page)
  const reader = watchDeliveryFrames(guest)
  const email = uniqueEmail()
  const notice = guest.getByTestId('public-status-subscription-notice')
  const form = guest.getByTestId('status-subscription-form')
  const address = guest.getByTestId('status-subscription-address')
  try {
    await grantAdminToSelf(expect, page)
    cleanup.defer(() => removeStatusSubscribersIfAny(expect, page, [email]))

    const links =
      await test.step('the address is subscribed and confirmed from its letter', async () => {
        await gotoPublicStatus(expect, guest)
        await expect(guest.getByTestId('conn-state')).toHaveText('connected')
        await subscribeToStatus(expect, guest, reader, email)
        const delivered = statusMailLinks(
          await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
        )
        const since = reader.sent.length
        await openMountedStatusMailLink(expect, guest, delivered.confirm!)
        await expect(notice).toHaveText(SUBSCRIBED)
        await expectTrackedActionReply(
          expect,
          reader,
          'status_subscription_confirm',
          since,
          { status: 'subscribed' },
        )
        return delivered
      })
    const token = tokenOf(links.unsubscribe)

    const { id, counts } =
      await test.step('the admin window holds the confirmed row', async () => {
        await gotoPage(expect, page, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
        const answered = pageCounts(admin)
        expect(answered.confirmed).toBeGreaterThan(0)
        await typeInto(page.getByTestId('hilos-table-search'), email)
        await expectTableTotal(expect, page, 1)
        const subscriberId = await statusSubscriberId(expect, page, email)
        await expect(
          shownByTestId(
            page,
            `hilos-status-subscriber-confirmed-${subscriberId}`,
          ),
        ).toHaveText('Yes')
        return { id: subscriberId, counts: answered }
      })

    const maskedEmail =
      await test.step('the unsubscribe link only asks, behind the masked address, and Keep keeps the subscription', async () => {
        const since = reader.sent.length
        await openMountedStatusMailLink(expect, guest, links.unsubscribe)
        await expect(address).toContainText('****')
        await expect(address).not.toContainText(email)
        const masked = await address.locator('strong').innerText()
        await expectTrackedActionReply(
          expect,
          reader,
          'status_unsubscribe_preview',
          since,
          { maskedEmail: masked },
        )
        expect(sentData(reader, 'status_unsubscribe_preview')).toEqual([
          { token },
        ])
        await guest.getByTestId('status-subscription-cancel').click()
        await expect(form).toBeHidden()
        expect(sentData(reader, 'status_unsubscribe')).toEqual([])
        await expect(
          shownByTestId(page, `hilos-status-subscriber-email-${id}`),
        ).toHaveText(email)
        await expectStatusSubscriberCounts(
          expect,
          page,
          admin,
          counts.confirmed,
          counts.pending,
        )
        return masked
      })

    await test.step('confirming in the reopened window unsubscribes, and the row leaves the admin window', async () => {
      await openMountedStatusMailLink(expect, guest, links.unsubscribe)
      await expect(address).toContainText(maskedEmail)
      const since = reader.sent.length
      await clickSubmit(expect, guest.getByTestId('status-subscription-submit'))
      await expect(form).toBeHidden()
      await expect(notice).toHaveText(UNSUBSCRIBED)
      await expectTrackedActionReply(
        expect,
        reader,
        'status_unsubscribe',
        since,
        { status: 'unsubscribed' },
      )
      expect(sentData(reader, 'status_unsubscribe')).toEqual([{ token }])
      expect(sentActions(reader)).toEqual([
        'status_subscribe',
        'status_subscription_confirm',
        'status_unsubscribe_preview',
        'status_unsubscribe_preview',
        'status_unsubscribe',
      ])
      await expectStatusSubscriberCounts(
        expect,
        page,
        admin,
        counts.confirmed - 1,
        counts.pending,
      )
      await expectStatusSubscriberDeparture(expect, page, admin, id, 'deleted')
      // A capability travels in the action body only: never kept, never echoed.
      const kept = await browserStorage(guest)
      const received = JSON.stringify(reader.packets)
      for (const link of [links.confirm!, links.unsubscribe]) {
        expect(kept).not.toContain(tokenOf(link))
        expect(received).not.toContain(tokenOf(link))
      }
    })
  } finally {
    await test.info().attach('status-unsubscribe-wire', {
      body: JSON.stringify({ admin, reader: reportLog(reader) }),
      contentType: 'application/json',
    })
    admin.stop()
    reader.stop()
    await guestContext.close()
  }
})
