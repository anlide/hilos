import { expect, test } from '@playwright/test'
import {
  STATUS_PATH,
  STATUS_SUBSCRIBERS_PATH,
  PAGE_READY,
  deliveryObject,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  openStatusMailLink,
  showAnnouncedRows,
  shownByTestId,
  statusSubscriberId,
  subscribeToStatus,
  typeInto,
  uniqueEmail,
  watchDeliveryFrames,
  expectStatusSubscriberCounts,
  expectStatusSubscriberDeparture,
  expectDocumentUnchanged,
  expectTableTotal,
  markDocument,
  removeStatusSubscriber,
  statusSubscriberFrames,
  statusSubscriberWindow,
  tableRowKeys,
  watchFirstRowTop,
  watchHeight,
} from '../../../../../framework/frontend/e2e/index.js'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'

test.use({ colorScheme: 'light' })

test('subscriber counts preserve two held windows through Show, confirmation, Apply and own removal', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Two live windows, an Overview and a real mailed confirmation.
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const guest = await guestContext.newPage()
  const observer = await page.context().newPage()
  const overview = await page.context().newPage()
  const logs = [page, observer, overview, guest].map(watchDeliveryFrames)
  const email = uniqueEmail()
  const newerEmail = email.replace('@', '-new@')
  try {
    await grantAdminToSelf(expect, page)
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, logs[3], email)
    const mail = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    for (const reader of [page, observer]) {
      await gotoPage(expect, reader, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
      await typeInto(
        reader.getByTestId('hilos-table-search'),
        email.split('@')[0],
      )
      await expectTableTotal(expect, reader, 1)
    }
    const id = await statusSubscriberId(expect, page, email)
    const pending = Number(
      await page.getByTestId('hilos-status-subscribers-pending').innerText(),
    )
    const confirmed = Number(
      await page.getByTestId('hilos-status-subscribers-confirmed').innerText(),
    )
    const filter = observer.getByTestId('hilos-table-filter-confirmed')
    await filter.getByTestId('hilos-dropdown-toggle').click()
    await filter.getByTestId('hilos-dropdown-option-1').click()
    await expect(filter.getByTestId('hilos-dropdown-toggle')).toHaveText(
      'Confirmed: No',
    )
    await expect
      .poll(
        () =>
          logs[1].sent
            .map(({ wire }) => deliveryObject(wire))
            .filter((frame) => frame.type === 'table_viewport')
            .at(-1)?.filter,
      )
      .toMatchObject({ confirmed: false })
    const filteredRequest = logs[1].sent
      .map(({ wire }) => deliveryObject(wire))
      .filter((frame) => frame.type === 'table_viewport')
      .at(-1)!
    expect(filteredRequest.sort).toEqual([
      { field: 'subscribedAt', direction: 'desc' },
    ])
    await expect
      .poll(() => statusSubscriberWindow(logs[1]))
      .toMatchObject({
        requestId: filteredRequest.requestId,
        rows: [{ rowKey: Number(id) }],
      })
    await expectTableTotal(expect, observer, 1)
    await gotoPage(expect, overview, STATUS_PATH, PAGE_READY)
    for (const reader of [page, observer, overview]) await markDocument(reader)
    const positions = await Promise.all(
      [page, observer].map((reader) =>
        watchFirstRowTop(reader, 'hilos-status-subscribers-table'),
      ),
    )
    const requests = logs.slice(0, 2).map((log) => log.sent.length)
    await subscribeToStatus(expect, guest, logs[3], newerEmail)
    for (const [index, reader] of [page, observer].entries()) {
      await expectStatusSubscriberCounts(
        expect,
        reader,
        logs[index],
        confirmed,
        pending + 1,
      )
      await expect
        .poll(() =>
          statusSubscriberFrames(logs[index], 'table_viewport_announce'),
        )
        .toContainEqual(expect.objectContaining({ placement: 'above' }))
      await expect
        .poll(() => tableRowKeys(reader, 'hilos-status-subscribers-table'))
        .toEqual([id])
      await expect(
        reader.getByTestId('hilos-table-announce-show'),
      ).toBeVisible()
      await expect(reader.getByTestId('hilos-table-apply')).toBeHidden()
      await positions[index].unchanged()
      expect(
        logs[index].sent
          .slice(requests[index])
          .filter(({ wire }) => deliveryObject(wire).type === 'table_viewport'),
      ).toEqual([])
    }
    await expectStatusSubscriberCounts(
      expect,
      overview,
      logs[2],
      confirmed,
      pending + 1,
      true,
    )
    await showAnnouncedRows(expect, page)
    const newerId = await statusSubscriberId(expect, page, newerEmail)
    await expect
      .poll(() => tableRowKeys(page, 'hilos-status-subscribers-table'))
      .toEqual([newerId, id])
    await expect
      .poll(() => tableRowKeys(observer, 'hilos-status-subscribers-table'))
      .toEqual([id])
    await expect(
      observer.getByTestId('hilos-table-announce-show'),
    ).toBeVisible()
    await openStatusMailLink(expect, guest, mail.confirm!)
    await expect(
      guest.getByTestId('public-status-subscription-notice'),
    ).toHaveText(
      'You are subscribed. Letters come on outages and scheduled maintenance.',
    )
    await expect(
      shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Yes')
    await expect(page.getByTestId('hilos-table-apply')).toBeHidden()
    for (const [index, reader] of [page, observer, overview].entries())
      await expectStatusSubscriberCounts(
        expect,
        reader,
        logs[index],
        confirmed + 1,
        pending,
        index === 2,
      )
    // Pending departures own the shared message row; its live region still names the announcement.
    await expect(observer.getByTestId('hilos-table-live-status')).toContainText(
      'Also: new rows.',
    )
    await expectStatusSubscriberDeparture(
      expect,
      observer,
      logs[1],
      id,
      'left_set',
    )
    await positions[1].unchanged()
    await showAnnouncedRows(expect, observer)
    await expect
      .poll(() => tableRowKeys(observer, 'hilos-status-subscribers-table'))
      .toEqual([newerId])
    const beforeRemoval = logs[1].sent.length
    const heldHeight = await watchHeight(
      observer.getByTestId('hilos-status-subscribers-table'),
    )
    await removeStatusSubscriber(expect, page, logs[0], newerId)
    for (const [index, reader] of [page, observer, overview].entries())
      await expectStatusSubscriberCounts(
        expect,
        reader,
        logs[index],
        confirmed + 1,
        pending - 1,
        index === 2,
      )
    await expect(
      shownByTestId(observer, `hilos-table-pending-remove-${newerId}`),
    ).toBeVisible()
    await expectTableTotal(expect, observer, 0)
    await expectStatusSubscriberDeparture(
      expect,
      observer,
      logs[1],
      newerId,
      'deleted',
    )
    await heldHeight.unchanged()
    expect(
      statusSubscriberFrames(logs[0], 'table_viewport_delta'),
    ).toContainEqual(
      expect.objectContaining({
        rowKey: Number(newerId),
        kind: 'row_removed',
        own: true,
      }),
    )
    const foreignRemoval = statusSubscriberFrames(
      logs[1],
      'table_viewport_delta',
    ).find((delta) => delta.rowKey === Number(newerId))
    expect(foreignRemoval).toMatchObject({
      rowKey: Number(newerId),
      kind: 'row_removed',
      reason: 'deleted',
    })
    // The wire omits the default false; only the initiating window receives own:true.
    expect(foreignRemoval).not.toHaveProperty('own')
    await expect(observer.getByTestId('hilos-table-empty')).toHaveCount(0)
    expect(
      logs[1].sent
        .slice(beforeRemoval)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).action === 'hilos_status_subscriber_remove',
        ),
    ).toEqual([])
    await removeStatusSubscriber(expect, page, logs[0], id)
    for (const [index, reader] of [page, observer, overview].entries()) {
      await expectStatusSubscriberCounts(
        expect,
        reader,
        logs[index],
        confirmed,
        pending - 1,
        index === 2,
      )
      await expectDocumentUnchanged(expect, reader)
    }
  } finally {
    await test.info().attach('status-subscribers-two-window-wire', {
      body: JSON.stringify(logs),
      contentType: 'application/json',
    })
    for (const log of logs) log.stop()
    await Promise.all([
      observer.close(),
      overview.close(),
      guestContext.close(),
    ])
  }
})
