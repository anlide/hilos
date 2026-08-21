import { test, expect } from '@playwright/test'

import { gotoMaintenance, gotoPage } from '../helpers/page'
import {
  enterProtectedMode,
  leaveProtectedMode,
  openProtectedModeIfAny,
} from '../helpers/protectedMode'

// The Angular half of HIL-615: the verifier's code field belongs to
// administrative surfaces and to nowhere else. Angular has no component test of
// its own (`ng test` is blocked upstream), so this live run is where the view
// package's binding of the route's surface type is actually proven.
//
// The whole node freezes for the duration, so this spec must never leave one
// behind: the runner is serialized (CI=1 → workers: 1), and the teardown lifts
// unconditionally.

const OPERATION = 'e2e-freeze'

// The framework dashboard, an administrative surface by its own route
// declaration (HILOS_ROUTE_DECLARATIONS). Any admin url would do; this one needs
// no route params.
const ADMIN_URL = '/hilos'

test.afterEach(async () => {
  // Unconditional, and an open rather than a leave: an enter can be refused and
  // still land afterwards, a failed assertion can strand the node in any phase,
  // and only the open lifts from all of them.
  await openProtectedModeIfAny()
})

test('the verification window offers its code field on an administrative url only', async ({
  page,
}) => {
  // Both loads are cold, and deliberately so: the stub is painted from the
  // welcome frame, before any subscription, so this is the frame where the
  // verdict has to be right without a round trip.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  // A public url in the window looks exactly like the active phase. That is the
  // point of the leaf: a visitor holds no code, and a field would announce to
  // him that a window is open at all.
  await gotoMaintenance(page, '/')
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()

  // The same phase, the same freeze, an administrative url. The verifier types
  // it: the shell drops brand, nav, gear and footer while the stub is up, so
  // there is nothing on screen to click his way in with.
  await gotoMaintenance(page, ADMIN_URL)
  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-submit')).toBeVisible()
})

test('a public page live through the switch gains no code field', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  expect(await enterProtectedMode(OPERATION)).toBe('active')
  await expect(page.getByTestId('maintenance')).toBeVisible()

  expect(await leaveProtectedMode()).toBe('verifying')

  // A second tab proves the window really is open to browsers right now, so the
  // assertion below is about the surface type and not about a frame still in
  // flight.
  const verifierTab = await page.context().newPage()
  await gotoMaintenance(verifierTab, ADMIN_URL)
  await expect(verifierTab.getByTestId('maintenance-pass-form')).toBeVisible()
  await verifierTab.close()

  // The public page never navigated, so its route — and its verdict — never
  // changed: the switch into the window adds nothing to it.
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
})
