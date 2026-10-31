import { expect, test } from '@playwright/test'

import {
  PASSWORD,
  uniqueEmail,
  login,
  openSignIn,
  waitAnalyticsBrowserSession,
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
  watchAnalyticsFrames,
  expectPageReady,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/index.js'

// What the Analytics section proves, the same on every kit: a person's card leads
// to their browser sessions, a session lists its actions in source order, and no
// action body — a sign-in address or password — reaches the screen or frames.
// Both actors sign in through the real surface on the main page.

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
  const user = await seedTestUser({ email: uniqueEmail() })
  await gotoPage(expect, page, '/')
  await openSignIn(expect, page)
  await login(expect, page, user.email, PASSWORD)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const bodies = [user.email, PASSWORD]

  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  try {
    const admin = await adminContext.newPage()
    const frames = watchAnalyticsFrames(admin)
    const { userId: adminId, email: adminEmail } = await seedTestUser({
      email: uniqueEmail(),
    })
    await gotoPage(expect, admin, '/')
    await openSignIn(expect, admin)
    await login(expect, admin, adminEmail, PASSWORD)
    await setAdmin(adminId, true)
    await expect(admin.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'full',
    )
    bodies.push(adminEmail)

    const userSession = await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      user.userId,
      AUTH_ACTION_LOGIN,
    )
    const adminSession = await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      adminId,
      AUTH_ACTION_LOGIN,
    )
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
    // Both real logins ran on the main page: every action stands on it.
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

    // The observer is on the admin's analytics page only: the sign-in
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
