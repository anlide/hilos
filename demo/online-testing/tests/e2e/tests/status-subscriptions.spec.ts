import { expect, test as base } from '@playwright/test'
import {
  applyStatusSubscribers,
  cleanupFixtures,
  clearStatusSubscriber,
  clickSubmit,
  clickUnavailableControl,
  deliveryObject,
  expectStatusSubscriberCounts,
  expectTrackedActionReply,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  openMountedStatusMailLink,
  PAGE_READY,
  removeStatusSubscriber,
  shownByTestId,
  STATUS_SUBSCRIBERS_PATH,
  statusSubscriberId,
  statusSubscriberWindow,
  subscribeToStatus,
  trackedActionReplies,
  type TestCleanup,
  uniqueEmail,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
test.use({ colorScheme: 'light', timezoneId: 'Pacific/Johnston' })

// Two delivered capabilities bind the Angular forms, fragment handler and table.
test('Status subscription confirms, stays private to a viewer and leaves through both UI paths', async ({
  page,
  browser,
  baseURL,
  cleanup,
}) => {
  const options = {
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light' as const,
    timezoneId: 'Pacific/Johnston',
  }
  const guestContext = await browser.newContext(options)
  cleanup.defer(() => guestContext.close())
  const viewerContext = await browser.newContext(options)
  cleanup.defer(() => viewerContext.close())
  const guest = await guestContext.newPage()
  const viewer = await viewerContext.newPage()
  const adminLog = watchDeliveryFrames(page)
  const guestLog = watchDeliveryFrames(guest)
  const viewerLog = watchDeliveryFrames(viewer)
  cleanup.defer(() => {
    adminLog.stop()
    guestLog.stop()
    viewerLog.stop()
  })
  await grantAdminToSelf(expect, page)
  const session = adminLog.packets
    .flatMap((packet) => packet.frames)
    .map(({ wire }) => deliveryObject(wire))
    .find((frame) => frame.type === 'handshake_response')!
  const originalMode = deliveryObject(
    deliveryObject(session.data).data,
  ).adminViewMode
  expect(typeof originalMode).toBe('boolean')
  cleanup.defer(() => setAdminViewMode(originalMode as boolean))
  const firstEmail = uniqueEmail()
  const secondEmail = uniqueEmail()
  let firstRemoved = false
  let secondRemoved = false
  // Reserve both UI subjects before either submit, including failed mail reads.
  cleanup.defer(async () => {
    if (!firstRemoved)
      await clearStatusSubscriber(expect, page, adminLog, firstEmail)
  })
  cleanup.defer(async () => {
    if (!secondRemoved)
      await clearStatusSubscriber(expect, page, adminLog, secondEmail)
  })

  let firstLinks!: ReturnType<typeof statusMailLinks>
  let id!: string
  await test.step('Guest subscribes through the Angular form; Mailpit supplies the confirmation link', async () => {
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, guestLog, firstEmail)
    firstLinks = statusMailLinks(
      await waitForMailTo(firstEmail, STATUS_CONFIRM_SUBJECT),
    )
    await gotoPage(expect, page, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
    id = await statusSubscriberId(expect, page, firstEmail)
    await expectStatusSubscriberCounts(expect, page, adminLog, 0, 1)
    const filter = page.getByTestId('hilos-table-filter-confirmed')
    const since = adminLog.sent.length
    await filter.getByTestId('hilos-dropdown-toggle').click()
    await filter.getByTestId('hilos-dropdown-option-1').click()
    await expect
      .poll(() => statusSubscriberWindow(adminLog, since))
      .toBeDefined()
    await expect(
      shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Awaiting confirmation')
  })

  await test.step('The mounted mail fragment confirms; the live admin counts and held window follow', async () => {
    const since = guestLog.sent.length
    await openMountedStatusMailLink(expect, guest, firstLinks.confirm!)
    await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      guestLog,
      'status_subscription_confirm',
      since,
      { status: 'subscribed' },
    )
    await expect(
      guest.getByTestId('public-status-subscription-notice'),
    ).toHaveText(
      'You are subscribed. Letters come on outages and scheduled maintenance.',
    )
    await expectStatusSubscriberCounts(expect, page, adminLog, 1, 0)
    await expect(
      shownByTestId(page, `hilos-table-pending-remove-${id}`),
    ).toBeVisible()
    await applyStatusSubscribers(expect, page)
    await expect(
      page.getByTestId(`hilos-status-subscriber-email-${id}`),
    ).toHaveCount(0)
    const filter = page.getByTestId('hilos-table-filter-confirmed')
    const before = adminLog.sent.length
    await filter.getByTestId('hilos-dropdown-toggle').click()
    await filter.getByTestId('hilos-dropdown-option--1').click()
    await expect
      .poll(() => statusSubscriberWindow(adminLog, before))
      .toBeDefined()
    await expect(
      shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Yes')
  })

  await test.step('A viewer sees Hidden and cannot send a removal from its disabled control', async () => {
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-email-${id}`).getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    await expect(viewer.getByTestId('hilos-table-search')).toHaveCount(0)
    const trash = shownByTestId(viewer, `hilos-status-subscriber-remove-${id}`)
    await expect(trash).toBeDisabled()
    const since = viewerLog.sent.length
    await clickUnavailableControl(viewer, trash)
    await expect(
      viewer.getByTestId('hilos-status-subscriber-remove-form'),
    ).toHaveCount(0)
    await expectStatusSubscriberCounts(expect, viewer, viewerLog, 1, 0)
    expect(
      viewerLog.sent
        .slice(since)
        .filter(({ wire }) => deliveryObject(wire).type === 'action'),
    ).toEqual([])
  })

  await test.step('Admin removes the confirmed subscriber with the modal and its own ack', async () => {
    await removeStatusSubscriber(expect, page, adminLog, id)
    firstRemoved = true
    await expectStatusSubscriberCounts(expect, page, adminLog, 0, 0)
  })

  await test.step('A second delivered link previews a masked address and explicitly unsubscribes', async () => {
    await subscribeToStatus(expect, guest, guestLog, secondEmail)
    const links = statusMailLinks(
      await waitForMailTo(secondEmail, STATUS_CONFIRM_SUBJECT),
    )
    const since = guestLog.sent.length
    await openMountedStatusMailLink(expect, guest, links.unsubscribe)
    const address = guest.getByTestId('status-subscription-address')
    await expect(address).toContainText('****')
    await expect(address).not.toContainText(secondEmail)
    await expectTrackedActionReply(
      expect,
      guestLog,
      'status_unsubscribe_preview',
      since,
      { maskedEmail: expect.any(String) },
    )
    const preview = deliveryObject(
      trackedActionReplies(guestLog, 'status_unsubscribe_preview', since)[0]
        .data.reply,
    )
    await expect(address).toContainText(String(preview.maskedEmail))
    await expect(guest.getByTestId('status-subscription-form')).toBeVisible()
    await expectStatusSubscriberCounts(expect, page, adminLog, 0, 1)
    const before = guestLog.sent.length
    expect(
      guestLog.sent
        .slice(since)
        .filter(
          ({ wire }) => deliveryObject(wire).action === 'status_unsubscribe',
        ),
    ).toEqual([])
    await clickSubmit(expect, guest.getByTestId('status-subscription-submit'))
    await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      guestLog,
      'status_unsubscribe',
      before,
      { status: 'unsubscribed' },
    )
    secondRemoved = true
    await expect(
      guest.getByTestId('public-status-subscription-notice'),
    ).toHaveText('You are unsubscribed. No more letters will come.')
    await expectStatusSubscriberCounts(expect, page, adminLog, 0, 0)
  })
  expect(
    viewerLog.sent.filter(({ wire }) => deliveryObject(wire).type === 'action'),
  ).toEqual([])
})
