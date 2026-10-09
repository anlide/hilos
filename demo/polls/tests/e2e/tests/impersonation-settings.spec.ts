// The administrator's impersonation settings (HIL-1170), end to end on polls:
// switching impersonation off takes the takeover off the person's card and
// switching it back on returns it.
//
// The settings are the installation's own, so each test puts back what it moved
// whatever happens.
import { expect, test, type Page } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

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
