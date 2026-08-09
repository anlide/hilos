import { test, expect } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { expectPageReady } from '../helpers/page'

// No-refresh navigation e2e: the shell's gear moves to the framework dashboard
// and the brand moves back home through the core navigator (HilosRouter),
// without reloading the document or dropping the WebSocket. A hard navigation
// would fire a fresh `load`; the count staying put proves every transition
// stayed in the same live document, and `conn-state` staying `connected` proves
// the socket was never torn down.
test('navigates main <-> dashboard with no reload or reconnect', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  // Sign in and take the admin grant: the gear's target is the framework
  // dashboard, and /hilos is closed by default (HIL-441), so without the grant
  // this spec would be asserting that a non-admin is shown the admin section.
  // signUp asserts the self user itself, and both it and the grant run in
  // place, so the load count settles here.
  await signUpAdmin(page)
  const loadsAfterColdLoad = fullLoads

  // Gear -> dashboard. Wait for the outlet to settle first: the page is held
  // back until its subscription answers, so `ready` is the moment the dashboard
  // on screen is the one that stays.
  await page.getByTestId('nav-admin').click()
  await expectPageReady(page)
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)

  // Brand -> home.
  await page.getByTestId('nav-brand').click()
  await expectPageReady(page)
  await expect(page.getByTestId('self-user')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(fullLoads).toBe(loadsAfterColdLoad)
})
