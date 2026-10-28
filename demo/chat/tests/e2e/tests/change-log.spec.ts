import { expect, test, type Page } from '@playwright/test'

import {
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_PATH,
  CHANGE_LOG_NOISY_PATH,
  CHANGE_LOG_FEED_PATH,
  CHANGE_LOG_TABLES_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogDashboardCard,
  expectChangeLogSectionHead,
  expectChangeLogNoisyColumns,
  expectTrackedTablesPage,
  expectChangeLogFeedRow,
  expectChangeLogOverview,
  expectChangeLogFeed,
  expectChangeLogReceiptOpen,
  expectChangeLogReceiptMoment,
  expectChangeLogTimes,
  expectChangeLogValues,
  expectRegistrationFeedRow,
  expectRegistrationHistory,
  expectRegistrationReceiptFacts,
  expectTrackedTableRecord,
  forwardSessionTimeZone,
  shownByTestId,
  watchChangeLogDisplay,
  watchSocketFrames,
  expectPageReady,
  clickSubmit,
  typeInto,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUp } from '../helpers/session.js'

test('opens Change Log from the admin dashboard with its overview and feed', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await gotoPage(expect, page, '/hilos')

  await expectChangeLogDashboardCard(expect, page)
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expectChangeLogOverview(expect, page)
  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  await expectChangeLogFeed(expect, page)
})

const SECTION = [
  { key: 'hilos_change_log', label: 'Overview', path: CHANGE_LOG_PATH },
  { key: 'hilos_change_log_feed', label: 'Feed', path: CHANGE_LOG_FEED_PATH },
  {
    key: 'hilos_change_log_tables',
    label: 'Tracked tables',
    path: CHANGE_LOG_TABLES_PATH,
  },
  {
    key: 'hilos_change_log_noisy',
    label: 'Noisy columns',
    path: CHANGE_LOG_NOISY_PATH,
  },
] as const

/** Observe the real first answer, including a missing payload as failed evidence. */
function watchSectionReplies(page: Page) {
  const replies: Array<{ page: string; payload: unknown }> = []
  const observer = watchSocketFrames(page, ({ wire }) => {
    const message = wire as
      | { type?: unknown; data?: { page?: unknown; payload?: unknown } }
      | undefined
    if (
      message?.type === 'page_response' &&
      typeof message.data?.page === 'string'
    ) {
      replies.push({ page: message.data.page, payload: message.data.payload })
    }
  })
  return { replies, stop: observer.stop }
}

/** The first response carries the whole section identity and that page's own data. */
function expectFirstSectionReply(
  replies: ReturnType<typeof watchSectionReplies>['replies'],
  key: string,
): void {
  const first = replies.find((reply) => reply.page === key)
  expect(first).toBeDefined()
  expect(first!.payload).toMatchObject({
    data: {
      pageHeadOwner: {
        page: 'hilos_change_log',
        label: 'Change Log',
        lead: 'Who changed what in the tracked tables, and through which action.',
        icon: 'bi-clock-history',
      },
      pageBreadcrumb: [
        { page: 'hilos', label: 'Hilos' },
        { page: 'hilos_change_log', label: 'Change Log' },
      ],
      pageTabs: SECTION.map(({ key, label }) => ({ page: key, label })),
      pageChildren: [],
    },
  })
  if (key === 'hilos_change_log' || key === 'hilos_change_log_feed') {
    expect(first!.payload).toMatchObject({
      data: {
        changeLogOverview: {
          journalEntries: expect.any(Number),
          journalBytes: expect.any(Number),
          journaledTables: expect.arrayContaining(['hilos_user']),
          liveTables: expect.any(Number),
        },
      },
    })
  }
  const table =
    key === 'hilos_change_log_feed'
      ? 'hilosChangeLogFeed'
      : key === 'hilos_change_log_tables'
        ? 'hilosChangeLogTables'
        : key === 'hilos_change_log_noisy'
          ? 'hilosChangeLogNoisyColumns'
          : undefined
  if (table) {
    expect(first!.payload).toMatchObject({
      windows: {
        [table]: { rows: expect.any(Array), totalCount: expect.any(Number) },
      },
    })
  }
  if (key === 'hilos_change_log_noisy') {
    expect(first!.payload).toMatchObject({
      data: {
        changeLogNoisyColumns: expect.arrayContaining([
          expect.objectContaining({
            table: 'hilos_user',
            column: 'last_activity',
          }),
        ]),
      },
    })
  }
}

test('navigates both overview tiles and all four native section links with Back', async ({
  page,
}) => {
  const observed = watchSectionReplies(page)
  try {
    await signUpAdmin(expect, page, { entryId: 'message-signin' })
    await gotoPage(expect, page, CHANGE_LOG_PATH)
    await expectChangeLogOverview(expect, page)
    await expect(page.getByTestId(/^hilos-change-log-link-/)).toHaveCount(2)
    const feed = page.getByTestId('hilos-change-log-link-feed')
    await expect(feed).toHaveRole('link')
    await expect(feed).toHaveAttribute('href', CHANGE_LOG_FEED_PATH)
    await feed.click()
    await expectChangeLogFeed(expect, page)
    await page.goBack()
    await expectPageReady(expect, page)
    await expectChangeLogOverview(expect, page)
    const tables = page.getByTestId('hilos-change-log-link-tables')
    await expect(tables).toHaveRole('link')
    await expect(tables).toHaveAttribute('href', CHANGE_LOG_TABLES_PATH)
    await tables.click()
    await expectTrackedTablesPage(expect, page)
    for (const section of [SECTION[3], SECTION[0], SECTION[1], SECTION[2]]) {
      await page.getByTestId(`hilos-page-tab-${section.key}`).click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${section.path}$`))
      await expectChangeLogSectionHead(expect, page, section.key)
      expectFirstSectionReply(observed.replies, section.key)
    }
    expect(observed.replies.some((reply) => reply.page === 'hilos')).toBe(false)
  } finally {
    observed.stop()
  }
})

for (const section of [SECTION[1], SECTION[3]]) {
  test(`opens ${section.label} directly with its first complete answer and keeps it through F5`, async ({
    page,
  }) => {
    const observed = watchSectionReplies(page)
    try {
      await signUpAdmin(expect, page, { entryId: 'message-signin' })
      await gotoPage(expect, page, section.path)
      if (section.key === 'hilos_change_log_feed')
        await expectChangeLogFeed(expect, page)
      else await expectChangeLogNoisyColumns(expect, page)
      expectFirstSectionReply(observed.replies, section.key)
      expect(
        observed.replies.some(
          (reply) =>
            reply.page === 'hilos' || reply.page === 'hilos_change_log',
        ),
      ).toBe(false)
      await page.reload()
      await expectPageReady(expect, page)
      await expectChangeLogSectionHead(expect, page, section.key)
      if (section.key === 'hilos_change_log_feed')
        await expectChangeLogFeed(expect, page)
      else await expectChangeLogNoisyColumns(expect, page)
      expect(
        observed.replies.filter((reply) => reply.page === section.key),
      ).toHaveLength(2)
    } finally {
      observed.stop()
    }
  })
}

// FIXME(F-024, 2026-10-24): окно чека не открывается после Enter · чужой · попытка 1 · HIL-1661
test(
  'opens a block receipt with old and new values and returns keyboard focus',
  { tag: '@quarantine:F-024' },
  async ({ browser, page }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const personContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const personPage = await personContext.newPage()
    try {
      const adminId = await signUpAdmin(expect, page, {
        entryId: 'message-signin',
      })
      const person = await signUp(expect, personPage)
      await gotoPage(expect, page, `/hilos/user/${person.userId}`)
      await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
      await expect(page.getByTestId('modal')).toBeVisible()
      await clickSubmit(
        expect,
        page.getByTestId('hilos-user-lifecycle-confirm'),
      )
      await expect(page.getByTestId('modal')).toBeHidden()
      await dismissToasts(page)

      await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
      await chooseChangeLogFeedTable(page, 'hilos_user')
      await typeInto(page.getByTestId('hilos-table-search'), `#${adminId}`)
      const { rowKey, receiptId } = await expectChangeLogFeedRow(
        expect,
        page,
        `hilos_user #${person.userId} · updated`,
        CHANGE_LOG_BLOCK_ACTION,
      )

      const opener = shownByTestId(page, `hilos-change-log-open-${rowKey}`)
      await expect(opener).toHaveAccessibleName(`View receipt #${receiptId}`)
      await opener.focus()
      await page.keyboard.press('Enter')
      await expectChangeLogReceiptOpen(expect, page, receiptId)
      const modal = page.getByTestId('modal')
      await expect(modal).toBeFocused()
      const detail = page.getByTestId('hilos-change-log-detail')
      await expect(detail).toContainText(`hilos_user #${person.userId}`)
      await expect(detail).toContainText(CHANGE_LOG_BLOCK_ACTION)
      await expectChangeLogValues(expect, page, 'block', '0', '1')
      await page.keyboard.press('Escape')
      await expect(modal).toHaveCount(0)
      await expect(opener).toBeFocused()
    } finally {
      await personContext.close()
    }
  },
)

test('its registration receipt shows person and identity creation as facts without personal values', async ({
  page,
}) => {
  const userId = await signUpAdmin(expect, page, { entryId: 'message-signin' })
  // The zone the browser names at sign-up rides the registration's receipt, so
  // the feed sums the person's entries without a key: the history names it.
  await gotoPage(
    expect,
    page,
    `${CHANGE_LOG_TABLES_PATH}/hilos_user/history/${userId}`,
  )
  await expectTrackedTableRecord(expect, page, 'hilos_user', String(userId))
  const receiptId = await expectRegistrationHistory(expect, page)
  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  const rowKey = await expectRegistrationFeedRow(expect, page, receiptId)
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  await expectRegistrationReceiptFacts(expect, page, userId)
  await page.getByTestId('hilos-change-log-detail-close').click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
})

test.describe('Change Log reader time zone', () => {
  test.use({ timezoneId: 'UTC' })

  test('renders the delivered own zone and returns the same receipt and feed to Automatic', async ({
    page,
  }) => {
    // This proves display of an existing session contract. The real daemon
    // still supplies the receipt and feed; backend zone writes are HIL-1580.
    const socket = await forwardSessionTimeZone(page, 'Europe/Kyiv')
    try {
      const userId = await signUpAdmin(expect, page, {
        entryId: 'message-signin',
      })
      await gotoPage(
        expect,
        page,
        `${CHANGE_LOG_TABLES_PATH}/hilos_user/history/${userId}`,
      )
      const receiptId = await expectRegistrationHistory(expect, page)
      await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
      const rowKey = await expectRegistrationFeedRow(expect, page, receiptId)
      await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
      await expectChangeLogReceiptOpen(expect, page, receiptId)
      await expect(
        page.getByTestId('hilos-change-log-receipt-time'),
      ).toBeVisible()
      const createdAt = expectChangeLogReceiptMoment(
        expect,
        socket.frames,
        receiptId,
      )
      const ownTime = await expectChangeLogTimes(
        expect,
        page,
        receiptId,
        createdAt,
        'Europe/Kyiv',
      )
      const display = await watchChangeLogDisplay(expect, page, receiptId)
      const session = socket.sessions.at(-1)
      expect(session).toMatchObject({
        data: {
          entities: { currentUser: { id: userId, timeZone: 'Europe/Kyiv' } },
        },
      })
      const sockets = socket.sockets()

      socket.replay(null)
      const automaticTime = await expectChangeLogTimes(
        expect,
        page,
        receiptId,
        createdAt,
        'UTC',
      )
      expect(automaticTime).not.toBe(ownTime)
      expect(socket.sessions.at(-1)).toMatchObject({
        data: { entities: { currentUser: { id: userId, timeZone: null } } },
      })
      expect(socket.sockets()).toBe(sockets)
      expect(
        expectChangeLogReceiptMoment(expect, socket.frames, receiptId),
      ).toBe(createdAt)
      await display.unchanged()
      await display.dispose()
    } finally {
      socket.dispose()
    }
  })
})
