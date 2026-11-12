import { expect, test } from '../test.js'

import {
  AUTH_ACTION_COMPLETE_REGISTRATION,
  AUTH_ACTION_DISMISS_SESSION_ACK,
  HilosPages,
  resolveHilosPath,
} from '../../../../../framework/frontend/core/src/index.js'
import {
  ANALYTICS_WRITER_TIMEOUT_MS,
  analyticsSessionLink,
  expectAnalyticsActionsInOrder,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  signUp,
  waitAnalyticsBrowserSession,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — this demo's activation proof

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

test('a guest opens the empty public home', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('File Gallery Manager')
  await expect(page.getByTestId('self-user')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-analytics-/)).toHaveCount(0)
})

test('signing in from the shell and opening Analytics shows real data', async ({
  page,
}) => {
  // The shell's own sign-in button opens the surface on the empty home, so the
  // account is registered where a visitor of this carrier registers it.
  const user = await signUp(expect, page)
  expect(await grantAdminToSelf(expect, page)).toBe(user.userId)

  const session = await waitAnalyticsBrowserSession(
    expect.configure({ timeout: ANALYTICS_WRITER_TIMEOUT_MS }),
    sendCommand,
    user.userId,
    AUTH_ACTION_DISMISS_SESSION_ACK,
  )

  await gotoPage(expect, page, resolveHilosPath(HilosPages.ANALYTICS))
  await expect(
    page.getByTestId('hilos-analytics-visitors-day-count'),
  ).toHaveText(/^[1-9]\d*$/)

  await gotoPage(
    expect,
    page,
    resolveHilosPath(HilosPages.ANALYTICS_PERSON, {
      userId: String(user.userId),
    }),
  )
  await analyticsSessionLink(page, session.browserSessionId!).click()
  await expectPageReady(expect, page)
  await expect(
    page.getByTestId('hilos-analytics-browser-session-person'),
  ).toHaveText(`Last signed in: ${user.name} (#${user.userId})`)
  const actions = await expectAnalyticsActionsInOrder(
    expect,
    page,
    session.actionNames,
  )
  expect(actions).toEqual(
    expect.arrayContaining([
      expect.objectContaining({
        actionName: AUTH_ACTION_COMPLETE_REGISTRATION,
        pageName: 'main',
      }),
    ]),
  )
})
