import { expect as baseExpect, test } from '@playwright/test'
import {
  STATUS_PATH,
  STATUS_SUBSCRIBERS_PATH,
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
  PAGE_READY,
  armViewerSubmit,
  RESTART_MARK_TIMEOUT_MS,
  applyStatusSubscribers,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  expectPublicStatusPair,
  expectStatusMailAlternatives,
  expectTrackedActionReply,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  openStatusMailLink,
  openMountedStatusMailLink,
  showAnnouncedRows,
  shownByTestId,
  statusSubscriberId,
  subscribeToStatus,
  submitStatusIncident,
  trackedActionReplies,
  typeInto,
  uniqueEmail,
  watchDeliveryFrames,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  mailsTo,
  waitForMailTo,
  waitForMailToMatching,
} from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
  statusMailMoment,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'
import {
  resetAuthThrottle,
  THROTTLE_IP_LIMIT,
  THROTTLE_REASON,
  THROTTLE_SESSION_LIMIT,
  THROTTLE_WINDOW_MS,
} from '../../../../../framework/frontend/scripts/authThrottle.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { withStatusAgentLoss } from '../helpers/status.js'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

const expect = baseExpect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })

const STATUS_PUBLISHED_SUBJECT = 'Major outage: Settings library is unavailable'
const STATUS_UPDATE_SUBJECT = 'Update: Settings library is unavailable'
const STATUS_RESOLVED_SUBJECT = 'Resolved: Settings library is answering again'
// A distinct zone makes the saved browser zone observable beside the project default.
const RECIPIENT_TIME_ZONE = 'Pacific/Honolulu'

test.use({ colorScheme: 'light' })

test('delivered capabilities work in the mounted Status document and keep its socket and file reader', async ({
  page,
}) => {
  const log = watchPublicStatus(page)
  const email = uniqueEmail()
  try {
    await resetAuthThrottle()
    await gotoPublicStatus(expect, page)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expectPublicStatusPair(expect, page, log)
    const components = await page
      .getByTestId('public-status-components')
      .elementHandle()
    expect(log.wire.connections).toHaveLength(1)
    const socket = log.wire.connections[0].socket
    await subscribeToStatus(expect, page, log.wire, email)
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    let since = log.wire.sent.length
    await openMountedStatusMailLink(expect, page, links.confirm!)
    await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    await expect(
      page.getByTestId('public-status-subscription-notice'),
    ).toHaveText('You are subscribed')
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_subscription_confirm',
      since,
      { status: 'subscribed' },
    )
    for (const keep of [true, false]) {
      since = log.wire.sent.length
      await openMountedStatusMailLink(expect, page, links.unsubscribe)
      await expect(
        page.getByTestId('status-subscription-address'),
      ).toContainText('****')
      await expect(
        page.getByTestId('status-subscription-address'),
      ).not.toContainText(email)
      const maskedEmail = await page
        .getByTestId('status-subscription-address')
        .locator('strong')
        .innerText()
      await expectTrackedActionReply(
        expect,
        log.wire,
        'status_unsubscribe_preview',
        since,
        { maskedEmail },
      )
      expect(
        log.wire.sent.filter(
          ({ wire }) => deliveryObject(wire).action === 'status_unsubscribe',
        ),
      ).toHaveLength(0)
      if (keep) await page.getByTestId('status-subscription-cancel').click()
      else {
        since = log.wire.sent.length
        await clickSubmit(
          expect,
          page.getByTestId('status-subscription-submit'),
        )
      }
      await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    }
    await expect(
      page.getByTestId('public-status-subscription-notice'),
    ).toHaveText('You are unsubscribed')
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_unsubscribe',
      since,
      {
        status: 'unsubscribed',
      },
    )
    expect(
      log.wire.sent
        .map(({ wire }) => deliveryObject(wire))
        .filter((frame) => frame.type === 'action')
        .map((frame) => frame.action),
    ).toEqual([
      'status_subscribe',
      'status_subscription_confirm',
      'status_unsubscribe_preview',
      'status_unsubscribe_preview',
      'status_unsubscribe',
    ])
    expect(log.wire.connections).toHaveLength(1)
    expect(log.wire.connections[0].closed).toBeUndefined()
    expect(log.wire.sent.every((request) => request.socket === socket)).toBe(
      true,
    )
    expect(
      log.wire.sent.some(({ wire }) =>
        ['page_subscribe', 'table_viewport'].includes(
          String(deliveryObject(wire).type),
        ),
      ),
    ).toBe(false)
    expect(await components!.evaluate((node) => node.isConnected)).toBe(true)
    await expectPublicStatusPair(expect, page, log)
    await expectDocumentUnchanged(expect, page)
  } finally {
    await test.info().attach('status-mounted-capabilities', {
      body: JSON.stringify({ reads: log.reads, wire: log.wire }),
      contentType: 'application/json',
    })
    await log.stop()
    await resetAuthThrottle()
  }
})

// FIXME(F-049, 2026-10-29): Standalone and cluster publishers can expose mismatched Status revisions.
test(
  'guest letters confirm, publish, update, resolve and explicitly unsubscribe over real SMTP',
  { tag: '@quarantine:F-049' },
  async ({ page, browser, baseURL }) => {
    // Three subscription letters, two real worker losses and their SMTP fanout.
    test.slow()
    const contexts = await Promise.all(
      Array.from({ length: 3 }, () =>
        browser.newContext({
          baseURL,
          ignoreHTTPSErrors: true,
          colorScheme: 'light',
          timezoneId: RECIPIENT_TIME_ZONE,
        }),
      ),
    )
    const [guest, control, pending] = await Promise.all(
      contexts.map((context) => context.newPage()),
    )
    const recipients = [uniqueEmail(), uniqueEmail(), uniqueEmail()]
    const publicLog = watchPublicStatus(guest)
    const controlLog = watchDeliveryFrames(control)
    const pendingLog = watchDeliveryFrames(pending)
    const observer = await page.context().newPage()
    const workers = await page.context().newPage()
    const admin = watchStatusFrames(page)
    const mailEvidence: unknown[] = []
    try {
      await resetAuthThrottle()
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
      for (const [index, reader] of [guest, control, pending].entries()) {
        const log = [publicLog.wire, controlLog, pendingLog][index]
        await gotoPublicStatus(expect, reader)
        await expect(reader.getByTestId('conn-state')).toHaveText('connected')
        await subscribeToStatus(expect, reader, log, recipients[index])
      }
      const links = []
      for (const [index, reader] of [guest, control].entries()) {
        const mail = await waitForMailTo(
          recipients[index],
          STATUS_CONFIRM_SUBJECT,
        )
        const delivered = statusMailLinks(mail)
        expectStatusMailAlternatives(
          expect,
          mail,
          delivered,
          RECIPIENT_TIME_ZONE,
        )
        expect(delivered.status).toBe(`${baseURL}/status`)
        expect(delivered.confirm).toEqual(expect.any(String))
        links.push(delivered)
        mailEvidence.push(mail)
        const log = [publicLog.wire, controlLog][index]
        const since = log.sent.length
        await openStatusMailLink(expect, reader, delivered.confirm!)
        await expect(
          reader.getByTestId('status-subscription-form'),
        ).toBeHidden()
        await expect(
          reader.getByTestId('public-status-subscription-notice'),
        ).toHaveText('You are subscribed')
        await expectTrackedActionReply(
          expect,
          log,
          'status_subscription_confirm',
          since,
          {
            status: 'subscribed',
          },
        )
        await expect(reader.getByTestId('nav-signin')).toBeVisible()
      }
      await gotoPage(expect, observer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
      const originalId = await statusSubscriberId(
        expect,
        observer,
        recipients[0],
      )
      await expect(
        shownByTestId(
          observer,
          `hilos-status-subscriber-confirmed-${originalId}`,
        ),
      ).toHaveText('Yes')

      const markers = [
        `Mailpit first ${Date.now()}`,
        `Mailpit after removal ${Date.now()}`,
      ]
      for (const [cycle, marker] of markers.entries()) {
        await withStatusAgentLoss(expect, workers, admin, async () => {
          const duringLoss = expect.configure({
            timeout: Math.min(
              STATUS_EXPECT_TIMEOUT_MS,
              RESTART_MARK_TIMEOUT_MS,
            ),
          })
          await duringLoss.poll(() => admin.latest()?.incidents?.length).toBe(1)
          const incidentId = admin.latest()!.incidents![0].incidentId
          await page
            .getByTestId(`hilos-status-publish-now-${incidentId}`)
            .click()
          await submitStatusIncident(
            duringLoss,
            page,
            admin,
            'hilos_status_publish_now',
            incidentId,
          )
          await dismissToasts(page)
          await page
            .getByTestId(`hilos-status-add-update-${incidentId}`)
            .click()
          await typeInto(page.getByTestId('hilos-status-update-text'), marker)
          await submitStatusIncident(
            duringLoss,
            page,
            admin,
            'hilos_status_add_update',
            incidentId,
          )
        })
        await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
        const pair = await expectPublicStatusPair(
          expect,
          guest,
          publicLog,
          ({ status }) =>
            status.outages.some(
              (outage) =>
                outage.resolvedAt !== null &&
                outage.updates.some((update) => update.text === marker),
            ),
        )
        const outage = pair.status.outages.find((item) =>
          item.updates.some((update) => update.text === marker),
        )!
        const subjects = [
          STATUS_PUBLISHED_SUBJECT,
          STATUS_UPDATE_SUBJECT,
          STATUS_RESOLVED_SUBJECT,
        ]
        const deliveredTo =
          cycle === 0 ? recipients.slice(0, 2) : recipients.slice(1, 2)
        for (const address of deliveredTo) {
          for (const subject of subjects) {
            const eventAt =
              subject === STATUS_PUBLISHED_SUBJECT
                ? outage.publishedAt
                : subject === STATUS_UPDATE_SUBJECT
                  ? outage.updates.find((entry) => entry.text === marker)!.at
                  : outage.resolvedAt!
            const update = outage.updates.find((entry) => entry.at === eventAt)!
            const mail = await waitForMailToMatching(
              address,
              (letter) =>
                letter.subject === subject &&
                letter.text.includes(update.text) &&
                statusMailMoment(letter, 'Updated at') ===
                  Math.floor(eventAt / 1000) * 1000,
            )
            const delivered = statusMailLinks(mail)
            expectStatusMailAlternatives(
              expect,
              mail,
              delivered,
              RECIPIENT_TIME_ZONE,
            )
            expect(statusMailMoment(mail, 'Detected at')).toBe(
              Math.floor(outage.detectedAt / 1000) * 1000,
            )
            if (subject === STATUS_RESOLVED_SUBJECT)
              expect(statusMailMoment(mail, 'Resolved at')).toBe(
                Math.floor(outage.resolvedAt! / 1000) * 1000,
              )
            expect(delivered.unsubscribe).toBe(
              links[recipients.indexOf(address)].unsubscribe,
            )
            expect(mail.text).toContain(update.text)
            expect(mail.text).toContain('Settings library')
            mailEvidence.push(mail)
          }
        }
        // The confirmed control has received this exact committed event. A held
        // old SMTP copy is harmless; absence is about this new distinct team text.
        expect(
          (await mailsTo(recipients[2])).filter((mail) =>
            subjects.includes(mail.subject),
          ),
        ).toEqual([])
        if (cycle === 1) {
          expect(
            (await mailsTo(recipients[0])).filter(
              (mail) =>
                mail.subject === STATUS_UPDATE_SUBJECT &&
                mail.text.includes(marker),
            ),
          ).toEqual([])
          continue
        }
        const outageMail = await waitForMailTo(
          recipients[0],
          STATUS_UPDATE_SUBJECT,
        )
        const unsubscribe = statusMailLinks(outageMail).unsubscribe
        let since = publicLog.wire.sent.length
        await openStatusMailLink(expect, guest, unsubscribe)
        await expect(
          guest.getByTestId('status-subscription-address'),
        ).toContainText('****')
        await expect(
          guest.getByTestId('status-subscription-address'),
        ).not.toContainText(recipients[0])
        const preview = trackedActionReplies(
          publicLog.wire,
          'status_unsubscribe_preview',
          since,
        )
        expect(preview).toHaveLength(1)
        const maskedEmail = deliveryObject(preview[0].data.reply).maskedEmail
        expect(Object.keys(deliveryObject(preview[0].data.reply))).toEqual([
          'maskedEmail',
        ])
        expect(maskedEmail).not.toBe(recipients[0])
        await expect(
          guest.getByTestId('status-subscription-cancel'),
        ).toHaveText('Keep')
        await guest.getByTestId('status-subscription-cancel').click()
        await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
        await expect(
          shownByTestId(
            observer,
            `hilos-status-subscriber-email-${originalId}`,
          ),
        ).toHaveText(recipients[0])
        expect(
          publicLog.wire.sent.filter(
            ({ wire }) => deliveryObject(wire).action === 'status_unsubscribe',
          ),
        ).toHaveLength(0)
        await openStatusMailLink(expect, guest, unsubscribe)
        await expect(
          guest.getByTestId('status-subscription-address'),
        ).toContainText(String(maskedEmail))
        since = publicLog.wire.sent.length
        await clickSubmit(
          expect,
          guest.getByTestId('status-subscription-submit'),
        )
        await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
        await expect(
          guest.getByTestId('public-status-subscription-notice'),
        ).toHaveText('You are unsubscribed')
        await expectTrackedActionReply(
          expect,
          publicLog.wire,
          'status_unsubscribe',
          since,
          { status: 'unsubscribed' },
        )
        await applyStatusSubscribers(expect, observer)
        await expect(
          observer.getByTestId(`hilos-status-subscriber-email-${originalId}`),
        ).toHaveCount(0)
      }

      // A new row for the same address has new capabilities. Old mail cannot
      // confirm or remove it, even after the replacement is confirmed normally.
      await subscribeToStatus(expect, guest, publicLog.wire, recipients[0])
      const replacementMail = await waitForMailTo(
        recipients[0],
        STATUS_CONFIRM_SUBJECT,
        2,
      )
      const replacement = statusMailLinks(replacementMail)
      expect(replacement.unsubscribe).not.toBe(links[0].unsubscribe)
      await openStatusMailLink(expect, guest, replacement.confirm!)
      await expect(
        guest.getByTestId('public-status-subscription-notice'),
      ).toHaveText('You are subscribed')
      await showAnnouncedRows(expect, observer)
      const replacementId = await statusSubscriberId(
        expect,
        observer,
        recipients[0],
      )
      expect(replacementId).not.toBe(originalId)
      for (const oldLink of [links[0].confirm!, links[0].unsubscribe]) {
        await openStatusMailLink(expect, guest, oldLink)
        await expect(
          guest.getByTestId('status-subscription-error'),
        ).toContainText(
          'This subscription link is invalid or has expired. Request a new link.',
        )
        await expect(
          guest.getByTestId('status-subscription-submit'),
        ).toHaveCount(0)
      }
      await gotoPage(expect, observer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
      await expect(
        shownByTestId(
          observer,
          `hilos-status-subscriber-email-${replacementId}`,
        ),
      ).toHaveText(recipients[0])
      await expect(
        shownByTestId(
          observer,
          `hilos-status-subscriber-confirmed-${replacementId}`,
        ),
      ).toHaveText('Yes')
      await gotoPage(expect, guest, '/', PAGE_READY)
      await expect(guest.getByTestId('self-anonymous')).toBeVisible()
      const publicFacts = JSON.stringify({
        reads: publicLog.reads,
        received: publicLog.wire.packets,
      })
      for (const address of recipients)
        expect(publicFacts).not.toContain(address)
      for (const link of [...links, replacement]) {
        for (const secretLink of [link.confirm!, link.unsubscribe]) {
          const token = new URL(secretLink).hash.split('=')[1]
          expect(publicFacts).not.toContain(token)
          expect(
            await guest.evaluate(() =>
              JSON.stringify({
                local: { ...localStorage },
                session: { ...sessionStorage },
              }),
            ),
          ).not.toContain(token)
        }
      }
    } finally {
      await test.info().attach('status-subscriptions-smtp-and-wire', {
        body: JSON.stringify({
          mailEvidence,
          publicReads: publicLog.reads,
          guest: publicLog.wire,
          control: controlLog,
          pending: pendingLog,
          admin: admin.frames,
        }),
        contentType: 'application/json',
      })
      admin.stop()
      controlLog.stop()
      pendingLog.stop()
      await publicLog.stop()
      await Promise.all([
        observer.close(),
        workers.close(),
        ...contexts.map((context) => context.close()),
      ])
      await resetAuthThrottle()
    }
  },
)

test('the Status verdict host refuses the shared email budget across two guest sessions', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Eleven UI submissions, each with a real all-scopes verdict.
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const other = await otherContext.newPage()
  const logs = [watchDeliveryFrames(page), watchDeliveryFrames(other)]
  const email = uniqueEmail()
  try {
    await resetAuthThrottle()
    await gotoPublicStatus(expect, page)
    await gotoPublicStatus(expect, other)
    for (const reader of [page, other])
      await expect(reader.getByTestId('conn-state')).toHaveText('connected')
    const started = performance.now()
    for (let attempt = 0; attempt < THROTTLE_SESSION_LIMIT; attempt++) {
      const index = attempt % 2
      await subscribeToStatus(expect, [page, other][index], logs[index], email)
    }
    expect(THROTTLE_SESSION_LIMIT + 1).toBeLessThan(THROTTLE_IP_LIMIT)
    const since = logs[0].sent.length
    await page.getByTestId('public-status-subscribe').click()
    await typeInto(
      page.getByTestId('status-subscription-email'),
      email.toUpperCase(),
    )
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-error')).toContainText(
      THROTTLE_REASON,
    )
    await expect
      .poll(
        () => trackedActionReplies(logs[0], 'status_subscribe', since).length,
      )
      .toBe(1)
    const refusal = trackedActionReplies(logs[0], 'status_subscribe', since)[0]
    expect(refusal.frame.type).toBe('action_error')
    expect(refusal.data).toMatchObject({
      errorCode: 'rate_limited',
      reason: THROTTLE_REASON,
      retryAfter: expect.any(Number),
    })
    expect(refusal.data.retryAfter).toBeGreaterThan(0)
    expect(performance.now() - started).toBeLessThan(THROTTLE_WINDOW_MS)
  } finally {
    await test.info().attach('status-live-email-throttle', {
      body: JSON.stringify(logs),
      contentType: 'application/json',
    })
    for (const log of logs) log.stop()
    await otherContext.close()
    await resetAuthThrottle()
  }
})

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
    ).toHaveText('You are subscribed')
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

test('a live subscriber stays hidden from a viewer and a valid forged removal is refused', async ({
  page,
  browser,
  baseURL,
}) => {
  const contexts = await Promise.all(
    Array.from({ length: 2 }, () =>
      browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        colorScheme: 'light',
      }),
    ),
  )
  const [guest, viewer] = await Promise.all(
    contexts.map((context) => context.newPage()),
  )
  const forged = await armViewerSubmit(viewer)
  const logs = [page, guest, viewer].map(watchDeliveryFrames)
  const email = uniqueEmail()
  try {
    await grantAdminToSelf(expect, page)
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, logs[1], email)
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    await gotoPage(expect, page, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
    const id = await statusSubscriberId(expect, page, email)
    const pending = Number(
      await page.getByTestId('hilos-status-subscribers-pending').innerText(),
    )
    const confirmed = Number(
      await page.getByTestId('hilos-status-subscribers-confirmed').innerText(),
    )
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_SUBSCRIBERS_PATH, PAGE_READY)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expectStatusSubscriberCounts(
      expect,
      viewer,
      logs[2],
      confirmed,
      pending,
    )
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-email-${id}`).getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    const trash = shownByTestId(viewer, `hilos-status-subscriber-remove-${id}`)
    await expect(trash).toBeDisabled()
    await expect(trash).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    await expect(viewer.getByTestId('hilos-table-search')).toHaveCount(0)
    await expect(viewer.getByTestId('hilos-table-sort-email')).toHaveCount(0)
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Awaiting confirmation')
    // PUBLIC confirmation still works with the ADMIN viewing switch on.
    await openStatusMailLink(expect, guest, links.confirm!)
    await expect(
      guest.getByTestId('public-status-subscription-notice'),
    ).toHaveText('You are subscribed')
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Yes')
    await expectStatusSubscriberCounts(
      expect,
      viewer,
      logs[2],
      confirmed + 1,
      pending - 1,
    )
    const requestId = `subscriber-forged-${Date.now()}`
    const observed = await forged.submit(
      JSON.stringify({
        type: 'action',
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: Number(id) },
        requestId,
      }),
      requestId,
    )
    expect(observed.failure).toBeUndefined()
    const refusal = deliveryObject(observed.wire)
    expect(refusal.type).toBe('action_error')
    const data = deliveryObject(refusal.data)
    expect(refusal.requestId ?? data.requestId).toBe(requestId)
    expect(data).toMatchObject({
      action: 'hilos_status_subscriber_remove',
      errorCode: 'view_mode',
      reason: 'View mode: you can look around, but not change anything.',
    })
    expect(data).not.toHaveProperty('errorType')
    expect(data).not.toHaveProperty('errorDetail')
    await expectStatusSubscriberCounts(
      expect,
      page,
      logs[0],
      confirmed + 1,
      pending - 1,
    )
    await expect(
      shownByTestId(page, `hilos-status-subscriber-email-${id}`),
    ).toHaveText(email)
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-email-${id}`).getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    expect(JSON.stringify(logs[2].packets)).not.toContain(email)
    expect(await viewer.locator('body').textContent()).not.toContain(email)
  } finally {
    await test.info().attach('status-subscriber-viewer-wire', {
      body: JSON.stringify({
        admin: logs[0],
        viewer: logs[2],
        forged: forged.frames,
      }),
      contentType: 'application/json',
    })
    forged.dispose()
    for (const log of logs) log.stop()
    await Promise.all(contexts.map((context) => context.close()))
    await setAdminViewMode(false)
  }
})
