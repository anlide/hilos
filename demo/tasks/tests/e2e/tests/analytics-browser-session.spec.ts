import { expect, test } from '@playwright/test'

import {
  PASSWORD,
  signUp,
  ANALYTICS_SIGN_UP_LAST_ACTION,
  ANALYTICS_WRITER_TIMEOUT_MS,
  analyticsSessionLink,
  expectAnalyticsActionRows,
  expectAnalyticsActionsInOrder,
  expectAnalyticsActionsOnPage,
  expectAnalyticsPersonSession,
  expectAnalyticsSessionFrames,
  expectAnalyticsSessionHeader,
  expectNoAnalyticsBodies,
  expectNoAnalyticsPersonSession,
  expectOtherAnalyticsSession,
  readAnalyticsBrowserSession,
  watchAnalyticsFrames,
  expectPageReady,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// What the Analytics section proves, the same on every kit: a person's card leads
// to their browser sessions, a session lists its actions in source order, and no
// action body — the address or the password of a registration — reaches the
// screen or the frames. The actions are the registration of the person and of
// the administrator, because tasks and polls declare no actions of their own.

const sendCommand = createCommandChannel({
  host: process.env.COMMAND_HOST ?? 'tasks-daemon-test',
  port: Number(process.env.COMMAND_PORT ?? 8094),
  timeoutMs: 5_000,
})

test('shows real session actions in source order without bodies or another session', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  // Two registrations and the wait on the journal writer exceed the base cap.
  test.slow()
  const user = await signUp(expect, page)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const bodies = [user.email, PASSWORD]

  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  try {
    const admin = await adminContext.newPage()
    const frames = watchAnalyticsFrames(admin)
    const adminId = await signUpAdmin(expect, admin)

    const readUser = () => readAnalyticsBrowserSession(sendCommand, user.userId)
    const readAdmin = () => readAnalyticsBrowserSession(sendCommand, adminId)
    await expect
      .poll(
        async () =>
          (await readUser()).actionNames.includes(
            ANALYTICS_SIGN_UP_LAST_ACTION,
          ),
        { timeout: ANALYTICS_WRITER_TIMEOUT_MS },
      )
      .toBe(true)
    await expect
      .poll(
        async () =>
          (await readAdmin()).actionNames.includes(
            ANALYTICS_SIGN_UP_LAST_ACTION,
          ),
        { timeout: ANALYTICS_WRITER_TIMEOUT_MS },
      )
      .toBe(true)
    const userSession = await readUser()
    const adminSession = await readAdmin()
    expect(userSession.browserSessionId).not.toBeNull()
    expect(adminSession.browserSessionId).not.toBeNull()
    expect(userSession.browserSessionId).not.toBe(adminSession.browserSessionId)

    // The way in: the person's admin card, then their sessions in Analytics.
    await gotoPage(expect, admin, `/hilos/user/${user.userId}`)
    await admin.getByTestId('hilos-user-analytics-link').click()
    await expect(admin).toHaveURL(
      new RegExp(`/hilos/analytics/people/${user.userId}$`),
    )
    await expectPageReady(expect, admin)
    await expectAnalyticsPersonSession(
      expect,
      admin,
      user,
      userSession,
      browserMajor!,
    )
    await expectNoAnalyticsPersonSession(
      expect,
      admin,
      adminSession.browserSessionId!,
    )

    await analyticsSessionLink(admin, userSession.browserSessionId!).click()
    await expect(admin).toHaveURL(
      new RegExp(
        `/hilos/analytics/browser-sessions/${userSession.browserSessionId}$`,
      ),
    )
    await expectPageReady(expect, admin)
    await expectAnalyticsSessionHeader(
      expect,
      admin,
      userSession.browserSessionId!,
      user,
      browserMajor!,
    )
    const ascending = await expectAnalyticsActionsInOrder(
      expect,
      admin,
      userSession.actionNames,
    )
    // The whole registration ran on the main page: every action stands on it.
    await expectAnalyticsActionsOnPage(expect, admin, 'main')

    await expectAnalyticsSessionFrames(
      expect,
      admin,
      frames,
      userSession.browserSessionId!,
      user,
      ascending,
    )

    await admin.getByTestId('hilos-table-sort-createdTs').click()
    await expectAnalyticsActionRows(expect, admin, [...ascending].reverse())

    // The observer is on the admin's analytics page only: the registration
    // screens legitimately carried these bodies earlier, in the other context.
    await expectNoAnalyticsBodies(expect, admin, frames, bodies)

    await gotoPage(
      expect,
      admin,
      `/hilos/analytics/browser-sessions/${adminSession.browserSessionId}`,
    )
    await expectOtherAnalyticsSession(
      expect,
      admin,
      adminSession,
      adminId,
      ascending,
    )
  } finally {
    await adminContext.close()
  }
})
