import { expect, test, type Locator, type Page } from '@playwright/test'

import {
  readAnalyticsBrowserSession,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { clickSubmit, signUp, typeInto } from '../helpers/session.js'

// spec-owner: demo — real chat messages create the recorded actions.

const PAGE = 'hilos_analytics_browser_session'
const TABLE = 'hilosAnalyticsActions'
const sendCommand = createCommandChannel({
  host: process.env.COMMAND_HOST ?? 'chat-test',
  port: Number(process.env.COMMAND_PORT ?? 8094),
  timeoutMs: 5_000,
})
// A journal rotates within ten seconds; allow its writer to load that file too.
const WRITER_TIMEOUT_MS = 20_000
// Chat's ten-second re-send lockout exceeds the ordinary assertion window.
const SEND_COOLDOWN_TIMEOUT_MS = 15_000

interface ActionRow {
  rowKey: number
  createdTs: number
  pageName: string | null
  actionName: string
}

interface AnalyticsFrame {
  type: string
  data: {
    page?: string
    tableKey?: string
    rows?: Array<{
      rowKey: number
      slots: { action: Omit<ActionRow, 'rowKey'> }
    }>
    payload?: {
      data?: {
        browserSession?: {
          id: number
          browser: string
          lastSignedInUserId: number
          lastSignedInUserLabel: string
        }
      }
    }
  }
}

/** Observe only this analytics page, including the full unfiltered frame text. */
function watchAnalytics(page: Page) {
  const frames: Array<{ wire: AnalyticsFrame; raw: string }> = []
  page.on('websocket', (socket) => {
    socket.on('framereceived', ({ payload }) => {
      if (typeof payload !== 'string') return
      const wire = JSON.parse(payload) as AnalyticsFrame
      if (
        wire.data?.page === PAGE &&
        (wire.type === 'page_response' ||
          (wire.type === 'table_window' && wire.data.tableKey === TABLE))
      ) {
        frames.push({ wire, raw: payload })
      }
    })
  })
  return frames
}

/** Publish a real moderated message and wait until its composer has settled. */
async function sendMessage(page: Page, label: string): Promise<string> {
  const key = modelKey()
  const body = `${label} ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await expect(page.getByTestId('message-cooldown')).toHaveCount(0, {
    timeout: SEND_COOLDOWN_TIMEOUT_MS,
  })
  await typeInto(page.getByTestId('message-input'), body)
  await clickSubmit(page.getByTestId('message-send'))
  await expect(page.getByTestId('message-input')).toHaveValue('')
  await expect(page.getByTestId('moderation-text')).toHaveCount(0)
  await expect(
    page.getByTestId('event-text').filter({ hasText: body }),
  ).toBeVisible()
  return body
}

/** The shown copy of one session's cell on the person's sessions table. */
function sessionCell(table: Locator, testId: string, rowKey: number): Locator {
  return shownByTestId(table, testId).and(
    table.locator(`[data-record="${rowKey}"]`),
  )
}

/** Read the visible branch, preserving the source moment and row-number tie break. */
async function readRows(page: Page): Promise<ActionRow[]> {
  const table = page.getByTestId('hilos-analytics-actions-table')
  return shownByTestId(table, 'hilos-analytics-action-when').evaluateAll(
    (cells) =>
      cells.map((cell) => {
        const rowKey = cell.getAttribute('data-record')
        const row = cell.closest('tr')!
        return {
          rowKey: Number(rowKey),
          createdTs: Number(
            cell
              .querySelector('[data-created-ts]')
              ?.getAttribute('data-created-ts'),
          ),
          pageName: row
            .querySelector('[data-id="hilos-analytics-action-page"]')!
            .textContent!.trim(),
          actionName: row
            .querySelector('[data-id="hilos-analytics-action-name"]')!
            .textContent!.trim(),
        }
      }),
  )
}

test('shows real session actions in source order without bodies or another session', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  // Two registrations, moderation, the re-send lockout and rotation exceed the base cap.
  test.slow()
  const user = await signUp(page)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const bodies = [
    await sendMessage(page, 'first private analytics message'),
    await sendMessage(page, 'second private analytics message'),
  ]

  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  try {
    const admin = await adminContext.newPage()
    const frames = watchAnalytics(admin)
    const adminId = await signUpAdmin(admin)
    bodies.push(await sendMessage(admin, 'another session private message'))

    const readUser = () => readAnalyticsBrowserSession(sendCommand, user.userId)
    const readAdmin = () => readAnalyticsBrowserSession(sendCommand, adminId)
    await expect
      .poll(
        async () =>
          (await readUser()).actionNames.filter((name) => name === 'message')
            .length,
        { timeout: WRITER_TIMEOUT_MS },
      )
      .toBeGreaterThanOrEqual(2)
    await expect
      .poll(async () => (await readAdmin()).actionNames.includes('message'), {
        timeout: WRITER_TIMEOUT_MS,
      })
      .toBe(true)
    const userSession = await readUser()
    const adminSession = await readAdmin()
    expect(userSession.browserSessionId).not.toBeNull()
    expect(adminSession.browserSessionId).not.toBeNull()
    expect(userSession.browserSessionId).not.toBe(adminSession.browserSessionId)

    // The way in: the person's admin card, then their sessions in Analytics.
    await gotoPage(admin, `/hilos/user/${user.userId}`)
    await admin.getByTestId('hilos-user-analytics-link').click()
    await expect(admin).toHaveURL(
      new RegExp(`/hilos/analytics/people/${user.userId}$`),
    )
    await expectPageReady(admin)
    await expect(admin.getByTestId('hilos-admin-title')).toHaveText(
      `${user.name} (#${user.userId})`,
    )
    const sessions = admin.getByTestId('hilos-analytics-browser-sessions-table')
    const sessionLink = sessionCell(
      sessions,
      'hilos-analytics-session-link',
      userSession.browserSessionId!,
    )
    await expect(sessionLink).toHaveText(`#${userSession.browserSessionId}`)
    await expect(
      sessionCell(
        sessions,
        'hilos-analytics-session-browser',
        userSession.browserSessionId!,
      ),
    ).toContainText(`Chrome ${browserMajor}`)
    await expect(
      sessionCell(
        sessions,
        'hilos-analytics-session-actions',
        userSession.browserSessionId!,
      ),
    ).toHaveText(String(userSession.actionNames.length))
    await expect(
      sessionCell(
        sessions,
        'hilos-analytics-session-link',
        adminSession.browserSessionId!,
      ),
    ).toHaveCount(0)

    await sessionLink.click()
    await expect(admin).toHaveURL(
      new RegExp(
        `/hilos/analytics/browser-sessions/${userSession.browserSessionId}$`,
      ),
    )
    await expectPageReady(admin)
    await expect(admin.getByTestId('hilos-admin-title')).toHaveText(
      `Browser session #${userSession.browserSessionId}`,
    )
    await expect(
      admin.getByTestId('hilos-analytics-browser-session-person'),
    ).toHaveText(`Last signed in: ${user.name} (#${user.userId})`)
    await expect(
      admin.getByTestId('hilos-analytics-browser-session-browser'),
    ).toContainText(`Chrome ${browserMajor}`)
    await expect
      .poll(async () => (await readRows(admin)).map((row) => row.actionName))
      .toEqual(userSession.actionNames)

    const ascending = await readRows(admin)
    const messages = ascending.filter((row) => row.actionName === 'message')
    expect(messages).toHaveLength(2)
    expect(messages.map((row) => row.pageName)).toEqual(['main', 'main'])
    expect(messages[0].createdTs).toBeGreaterThan(0)
    expect(messages[1].createdTs).toBeGreaterThan(messages[0].createdTs)
    expect(ascending).toEqual(
      [...ascending].sort(
        (a, b) => a.createdTs - b.createdTs || a.rowKey - b.rowKey,
      ),
    )

    const header = frames.find(({ wire }) => wire.type === 'page_response')
      ?.wire.data.payload?.data?.browserSession
    expect(header).toMatchObject({
      id: userSession.browserSessionId,
      lastSignedInUserId: user.userId,
      lastSignedInUserLabel: user.name,
    })
    await expect(
      admin.getByTestId('hilos-analytics-browser-session-browser'),
    ).toHaveText(header!.browser)
    const firstWindow = frames.find(({ wire }) => wire.type === 'table_window')
    expect(
      firstWindow?.wire.data.rows?.map(({ rowKey, slots }) => ({
        rowKey: Number(rowKey),
        ...slots.action,
      })),
    ).toEqual(ascending)

    await admin.getByTestId('hilos-table-sort-createdTs').click()
    await expect.poll(() => readRows(admin)).toEqual([...ascending].reverse())

    // The observer is on the admin's analytics page only. The ordinary chat
    // legitimately delivered these bodies earlier and is not this assertion's subject.
    for (const body of bodies) {
      expect(await admin.content()).not.toContain(body)
      expect(frames.map(({ raw }) => raw).join('\n')).not.toContain(body)
    }
    expect(
      frames.filter(({ wire }) => wire.type === 'table_window').length,
    ).toBeGreaterThanOrEqual(2)

    await gotoPage(
      admin,
      `/hilos/analytics/browser-sessions/${adminSession.browserSessionId}`,
    )
    await expect
      .poll(async () => (await readRows(admin)).map((row) => row.actionName))
      .toEqual(adminSession.actionNames)
    const otherRows = await readRows(admin)
    expect(
      otherRows.filter((row) => row.actionName === 'message'),
    ).toHaveLength(1)
    const userKeys = new Set(ascending.map((row) => row.rowKey))
    expect(otherRows.some((row) => userKeys.has(row.rowKey))).toBe(false)
    await expect(
      admin.getByTestId('hilos-analytics-browser-session-person'),
    ).toContainText(`(#${adminId})`)
  } finally {
    await adminContext.close()
  }
})
