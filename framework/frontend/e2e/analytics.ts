import type { Expect, Locator, Page } from '@playwright/test'
import { shownByTestId } from './table.js'

/**
 * Analytics section checks all three kits must meet alike. Tests, their order
 * and navigation stay in each demo's spec.
 */

/** The bounded snapshot returned by the non-production analytics probe. */
export interface AnalyticsBrowserSession {
  readonly browserSessionId: number | null
  readonly actionNames: readonly string[]
}

/**
 * Read the latest loaded browser session of a user in one command round trip.
 * The caller binds the stand address and polls until the writer has caught up.
 *
 * @param sendCommand The stand's command-channel request function.
 * @param userId The last signed-in user's positive integer id.
 */
export async function readAnalyticsBrowserSession(
  sendCommand: (
    command: string,
    payload: Record<string, unknown>,
  ) => Promise<Record<string, unknown>>,
  userId: number,
): Promise<AnalyticsBrowserSession> {
  if (!Number.isSafeInteger(userId) || userId <= 0) {
    throw new Error('Analytics probe needs a positive integer userId')
  }

  const { browserSessionId, actionNames } = await sendCommand(
    'test:analytics:browser-session',
    { userId },
  )
  if (
    !(
      browserSessionId === null ||
      (typeof browserSessionId === 'number' &&
        Number.isSafeInteger(browserSessionId) &&
        browserSessionId > 0)
    ) ||
    !Array.isArray(actionNames) ||
    actionNames.length > 50 ||
    !actionNames.every((name): name is string => typeof name === 'string') ||
    (browserSessionId === null && actionNames.length !== 0)
  ) {
    throw new Error('Invalid analytics browser-session probe reply')
  }

  return { browserSessionId, actionNames }
}

/** A journal rotates within ten seconds; allow its writer to load that file too. */
export const ANALYTICS_WRITER_TIMEOUT_MS = 20_000

/**
 * Continue closes the registration and is the last action a tab records before
 * a spec moves on (authActions.ts, the `done` step). The writer loads a node's
 * journal files in order, so once it is loaded, every earlier action of the
 * same tab is loaded too.
 */
export const ANALYTICS_SIGN_UP_LAST_ACTION = 'hilos_dismiss_session_ack'

/** The registration action whose body carries the password (authActions.ts). */
export const ANALYTICS_PASSWORD_ACTION = 'hilos_complete_registration'

/** One action of a browser session, as the session page draws its row. */
export interface AnalyticsActionRow {
  rowKey: number
  createdTs: number
  pageName: string | null
  actionName: string
}

/** The frames of the browser-session page a spec observes on the socket. */
export interface AnalyticsFrame {
  type: string
  data: {
    page?: string
    tableKey?: string
    rows?: Array<{
      rowKey: number
      slots: { action: Omit<AnalyticsActionRow, 'rowKey'> }
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

/** A frame as parsed and as received, for a check on its raw text. */
export interface AnalyticsFrameLog {
  wire: AnalyticsFrame
  raw: string
}

const SESSION_PAGE = 'hilos_analytics_browser_session'
const ACTIONS_TABLE = 'hilosAnalyticsActions'
const SESSIONS_TABLE = 'hilos-analytics-browser-sessions-table'
const SESSION_LINK = 'hilos-analytics-session-link'
const SESSION_BROWSER = 'hilos-analytics-browser-session-browser'
const SESSION_PERSON = 'hilos-analytics-browser-session-person'
const ANALYTICS_LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
/**
 * The sign the section draws for an absent page name — the core's
 * `ANALYTICS_NO_VALUE` (core/src/admin/analytics/hilosAnalytics.ts). This
 * folder imports nothing of the core, so the sign is repeated here.
 */
const ANALYTICS_NO_VALUE_TEXT = '—'

/**
 * Observe the browser-session page only — its answer and the windows of its
 * actions table — keeping the full unfiltered frame text. Call it before the
 * page signs in, so that no frame of the page goes by unobserved.
 *
 * @param page The page whose socket frames are observed.
 * @returns The log the observed frames are appended to.
 */
export function watchAnalyticsFrames(page: Page): AnalyticsFrameLog[] {
  const frames: AnalyticsFrameLog[] = []
  page.on('websocket', (socket) => {
    socket.on('framereceived', ({ payload }) => {
      if (typeof payload !== 'string') return
      const wire = JSON.parse(payload) as AnalyticsFrame
      if (
        wire.data?.page === SESSION_PAGE &&
        (wire.type === 'page_response' ||
          (wire.type === 'table_window' &&
            wire.data.tableKey === ACTIONS_TABLE))
      ) {
        frames.push({ wire, raw: payload })
      }
    })
  })
  return frames
}

/**
 * Read the shown copy of the actions table, preserving the source moment and
 * the row-number tie break the page sorts by. A page cell drawing the no-value
 * sign reads as null, the way the page's frames carry an absent page name.
 *
 * @param page The page showing a browser session.
 * @returns The rows in the order they are drawn.
 */
export async function readAnalyticsActionRows(
  page: Page,
): Promise<AnalyticsActionRow[]> {
  const table = page.getByTestId('hilos-analytics-actions-table')
  return shownByTestId(table, 'hilos-analytics-action-when').evaluateAll(
    (cells, noValue) =>
      cells.map((cell) => {
        const rowKey = cell.getAttribute('data-record')
        const row = cell.closest('tr')!
        const pageName = row
          .querySelector('[data-id="hilos-analytics-action-page"]')!
          .textContent!.trim()
        return {
          rowKey: Number(rowKey),
          createdTs: Number(
            cell
              .querySelector('[data-created-ts]')
              ?.getAttribute('data-created-ts'),
          ),
          pageName: pageName === noValue ? null : pageName,
          actionName: row
            .querySelector('[data-id="hilos-analytics-action-name"]')!
            .textContent!.trim(),
        }
      }),
    ANALYTICS_NO_VALUE_TEXT,
  )
}

/**
 * The shown copy of one session's cell on the person's sessions table.
 *
 * @param table The sessions table.
 * @param testId The cell's `data-id`.
 * @param rowKey The session's row key — its browser session id.
 */
export function analyticsSessionCell(
  table: Locator,
  testId: string,
  rowKey: number,
): Locator {
  return shownByTestId(table, testId).and(
    table.locator(`[data-record="${rowKey}"]`),
  )
}

/**
 * The link of one session on the person's page, which opens that session.
 *
 * @param page The page showing a person in Analytics.
 * @param browserSessionId The session the link opens.
 */
export function analyticsSessionLink(
  page: Page,
  browserSessionId: number,
): Locator {
  return analyticsSessionCell(
    page.getByTestId(SESSIONS_TABLE),
    SESSION_LINK,
    browserSessionId,
  )
}

/** The section's dashboard card is shown and carries its catalog line. */
export async function expectAnalyticsDashboardCard(
  expect: Expect,
  page: Page,
): Promise<void> {
  const card = page.getByTestId('dashboard-card-hilos_analytics')
  await expect(card).toBeVisible()
  await expect(card).toContainText(ANALYTICS_LEAD)
}

/** The overview is open and empty, and offers the way to the users. */
export async function expectAnalyticsOverview(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Analytics')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    ANALYTICS_LEAD,
  )
  await expect(
    page.getByTestId('hilos-analytics-overview-empty'),
  ).toContainText(
    "Nothing here yet. A person's actions open from their page in the admin.",
  )
  await expect(
    page.getByTestId('hilos-analytics-overview-open-users'),
  ).toBeVisible()
}

/**
 * The person's page names them and lists the session: its link, the browser
 * it ran in, and as many actions as the probe counted.
 *
 * @param expect The spec's expect.
 * @param page The page showing a person in Analytics.
 * @param person The person the page is about.
 * @param session The person's session as the probe reports it.
 * @param browserMajor The major version of the Chrome the session ran in.
 */
export async function expectAnalyticsPersonSession(
  expect: Expect,
  page: Page,
  person: { userId: number; name: string },
  session: AnalyticsBrowserSession,
  browserMajor: string,
): Promise<void> {
  const { browserSessionId } = session
  if (browserSessionId === null) {
    throw new Error('The probe has loaded no browser session of this person')
  }
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    `${person.name} (#${person.userId})`,
  )
  const sessions = page.getByTestId(SESSIONS_TABLE)
  await expect(
    analyticsSessionCell(sessions, SESSION_LINK, browserSessionId),
  ).toHaveText(`#${browserSessionId}`)
  await expect(
    analyticsSessionCell(
      sessions,
      'hilos-analytics-session-browser',
      browserSessionId,
    ),
  ).toContainText(`Chrome ${browserMajor}`)
  await expect(
    analyticsSessionCell(
      sessions,
      'hilos-analytics-session-actions',
      browserSessionId,
    ),
  ).toHaveText(String(session.actionNames.length))
}

/**
 * The person's page lists no row of this session — somebody else's. Asserted on
 * both copies of the table: gone from the screen is not gone from the document
 * (table.ts).
 *
 * @param expect The spec's expect.
 * @param page The page showing a person in Analytics.
 * @param browserSessionId The session that must not be listed.
 */
export async function expectNoAnalyticsPersonSession(
  expect: Expect,
  page: Page,
  browserSessionId: number,
): Promise<void> {
  const sessions = page.getByTestId(SESSIONS_TABLE)
  await expect(
    sessions
      .getByTestId(SESSION_LINK)
      .and(sessions.locator(`[data-record="${browserSessionId}"]`)),
  ).toHaveCount(0)
}

/**
 * The session page names the session, who last signed in on it, and its browser.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param browserSessionId The session the page shows.
 * @param person The person who last signed in on it.
 * @param browserMajor The major version of the Chrome the session ran in.
 */
export async function expectAnalyticsSessionHeader(
  expect: Expect,
  page: Page,
  browserSessionId: number,
  person: { userId: number; name: string },
  browserMajor: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    `Browser session #${browserSessionId}`,
  )
  await expect(page.getByTestId(SESSION_PERSON)).toHaveText(
    `Last signed in: ${person.name} (#${person.userId})`,
  )
  await expect(page.getByTestId(SESSION_BROWSER)).toContainText(
    `Chrome ${browserMajor}`,
  )
}

/**
 * The actions table shows exactly these actions in source order — by the
 * moment each was recorded, then by row number — and every moment is set.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param actionNames The session's actions as the probe reports them.
 * @returns The rows as drawn, for the checks that follow.
 */
export async function expectAnalyticsActionsInOrder(
  expect: Expect,
  page: Page,
  actionNames: readonly string[],
): Promise<AnalyticsActionRow[]> {
  await expect
    .poll(async () =>
      (await readAnalyticsActionRows(page)).map((row) => row.actionName),
    )
    .toEqual(actionNames)
  const rows = await readAnalyticsActionRows(page)
  for (const row of rows) {
    expect(row.createdTs).toBeGreaterThan(0)
  }
  expect(rows).toEqual(
    [...rows].sort((a, b) => a.createdTs - b.createdTs || a.rowKey - b.rowKey),
  )
  return rows
}

/**
 * The actions table shows exactly these rows, once the page has redrawn.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param rows The rows expected, in the order they are drawn.
 */
export async function expectAnalyticsActionRows(
  expect: Expect,
  page: Page,
  rows: readonly AnalyticsActionRow[],
): Promise<void> {
  await expect.poll(() => readAnalyticsActionRows(page)).toEqual(rows)
}

/**
 * What the page's frames carried: its answer names the session and the person,
 * the browser on screen is the one the answer carried, and the first window of
 * the actions table is what the screen drew.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param frames The frames observed on this page since before it signed in.
 * @param browserSessionId The session the page shows.
 * @param person The person who last signed in on it.
 * @param rows The rows the screen drew, in order.
 */
export async function expectAnalyticsSessionFrames(
  expect: Expect,
  page: Page,
  frames: readonly AnalyticsFrameLog[],
  browserSessionId: number,
  person: { userId: number; name: string },
  rows: readonly AnalyticsActionRow[],
): Promise<void> {
  const header = frames.find(({ wire }) => wire.type === 'page_response')?.wire
    .data.payload?.data?.browserSession
  if (header === undefined) {
    throw new Error('No answer of the browser-session page was observed')
  }
  expect(header).toMatchObject({
    id: browserSessionId,
    lastSignedInUserId: person.userId,
    lastSignedInUserLabel: person.name,
  })
  await expect(page.getByTestId(SESSION_BROWSER)).toHaveText(header.browser)
  const firstWindow = frames.find(({ wire }) => wire.type === 'table_window')
  expect(
    firstWindow?.wire.data.rows?.map(({ rowKey, slots }) => ({
      rowKey: Number(rowKey),
      ...slots.action,
    })),
  ).toEqual(rows)
}

/**
 * No action body reached the page: none of the texts is in the document or in
 * the raw text of any observed frame — and the table answered at least twice,
 * so there were frames to search.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param frames The frames observed on this page.
 * @param bodies The texts that must not have arrived.
 */
export async function expectNoAnalyticsBodies(
  expect: Expect,
  page: Page,
  frames: readonly AnalyticsFrameLog[],
  bodies: readonly string[],
): Promise<void> {
  const content = await page.content()
  const raw = frames.map((frame) => frame.raw).join('\n')
  for (const body of bodies) {
    expect(content).not.toContain(body)
    expect(raw).not.toContain(body)
  }
  expect(
    frames.filter(({ wire }) => wire.type === 'table_window').length,
  ).toBeGreaterThanOrEqual(2)
}

/**
 * The page shows another session — its own actions, none of the foreign rows —
 * and names the person who last signed in on it. The spec opens the page.
 *
 * @param expect The spec's expect.
 * @param page The page showing the other browser session.
 * @param session The other session as the probe reports it.
 * @param userId The person who last signed in on it.
 * @param foreignRows The rows of the session shown before; none may recur.
 */
export async function expectOtherAnalyticsSession(
  expect: Expect,
  page: Page,
  session: AnalyticsBrowserSession,
  userId: number,
  foreignRows: readonly AnalyticsActionRow[],
): Promise<void> {
  const rows = await expectAnalyticsActionsInOrder(
    expect,
    page,
    session.actionNames,
  )
  const foreignKeys = new Set(foreignRows.map((row) => row.rowKey))
  expect(rows.some((row) => foreignKeys.has(row.rowKey))).toBe(false)
  await expect(page.getByTestId(SESSION_PERSON)).toContainText(`(#${userId})`)
}
