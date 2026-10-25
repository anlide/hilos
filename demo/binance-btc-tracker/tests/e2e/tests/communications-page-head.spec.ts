import { expect, test } from '@playwright/test'

import {
  communicationsChannelReplies,
  deliveryObject,
  expectCommunicationsChannelHead,
  expectCommunicationsOverviewSummary,
  expectCommunicationsSummaryUpdate,
  resetCommunicationsField,
  saveCommunicationsField,
  shownByTestId,
  watchDeliveryFrames,
  watchTableSearch,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf, sessionToken } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { typeInto } from '../helpers/session.js'

const OVERVIEW = 'hilos_communications_channel'
const CONFIGURATION = 'hilos_communications_channel_configuration'
const DELIVERIES = 'hilos_communications_deliveries'

test('Configure opens Configuration and channel links bind the real Email and Sms names', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications')
  for (const [channel, label] of [
    ['email', 'Email'],
    ['sms', 'Sms'],
    ['email', 'Email'],
  ] as const) {
    await shownByTestId(page, `hilos-channel-configure-${channel}`).click()
    await expectPageReady(page)
    await expect(page).toHaveURL(
      `/hilos/communications/${channel}/configuration`,
    )
    await expectCommunicationsChannelHead(
      expect,
      page,
      'configuration',
      channel,
      label,
    )
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await expect(page.getByTestId('hilos-channel-test')).toHaveCount(0)

    await page.getByTestId(`hilos-page-tab-${OVERVIEW}`).click()
    await expectPageReady(page)
    await expect(page).toHaveURL(`/hilos/communications/${channel}`)
    await expectCommunicationsChannelHead(
      expect,
      page,
      'overview',
      channel,
      label,
    )
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

    await page.getByTestId(`hilos-page-tab-${DELIVERIES}`).click()
    await expectPageReady(page)
    await expect(page).toHaveURL(`/hilos/communications/${channel}/deliveries`)
    await expectCommunicationsChannelHead(
      expect,
      page,
      'deliveries',
      channel,
      label,
    )
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await page.getByTestId('hilos-breadcrumb-hilos_communications').click()
    await expectPageReady(page)
  }
})

for (const tab of ['configuration', 'deliveries'] as const) {
  test(`direct ${tab} and F5 resolve the requested channel from its own server reply`, async ({
    page,
  }) => {
    const wire = watchDeliveryFrames(page)
    try {
      await grantAdminToSelf(page)
      for (const [channel, label] of [
        ['email', 'Email'],
        ['sms', 'Sms'],
      ] as const) {
        await gotoPage(page, `/hilos/communications/${channel}/${tab}`)
        await expectCommunicationsChannelHead(expect, page, tab, channel, label)
        await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
        const beforeReload = wire.packets.length
        await page.reload()
        await expectPageReady(page)
        await expectCommunicationsChannelHead(expect, page, tab, channel, label)
        const pageKey = tab === 'configuration' ? CONFIGURATION : DELIVERIES
        const replies = communicationsChannelReplies(
          wire,
          pageKey,
          beforeReload,
        )
        expect(replies).toHaveLength(1)
        expect(replies[0]!.summary).toMatchObject({ channel, label })
        expect(
          Object.keys(deliveryObject(replies[0]!.payload.windows)),
        ).toEqual([
          tab === 'configuration'
            ? 'hilosCommunicationsChannelFields'
            : 'hilosNotificationDeliveries',
        ])
      }
    } finally {
      wire.stop()
    }
  })
}

test('a different admin session changes the open summary while Deliveries keeps its applied window', async ({
  browser,
  baseURL,
  ignoreHTTPSErrors,
}, info) => {
  const observerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const mutatorContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  try {
    const overview = await observerContext.newPage()
    const configuration = await mutatorContext.newPage()
    const journal = await observerContext.newPage()
    const overviewWire = watchDeliveryFrames(overview)
    const configurationWire = watchDeliveryFrames(configuration)
    const journalWire = watchDeliveryFrames(journal)
    const table = await watchTableSearch(journal, {
      page: DELIVERIES,
      tableKey: 'hilosNotificationDeliveries',
    })
    try {
      const observerId = await grantAdminToSelf(overview)
      const mutatorId = await grantAdminToSelf(configuration)
      expect(mutatorId).not.toBe(observerId)
      expect(await sessionToken(configuration)).not.toBe(
        await sessionToken(overview),
      )
      await gotoPage(configuration, '/hilos/communications/sms/configuration')
      await expectCommunicationsChannelHead(
        expect,
        configuration,
        'configuration',
        'sms',
        'Sms',
      )
      const row = configuration.getByTestId(
        'hilos-table-row-notifications.channel.sms.from',
      )
      await expect(row).toBeVisible()
      await expect(
        shownByTestId(configuration, 'hilos-channel-field-reset-from'),
      ).toBeDisabled()
      const originalRow = await row.innerText()
      expect(originalRow).toMatch(/\b(Default|From env)\b/)
      const fromDefault = /\bDefault\b/.test(originalRow)
      const baseline = communicationsChannelReplies(
        configurationWire,
        CONFIGURATION,
      ).at(-1)!.summary

      await gotoPage(overview, '/hilos/communications/sms')
      await expectCommunicationsChannelHead(
        expect,
        overview,
        'overview',
        'sms',
        'Sms',
      )
      await expectCommunicationsOverviewSummary(expect, overview, baseline)
      const opening = communicationsChannelReplies(overviewWire, OVERVIEW).at(
        -1,
      )!
      expect(deliveryObject(opening.payload.windows)).toEqual({})
      expect(
        overviewWire.sent.filter(({ wire }) => {
          const data = deliveryObject(deliveryObject(wire).data)
          return data.page === OVERVIEW && 'tableKey' in data
        }),
      ).toEqual([])

      await gotoPage(journal, '/hilos/communications/sms/deliveries')
      await expectCommunicationsChannelHead(
        expect,
        journal,
        'deliveries',
        'sms',
        'Sms',
      )
      await expect(journal.getByTestId('hilos-viewport-table')).toBeVisible()
      const search = 'communications-summary-continuity'
      const mark = await table.mark()
      await typeInto(journal.getByTestId('hilos-table-search'), search)
      await table.settled(expect, mark, search, [])
      const applied = (await table.read()).application
      expect(applied).toHaveLength(1)
      expect(applied[0]!.appliedRequestId).not.toBeNull()
      const root = await journal
        .getByTestId('hilos-viewport-table')
        .elementHandle()
      expect(root).not.toBeNull()
      const sentBefore = journalWire.sent.length

      const missingFields = baseline.missingFields - (fromDefault ? 1 : 0)
      const changed = {
        ...baseline,
        missingFields,
        configured: missingFields === 0,
        overriddenFieldCount: baseline.overriddenFieldCount + 1,
      }
      const beforeSet = [
        overviewWire.packets.length,
        journalWire.packets.length,
      ]
      await saveCommunicationsField(
        expect,
        configuration,
        'from',
        '+15550000009',
      )
      await expect(row).toContainText('+15550000009')
      await expect(row).toContainText('Override')
      await expectCommunicationsSummaryUpdate(
        expect,
        overviewWire,
        OVERVIEW,
        beforeSet[0]!,
        changed,
      )
      await expectCommunicationsSummaryUpdate(
        expect,
        journalWire,
        DELIVERIES,
        beforeSet[1]!,
        changed,
      )
      await expectCommunicationsOverviewSummary(expect, overview, changed)
      await expect(journal.getByTestId('channel-head-configured')).toHaveText(
        changed.configured ? 'Configured' : `${changed.missingFields} missing`,
      )
      expect((await table.read()).application).toEqual(applied)
      expect(await root!.evaluate((node) => node.isConnected)).toBe(true)

      const beforeReset = [
        overviewWire.packets.length,
        journalWire.packets.length,
      ]
      await resetCommunicationsField(expect, configuration, 'from')
      await expect.poll(() => row.innerText()).toBe(originalRow)
      await expectCommunicationsSummaryUpdate(
        expect,
        overviewWire,
        OVERVIEW,
        beforeReset[0]!,
        baseline,
      )
      await expectCommunicationsSummaryUpdate(
        expect,
        journalWire,
        DELIVERIES,
        beforeReset[1]!,
        baseline,
      )
      await expectCommunicationsOverviewSummary(expect, overview, baseline)
      expect((await table.read()).application).toEqual(applied)
      expect(await root!.evaluate((node) => node.isConnected)).toBe(true)
      expect(
        journalWire.sent.slice(sentBefore).filter(({ wire }) => {
          const request = deliveryObject(wire)
          return (
            request.type === 'subscription_page' ||
            'tableKey' in deliveryObject(request.data)
          )
        }),
      ).toEqual([])
      await root!.dispose()
      await info.attach('communications-live-summary-and-window', {
        body: JSON.stringify({
          baseline,
          changed,
          overview: overviewWire.packets,
          journal: journalWire.packets,
          applied,
          final: await table.read(),
        }),
        contentType: 'application/json',
      })
    } finally {
      overviewWire.stop()
      configurationWire.stop()
      journalWire.stop()
    }
  } finally {
    await mutatorContext.close()
    await observerContext.close()
  }
})
