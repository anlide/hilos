import { expect, test } from '../test.js'

import { AUTH_ACTION_COMPLETE_REGISTRATION } from '../../../../../framework/frontend/core/src/auth/authProtocol.js'
import { HilosPages } from '../../../../../framework/frontend/core/src/routing/hilosPages.js'
import { resolveHilosPath } from '../../../../../framework/frontend/core/src/routing/hilosAdmin.js'
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

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

test('a guest opens the empty home', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Roblox Game Server')
  await expect(page.getByTestId('self-user')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-analytics-/)).toHaveCount(0)
})

test('signing in and opening Analytics shows real data', async ({ page }) => {
  const user = await signUp(expect, page)
  expect(await grantAdminToSelf(expect, page)).toBe(user.userId)

  const session = await waitAnalyticsBrowserSession(
    expect.configure({ timeout: ANALYTICS_WRITER_TIMEOUT_MS }),
    sendCommand,
    user.userId,
    AUTH_ACTION_COMPLETE_REGISTRATION,
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
