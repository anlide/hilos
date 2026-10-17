// The administrator's impersonation settings (HIL-1170), end to end on polls:
// switching impersonation off takes the takeover off the person's card and
// switching it back on returns it; switching it off also ends a takeover already
// under way (HIL-1308).
//
// The settings are the installation's own, so each test puts back what it moved
// whatever happens.
import { expect, test, type Page } from '@playwright/test'

import {
  dismissToasts,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, signUp } from '../helpers/session.js'

/** The settings page this spec drives. */
const SETTINGS_PATH = '/hilos/security/impersonation'

/** The switch that decides whether impersonation exists in the product. */
const ALLOWED_SWITCH = 'hilos-impersonation-switch-auth.impersonation.allowed'

/**
 * Flip the "Impersonation is allowed" switch and wait for the row to move.
 *
 * @param page The administrator's page.
 * @param on The position the switch is to reach.
 */
async function setAllowed(page: Page, on: boolean): Promise<void> {
  await gotoPage(page, SETTINGS_PATH)
  const allowed = shownByTestId(page, ALLOWED_SWITCH)
  if (on) {
    await expect(allowed).not.toBeChecked()
  } else {
    await expect(allowed).toBeChecked()
  }
  await allowed.click()
  if (on) {
    await expect(allowed).toBeChecked()
  } else {
    await expect(allowed).not.toBeChecked()
  }
}

test('switching impersonation off takes the takeover off the card, and on brings it back', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const personPage = await personContext.newPage()
  let settingsPage: Page | null = null
  let switchedOff = false
  let cardLoads = 0
  page.on('load', () => {
    cardLoads += 1
  })
  try {
    await signUpAdmin(page)
    const person = await signUp(personPage)
    const card = `/hilos/user/${person.userId}`
    const open = page.getByTestId('hilos-user-impersonate-open')

    await gotoPage(page, card)
    await expect(open).toBeVisible()
    const loadsBeforeSettings = cardLoads

    settingsPage = await page.context().newPage()
    await setAllowed(settingsPage, false)
    switchedOff = true
    await expect(open).toHaveCount(0)
    expect(new URL(page.url()).pathname).toBe(card)
    expect(cardLoads).toBe(loadsBeforeSettings)

    await setAllowed(settingsPage, true)
    switchedOff = false
    await expect(open).toBeVisible()
    await expect(open).toBeEnabled()
    expect(new URL(page.url()).pathname).toBe(card)
    expect(cardLoads).toBe(loadsBeforeSettings)
  } finally {
    if (switchedOff && settingsPage) {
      await setAllowed(settingsPage, true)
    }
    if (settingsPage) {
      await settingsPage.close()
    }
    await personContext.close()
  }
})

test('switching impersonation off returns an administrator from a takeover without a reload', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  // A second tab of the same browser is the same session, so the takeover would
  // cover it too: the administrator who switches it off has a browser of their own.
  const switcherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const switcherPage = await switcherContext.newPage()
  const strip = page.getByTestId('impersonation-banner')
  const gear = page.getByTestId('nav-admin')
  let switchedOff = false
  let loads = 0
  page.on('load', () => {
    loads += 1
  })
  try {
    await signUpAdmin(page)
    const person = await signUp(await personContext.newPage())

    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-impersonate-open'))
    await clickSubmit(page.getByTestId('hilos-user-impersonate-confirm'))
    await expect(strip).toBeVisible()
    await expect(strip).toContainText(person.name)
    await expect(gear).toHaveCount(0)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await dismissToasts(page)
    const loadsBeforeSwitch = loads

    await signUpAdmin(switcherPage)
    await setAllowed(switcherPage, false)
    switchedOff = true
    await expect(strip).toBeHidden()
    await expect(page.getByTestId('hilos-toast-info')).toContainText(
      'Impersonation was switched off',
    )
    await expect(gear).toBeVisible()
    expect(loads).toBe(loadsBeforeSwitch)
  } finally {
    if (switchedOff) {
      await setAllowed(switcherPage, true)
    }
    await switcherContext.close()
    await personContext.close()
  }
})
