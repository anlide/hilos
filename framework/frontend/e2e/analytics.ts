import type { Expect, Locator, Page } from '@playwright/test'
import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { expectReaderMillisecondMoment } from './moments.js'
import { shownByTestId } from './table.js'
import { watchSocketFrames, type SocketFrame } from './socket.js'

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

/** One complete overview reply, kept separate from the production projection. */
export interface AnalyticsOverviewSnapshot {
  readonly timeZone: string
  readonly weekStartTs: number
  readonly dayStartTs: number
  readonly hourStartTs: number
  readonly asOfTs: number
  readonly visitorsThisWeek: number
  readonly visitorsToday: number
  readonly visitorsThisHour: number
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

const PEOPLE_PAGE = 'hilos_analytics_people'
const PEOPLE_TABLE = 'hilosAnalyticsPeople'
const PEOPLE_HANDLE = 'hilos-analytics-people-table'

export const ANALYTICS_SESSION_LIST_PATH = '/hilos/analytics/browser-sessions'
export const ANALYTICS_SESSION_LIST_HANDLE =
  'hilos-analytics-browser-session-list-table'
const SESSION_LIST_PAGE = 'hilos_analytics_browser_sessions'
const SESSION_LIST_TABLE = 'hilosAnalyticsBrowserSessionList'
const SESSION_LIST_LINK = 'hilos-analytics-browser-session-list-session'
const peopleSurface = {
  page: PEOPLE_PAGE,
  table: PEOPLE_TABLE,
  handle: PEOPLE_HANDLE,
  link: 'hilos-analytics-person-link',
}
const sessionListSurface = {
  page: SESSION_LIST_PAGE,
  table: SESSION_LIST_TABLE,
  handle: ANALYTICS_SESSION_LIST_HANDLE,
  link: SESSION_LIST_LINK,
}

/** Wait on the real journal writer, using the existing bounded stand probe. */
export async function waitAnalyticsBrowserSession(
  expect: Expect,
  sendCommand: Parameters<typeof readAnalyticsBrowserSession>[0],
  userId: number,
): Promise<AnalyticsBrowserSession> {
  await expect
    .poll(
      async () =>
        (
          await readAnalyticsBrowserSession(sendCommand, userId)
        ).actionNames.includes(ANALYTICS_SIGN_UP_LAST_ACTION),
      { timeout: ANALYTICS_WRITER_TIMEOUT_MS },
    )
    .toBe(true)
  const session = await readAnalyticsBrowserSession(sendCommand, userId)
  expect(session.browserSessionId).not.toBeNull()
  return session
}

/** Real requests for one table surface, in the order the browser sent them. */
function analyticsViewports(
  log: DeliveryFrameLog,
  surface: typeof peopleSurface,
) {
  return log.sent.filter(({ wire }) => {
    const request = deliveryObject(wire)
    return (
      request.type === 'table_viewport' &&
      request.page === surface.page &&
      request.tableKey === surface.table
    )
  })
}

/**
 * Settle a table request on its correlated daemon reply and drawn row keys.
 * The returned filter is the actual captured context, independent of the core.
 */
async function expectAnalyticsWindow(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  surface: typeof peopleSurface,
  period: 'today' | 'this-hour' | 'this-week' | undefined,
  after = 0,
) {
  const latest = () => {
    const sent = log.sent
      .slice(after)
      .filter(({ wire }) => {
        const request = deliveryObject(wire)
        return (
          request.type === 'table_viewport' &&
          request.page === surface.page &&
          request.tableKey === surface.table
        )
      })
      .at(-1)
    const request = deliveryObject(sent?.wire)
    if (!sent || typeof request.requestId !== 'string') return undefined
    const frame = log.packets
      .filter((packet) => packet.socket === sent.socket && packet.at >= sent.at)
      .flatMap((packet) => packet.frames)
      .find(({ wire }) => {
        const response = deliveryObject(wire)
        const data = deliveryObject(response.data)
        return (
          response.type === 'table_window' &&
          data.page === surface.page &&
          data.tableKey === surface.table &&
          data.requestId === request.requestId
        )
      })
    return frame ? { request, frame } : undefined
  }
  await expect.poll(latest).toBeDefined()
  const { request, frame } = latest()!
  expect(frame.failure).toBeUndefined()
  const window = deliveryObject(deliveryObject(frame.wire).data)
  expect(window.rows).toEqual(expect.any(Array))
  const rows = (window.rows as unknown[]).map(deliveryObject)
  for (const row of rows) {
    expect(Number.isSafeInteger(row.rowKey)).toBe(true)
    expect(row.rowKey).toBeGreaterThan(0)
  }
  const filter = deliveryObject(request.filter)
  const context = {
    timeZone: filter.timeZone,
    weekStartTs: filter.weekStartTs,
    dayStartTs: filter.dayStartTs,
    hourStartTs: filter.hourStartTs,
  }
  expect(filter).toEqual({ ...context, ...(period ? { period } : {}) })
  expect(context.timeZone).toEqual(expect.any(String))
  for (const moment of [
    context.weekStartTs,
    context.dayStartTs,
    context.hourStartTs,
  ]) {
    expect(Number.isSafeInteger(moment)).toBe(true)
    expect(moment).toBeGreaterThan(0)
  }
  const table = page.getByTestId(surface.handle)
  await expect(shownByTestId(table, 'hilos-table-loading')).toHaveCount(0)
  await expect
    .poll(() =>
      shownByTestId(table, surface.link).evaluateAll((links) =>
        links.map((link) => Number(link.getAttribute('data-record'))),
      ),
    )
    .toEqual(rows.map((row) => row.rowKey))
  return { request, window, rows, context }
}

/** Real People viewport requests in send order. */
export function analyticsPeopleViewports(log: DeliveryFrameLog) {
  return analyticsViewports(log, peopleSurface)
}

/** Real browser-session list viewport requests in send order. */
export function analyticsSessionListViewports(log: DeliveryFrameLog) {
  return analyticsViewports(log, sessionListSurface)
}

/** Settle People on its correlated reply and drawn row keys. */
export function expectAnalyticsPeopleWindow(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  period: 'today' | 'this-hour' | 'this-week' | undefined,
  after = 0,
) {
  return expectAnalyticsWindow(expect, page, log, peopleSurface, period, after)
}

/** Settle the list on its correlated reply and drawn public session keys. */
export function expectAnalyticsSessionListWindow(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  period: 'today' | 'this-hour' | 'this-week' | undefined,
  after = 0,
) {
  return expectAnalyticsWindow(
    expect,
    page,
    log,
    sessionListSurface,
    period,
    after,
  )
}

/** Address a visible session cell by its public envelope key. */
export function analyticsSessionListCell(
  page: Page,
  field: string,
  rowKey: number,
): Locator {
  return analyticsSessionCell(
    page.getByTestId(ANALYTICS_SESSION_LIST_HANDLE),
    `hilos-analytics-browser-session-list-${field}`,
    rowKey,
  )
}

/** Match a real session's personal cells to its served window. */
export async function expectAnalyticsSessionListRow(
  expect: Expect,
  page: Page,
  settled: Awaited<ReturnType<typeof expectAnalyticsSessionListWindow>>,
  sessionId: number,
  person: { userId: number; name: string },
  browserMajor: string,
): Promise<void> {
  const row = settled.rows.find((row) => row.rowKey === sessionId)
  expect(row).toBeDefined()
  const value = deliveryObject(deliveryObject(row!.slots).browserSession)
  expect(value.lastSignedInUserId).toBe(person.userId)
  expect(value.lastSignedInUserName).toBe(person.name)
  const link = analyticsSessionListCell(page, 'session', sessionId)
  await expect(link).toHaveText(`#${sessionId}`)
  await expect(link).toHaveAttribute(
    'href',
    `${ANALYTICS_SESSION_LIST_PATH}/${sessionId}`,
  )
  const signedIn = analyticsSessionListCell(page, 'person', sessionId)
  await expect(signedIn).toHaveText(`${person.name} (#${person.userId})`)
  await expect(signedIn.getByRole('link')).toHaveAttribute(
    'href',
    `/hilos/analytics/people/${person.userId}`,
  )
  expect(value.browser).toContain(`Chrome ${browserMajor}`)
  await expect(analyticsSessionListCell(page, 'browser', sessionId)).toHaveText(
    String(value.browser),
  )
  for (const [field, key] of [
    ['first-seen', 'firstSeenTs'],
    ['last-seen', 'lastSeenTs'],
  ] as const) {
    expect(value[key]).toBeGreaterThan(0)
    await expectReaderMillisecondMoment(
      expect,
      analyticsSessionListCell(page, field, sessionId),
      Number(value[key]),
      String(settled.context.timeZone),
    )
  }
  expect(value.actionCount).toBeGreaterThan(0)
  await expect(analyticsSessionListCell(page, 'actions', sessionId)).toHaveText(
    String(value.actionCount),
  )
}

/** The legacy session title and return link, independent of entity-head adoption. */
export async function expectAnalyticsSessionReturn(
  expect: Expect,
  page: Page,
  sessionId: number,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    `Browser session #${sessionId}`,
  )
  await expect(
    page.getByTestId('hilos-breadcrumb').getByRole('link'),
  ).toHaveText(['Hilos', 'Analytics', 'Browser sessions'])
  await expect(page.getByTestId('hilos-breadcrumb').locator('li')).toHaveText([
    'Hilos',
    'Analytics',
    'Browser sessions',
    `Browser session #${sessionId}`,
  ])
  await expect(
    page.getByTestId('hilos-breadcrumb-hilos_analytics_browser_sessions'),
  ).toHaveAttribute('href', ANALYTICS_SESSION_LIST_PATH)
}

/** The public numeric address of a person in the current People window. */
export function analyticsPersonLink(page: Page, userId: number): Locator {
  return analyticsSessionCell(
    page.getByTestId(PEOPLE_HANDLE),
    'hilos-analytics-person-link',
    userId,
  )
}

/** The explicit kit's served tabs, in catalog order, and section crumbs. */
export async function expectAnalyticsSectionHead(
  expect: Expect,
  page: Page,
  current: 'overview' | 'sessions' | 'people',
  kit: 'vue' | 'react' = 'vue',
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Analytics')
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName('Analytics pages')
  await expect(tabs.getByRole('link')).toHaveText(
    kit === 'vue'
      ? ['Overview', 'Browser sessions', 'People']
      : ['Overview', 'People'],
  )
  for (const [key, path, active] of [
    ['hilos_analytics', '/hilos/analytics', current === 'overview'],
    ...(kit === 'vue'
      ? ([
          [
            SESSION_LIST_PAGE,
            ANALYTICS_SESSION_LIST_PATH,
            current === 'sessions',
          ],
        ] as const)
      : []),
    [PEOPLE_PAGE, '/hilos/analytics/people', current === 'people'],
  ] as const) {
    const link = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(link).toHaveAttribute('href', path)
    if (active) await expect(link).toHaveAttribute('aria-current', 'page')
    else await expect(link).not.toHaveAttribute('aria-current')
  }
  await expect(page.getByTestId('hilos-breadcrumb').locator('li')).toHaveText([
    'Hilos',
    'Analytics',
  ])
}

/** A real signed-in person's period count and moment agree with the served row. */
export async function expectAnalyticsPeoplePerson(
  expect: Expect,
  page: Page,
  settled: Awaited<ReturnType<typeof expectAnalyticsPeopleWindow>>,
  person: { userId: number; name: string },
): Promise<void> {
  const row = settled.rows.find((row) => row.rowKey === person.userId)
  expect(row).toBeDefined()
  const value = deliveryObject(deliveryObject(row!.slots).person)
  expect(value.name).toBe(person.name)
  // This fresh account signed into exactly one real browser session.
  expect(value.sessionCount).toBe(1)
  const table = page.getByTestId(PEOPLE_HANDLE)
  await expect(analyticsPersonLink(page, person.userId)).toHaveText(
    `${person.name} (#${person.userId})`,
  )
  await expect(analyticsPersonLink(page, person.userId)).toHaveAttribute(
    'href',
    `/hilos/analytics/people/${person.userId}`,
  )
  await expect(
    analyticsSessionCell(
      table,
      'hilos-analytics-person-sessions',
      person.userId,
    ),
  ).toHaveText('1')
  expect(value.lastSeenTs).toBeGreaterThan(0)
  await expectReaderMillisecondMoment(
    expect,
    analyticsSessionCell(
      table,
      'hilos-analytics-person-last-seen',
      person.userId,
    ),
    value.lastSeenTs,
    String(settled.context.timeZone),
  )
}

/** Period choices show the daemon's four facet counts, through standard handles. */
async function expectAnalyticsFacets(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  surface: typeof peopleSurface,
): Promise<void> {
  const latest = () =>
    log.packets
      .flatMap((packet) => packet.frames)
      .filter(({ wire }) => {
        const response = deliveryObject(wire)
        const data = deliveryObject(response.data)
        return (
          response.type === 'table_facet_counts' &&
          data.page === surface.page &&
          data.tableKey === surface.table
        )
      })
      .at(-1)
  await expect.poll(latest).toBeDefined()
  expect(latest()!.failure).toBeUndefined()
  const data = deliveryObject(deliveryObject(latest()!.wire).data)
  const facets = deliveryObject(deliveryObject(data.facets).period)
  const options = deliveryObject(facets.options)
  const control = page.getByTestId('hilos-table-filter-period')
  await expect(control.getByRole('listbox')).toHaveAccessibleName('Period')
  for (const [index, key, label] of [
    [-1, 'any', 'Any time'],
    [0, 'this-hour', 'This hour'],
    [1, 'today', 'Today'],
    [2, 'this-week', 'This week'],
  ] as const) {
    const count = deliveryObject(key === 'any' ? facets.any : options[key])
    expect(Number.isSafeInteger(count.count)).toBe(true)
    expect(count.count).toBeGreaterThanOrEqual(1)
    expect(count.exact).toBe(true)
    await expect(
      control.getByTestId(`hilos-dropdown-option-${index}`),
    ).toContainText(label)
    await expect(
      control.getByTestId(`hilos-table-facet-period-${key}`),
    ).toHaveText(String(count.count))
  }
}

/** Match People Period counts to the daemon's facets. */
export function expectAnalyticsPeopleFacets(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
) {
  return expectAnalyticsFacets(expect, page, log, peopleSurface)
}

/** Match session-list facets and their captured context to the viewport. */
export async function expectAnalyticsSessionListFacets(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  context: Record<string, unknown>,
): Promise<void> {
  await expectAnalyticsFacets(expect, page, log, sessionListSurface)
  const requests = log.sent
    .map(({ wire }) => deliveryObject(wire))
    .filter(
      (wire) =>
        (wire.type === 'table_facets' || wire.type === 'table_viewport') &&
        wire.page === SESSION_LIST_PAGE &&
        wire.facets !== undefined,
    )
  expect(requests.length).toBeGreaterThan(0)
  for (const request of requests) {
    expect(request.tableKey).toBe(SESSION_LIST_TABLE)
    expect(request.facets).toEqual({
      period: ['this-hour', 'today', 'this-week'],
    })
  }
  const first = analyticsSessionListViewports(log)[0]
  expect(first).toBeDefined()
  expect(deliveryObject(deliveryObject(first!.wire).filter)).toEqual({
    ...context,
    period: 'today',
  })
}

/** One h1 names the list table; Last seen exposes the served sort direction. */
export async function expectAnalyticsSessionListAccessibility(
  expect: Expect,
  page: Page,
  direction: 'ascending' | 'descending',
): Promise<void> {
  await expectAnalyticsSectionHead(expect, page, 'sessions')
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  const table = page
    .getByTestId(ANALYTICS_SESSION_LIST_HANDLE)
    .getByRole('table', { name: 'Analytics', exact: true })
  await expect(table).toBeVisible()
  await expect(table).toHaveAttribute(
    'aria-labelledby',
    (await heading.getAttribute('id'))!,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  await expect(
    table.getByRole('columnheader', { name: 'Last seen' }),
  ).toHaveAttribute('aria-sort', direction)
  await expect(
    table.getByTestId('hilos-table-sort-lastSeenTs'),
  ).toHaveAccessibleName('Last seen')
}

/** People uses the one h1 as its table name and exposes its live sort direction. */
export async function expectAnalyticsPeopleAccessibility(
  expect: Expect,
  page: Page,
  direction: 'ascending' | 'descending',
): Promise<void> {
  await expectAnalyticsSectionHead(expect, page, 'people')
  const table = page
    .getByTestId(PEOPLE_HANDLE)
    .getByRole('table', { name: 'Analytics', exact: true })
  await expect(table).toBeVisible()
  await expect(table).toHaveAttribute(
    'aria-labelledby',
    (await page
      .getByRole('heading', { level: 1 })
      .getAttribute('id')) as string,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  await expect(
    table.getByRole('columnheader', { name: 'Last seen' }),
  ).toHaveAttribute('aria-sort', direction)
  await expect(
    table.getByTestId('hilos-table-sort-lastSeenTs'),
  ).toHaveAccessibleName('Last seen')
}

/** Person has one entity head, no tab row, and an all-time People return link. */
export async function expectAnalyticsPersonNavigation(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  person: { userId: number; name: string },
): Promise<void> {
  const head = page.getByTestId('hilos-page-head')
  await expect(head).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(head.getByRole('heading', { level: 1 })).toHaveText(
    `${person.name} (#${person.userId})`,
  )
  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByRole('tablist')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId('hilos-breadcrumb').locator('li')).toHaveText([
    'Hilos',
    'Analytics',
    'People',
    `${person.name} (#${person.userId})`,
  ])
  await expect(
    page.getByTestId('hilos-breadcrumb-hilos_analytics_people'),
  ).toHaveAttribute('href', '/hilos/analytics/people')
  const requests = log.sent
    .map(({ wire }) => deliveryObject(wire))
    .filter((wire) => wire.page === 'hilos_analytics_person')
  const subscribe = requests.find((wire) => wire.type === 'page_subscribe')
  expect(subscribe).toBeDefined()
  expect(subscribe!.params).toEqual({ userId: String(person.userId) })
  for (const request of requests) {
    expect(JSON.stringify(request)).not.toContain('period')
    if (request.type === 'table_viewport')
      expect(request.filter).toEqual({ user: person.userId })
  }
  await expect(page.getByTestId('hilos-table-filter-period')).toHaveCount(0)
}

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
  watchSocketFrames(page, (frame) => {
    if (frame.raw === undefined) return
    if (frame.wire === undefined) {
      throw new Error(`Invalid Analytics socket evidence: ${frame.raw}`)
    }
    const wire = frame.wire as AnalyticsFrame | null
    if (
      wire?.data?.page === SESSION_PAGE &&
      (wire.type === 'page_response' ||
        (wire.type === 'table_window' && wire.data.tableKey === ACTIONS_TABLE))
    ) {
      frames.push({ wire, raw: frame.raw })
    }
  })
  return frames
}

/**
 * Read the first Analytics overview reply from this opening's observations.
 * The caller bookmarks the log before navigation and passes only later frames.
 * A missing or malformed full snapshot fails; separate replies never merge.
 *
 * @param frames Incoming observations since this opening, including batch inners.
 */
export function readAnalyticsOverviewSnapshot(
  frames: readonly SocketFrame[],
): AnalyticsOverviewSnapshot {
  const frame = frames.find(({ wire }) => {
    const response = deliveryObject(wire)
    return (
      response.type === 'page_response' &&
      deliveryObject(response.data).page === 'hilos_analytics'
    )
  })
  if (frame === undefined) {
    throw new Error('No Analytics overview page_response was observed')
  }
  if (frame.failure !== undefined || frame.raw === undefined) {
    throw new Error('Invalid Analytics overview socket evidence')
  }
  const data = deliveryObject(deliveryObject(frame.wire).data)
  const pageData = deliveryObject(deliveryObject(data.payload).data)
  const snapshot = deliveryObject(
    pageData.analyticsOverview,
  ) as Partial<AnalyticsOverviewSnapshot>
  if (
    typeof snapshot.timeZone !== 'string' ||
    snapshot.timeZone.length === 0 ||
    snapshot.timeZone.trim() !== snapshot.timeZone ||
    /^[+\-\u2212]/.test(snapshot.timeZone) ||
    ![
      snapshot.weekStartTs,
      snapshot.dayStartTs,
      snapshot.hourStartTs,
      snapshot.asOfTs,
    ].every(
      (moment) =>
        typeof moment === 'number' &&
        Number.isSafeInteger(moment) &&
        moment >= 0 &&
        new Date(moment).getTime() === moment,
    ) ||
    ![
      snapshot.visitorsThisWeek,
      snapshot.visitorsToday,
      snapshot.visitorsThisHour,
    ].every(
      (count) =>
        typeof count === 'number' && Number.isSafeInteger(count) && count >= 0,
    )
  ) {
    throw new Error('Invalid or incomplete Analytics overview snapshot')
  }
  const complete = snapshot as AnalyticsOverviewSnapshot
  if (
    complete.weekStartTs > complete.dayStartTs ||
    complete.dayStartTs > complete.hourStartTs ||
    complete.hourStartTs > complete.asOfTs ||
    complete.visitorsThisWeek < complete.visitorsToday ||
    complete.visitorsToday < complete.visitorsThisHour
  ) {
    throw new Error('Inconsistent Analytics overview snapshot periods')
  }
  // Reject unusable IANA names independently of the core's snapshot schema.
  new Intl.DateTimeFormat('en-GB', { timeZone: complete.timeZone })
  return complete
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

/** The overview shows its explicit kit body under the shared catalog identity. */
export async function expectAnalyticsOverview(
  expect: Expect,
  page: Page,
  kind: 'tiles' | 'empty' = 'empty',
  kit: 'legacy' | 'vue' = 'legacy',
): Promise<void> {
  await expect(
    page.getByRole('heading', { name: 'Analytics', exact: true }),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    ANALYTICS_LEAD,
  )
  if (kind === 'tiles') {
    const tiles = page.getByTestId('hilos-analytics-overview-tiles')
    await expect(tiles).toHaveCount(1)
    await expect(tiles).toBeVisible()
    await expect(page.locator('h1')).toHaveCount(1)
    await expect(page.getByTestId('hilos-page-head')).toHaveCount(1)
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Analytics',
    )
    const tabs = page.getByTestId('hilos-page-tabs')
    if (kit === 'vue') {
      await expectAnalyticsSectionHead(expect, page, 'overview')
    } else {
      await expect(tabs).toHaveCount(0)
      const crumbs = page.getByTestId('hilos-breadcrumb')
      await expect(crumbs.getByRole('link')).toHaveText(['Hilos'])
      await expect(crumbs.locator('[aria-current="page"]')).toContainText(
        'Analytics',
      )
    }
    for (const testId of [
      'hilos-admin-title',
      'hilos-admin-children',
      'hilos-admin-empty',
      'hilos-analytics-overview-empty',
      'hilos-analytics-overview-open-users',
      'hilos-analytics-overview-skeleton',
    ]) {
      await expect(page.getByTestId(testId)).toHaveCount(0)
    }
    await expect(
      page.getByRole('heading', { name: 'Overview', exact: true }),
    ).toHaveCount(0)
    await expect(tiles.getByRole('link')).toHaveCount(kit === 'vue' ? 3 : 0)
    if (kit === 'vue') {
      for (const [key, period] of [
        ['week', 'this-week'],
        ['day', 'today'],
        ['hour', 'this-hour'],
      ] as const) {
        await expect(
          tiles.getByTestId(`hilos-analytics-visitors-${key}-link`),
        ).toHaveAttribute('href', `${ANALYTICS_SESSION_LIST_PATH}/${period}`)
      }
    }
    for (const period of ['week', 'day', 'hour']) {
      await expect(
        tiles.getByTestId(`hilos-analytics-visitors-${period}`),
      ).toBeVisible()
    }
    return
  }
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
 * Match all three tiles to one real reply, including zeros and its captured
 * calendar context. Native Intl expectations do not use the core's formatter.
 *
 * @param expect The spec's expect.
 * @param page The page showing the ready overview.
 * @param snapshot The complete reply observed for this opening.
 * @param browserTimeZone The browser's resolved IANA name before opening.
 */
export async function expectAnalyticsOverviewTiles(
  expect: Expect,
  page: Page,
  snapshot: AnalyticsOverviewSnapshot,
  browserTimeZone: string,
): Promise<void> {
  const canonicalZone = await page.evaluate(
    (timeZone) =>
      new Intl.DateTimeFormat('en-GB', { timeZone }).resolvedOptions().timeZone,
    snapshot.timeZone,
  )
  expect(canonicalZone).toBe(browserTimeZone)

  const time = new Intl.DateTimeFormat('en-GB', {
    timeZone: snapshot.timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  })
  const weekday = new Intl.DateTimeFormat('en-GB', {
    timeZone: snapshot.timeZone,
    weekday: 'long',
  }).format(snapshot.weekStartTs)
  const periods = [
    {
      key: 'week',
      label: 'Visitors this week',
      count: snapshot.visitorsThisWeek,
      since: `${weekday}, ${time.format(snapshot.weekStartTs)}`,
    },
    {
      key: 'day',
      label: 'Visitors today',
      count: snapshot.visitorsToday,
      since: time.format(snapshot.dayStartTs),
    },
    {
      key: 'hour',
      label: 'Visitors this hour',
      count: snapshot.visitorsThisHour,
      since: time.format(snapshot.hourStartTs),
    },
  ]
  for (const { key, label, count, since } of periods) {
    const tile = page.getByTestId(`hilos-analytics-visitors-${key}`)
    await expect(tile.locator('dt')).toHaveText(label)
    await expect(
      tile.getByTestId(`hilos-analytics-visitors-${key}-count`),
    ).toHaveText(String(count))
    await expect(
      tile.getByTestId(`hilos-analytics-visitors-${key}-since`),
    ).toHaveText(`browser sessions since ${since}`)
  }
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
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
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
 * Every row the actions table draws stands on this page.
 *
 * @param expect The spec's expect.
 * @param page The page showing a browser session.
 * @param pageName The page every shown action ran on.
 */
export async function expectAnalyticsActionsOnPage(
  expect: Expect,
  page: Page,
  pageName: string,
): Promise<void> {
  const rows = await readAnalyticsActionRows(page)
  const expected = pageName
  expect(rows.length).toBeGreaterThan(0)
  expect(
    rows.map(({ actionName, pageName }) => ({ actionName, pageName })),
  ).toEqual(rows.map(({ actionName }) => ({ actionName, pageName: expected })))
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

/** Viewer receives hidden personal fields and only public session addresses. */
export async function expectAnalyticsSessionListViewer(
  expect: Expect,
  page: Page,
  settled: Awaited<ReturnType<typeof expectAnalyticsSessionListWindow>>,
): Promise<void> {
  expect(settled.rows.length).toBeGreaterThan(0)
  const table = page.getByTestId(ANALYTICS_SESSION_LIST_HANDLE)
  for (const [key, row] of [
    ['firstAnchor', settled.rows[0]],
    ['lastAnchor', settled.rows.at(-1)],
  ] as const) {
    expect(settled.window[key]).toEqual({ rowKey: row!.rowKey })
  }
  for (const row of settled.rows) {
    expect(Object.keys(row).sort()).toEqual(['rowKey', 'slots'])
    expect(Object.keys(deliveryObject(row.slots))).toEqual(['browserSession'])
    expect(deliveryObject(row.slots).browserSession).toEqual({
      lastSignedInUserId: { _hidden: true },
      lastSignedInUserName: { _hidden: true },
      browser: { _hidden: true },
      firstSeenTs: { _hidden: true },
      lastSeenTs: { _hidden: true },
      actionCount: { _hidden: true },
    })
    const id = Number(row.rowKey)
    for (const field of [
      'person',
      'browser',
      'first-seen',
      'last-seen',
      'actions',
    ]) {
      const cell = analyticsSessionListCell(page, field, id)
      await expect(cell).toHaveText('Hidden')
      await expect(cell.getByRole('link')).toHaveCount(0)
      await expect(
        cell.locator('[data-created-ts], [data-last-seen-ts], [title]'),
      ).toHaveCount(0)
    }
    await expect(analyticsSessionListCell(page, 'session', id)).toHaveAttribute(
      'href',
      `${ANALYTICS_SESSION_LIST_PATH}/${id}`,
    )
  }
  await expect(shownByTestId(table, SESSION_LIST_LINK)).toHaveCount(
    settled.rows.length,
  )
  const hrefs = await table
    .getByRole('link')
    .evaluateAll((links) => links.map((link) => link.getAttribute('href')))
  expect(hrefs).toEqual(
    settled.rows.map((row) => `${ANALYTICS_SESSION_LIST_PATH}/${row.rowKey}`),
  )
}
