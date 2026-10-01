// The administrator's impersonation settings (HIL-1170), end to end on the chat:
// switching impersonation off takes the takeover off the person's card and
// switching it back on returns it; and a takeover where the administrator may
// only look says so in the strip, has the server refuse what it would write —
// a chat message here — and still lets the administrator out with Stop.
//
// The settings are the installation's own, so each test puts back what it moved
// whatever happens; the chat suite runs in a single worker, so no other test
// meets the moved value meanwhile.
import { expect, test, type Page } from '@playwright/test'

import {
  dismissToasts,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, signUp, typeInto } from '../helpers/session'

/** The settings page this spec drives. */
const SETTINGS_PATH = '/hilos/security/impersonation'

/** The switch that decides whether impersonation exists in the product. */
const ALLOWED_SWITCH = 'hilos-impersonation-switch-auth.impersonation.allowed'

/** The refusal the screen shows for a write inside a takeover that only looks. */
const VIEW_ONLY_REFUSAL =
  "View only: nothing can be changed in someone else's account."

/**
 * Set what may be done in someone else's account through the scope's modal, and
 * wait for the row to say the new value.
 *
 * @param page The administrator's page.
 * @param scope The value to choose.
 */
async function setScope(page: Page, scope: 'view' | 'act'): Promise<void> {
  await gotoPage(page, SETTINGS_PATH)
  await clickSubmit(shownByTestId(page, 'hilos-impersonation-scope-edit'))
  const choice = page.getByTestId(`hilos-impersonation-scope-${scope}`)
  await expect(choice).toBeVisible()
  await choice.check()
  await clickSubmit(page.getByTestId('hilos-impersonation-scope-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(
    shownByTestId(page, 'hilos-impersonation-scope-value'),
  ).toHaveText(scope === 'view' ? 'View only' : 'View and act')
}

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
  let switchedOff = false
  try {
    await signUpAdmin(page)
    const person = await signUp(personPage)
    const card = `/hilos/user/${person.userId}`
    const open = page.getByTestId('hilos-user-impersonate-open')

    await gotoPage(page, card)
    await expect(open).toBeVisible()

    await setAllowed(page, false)
    switchedOff = true
    await gotoPage(page, card)
    await expect(page.getByTestId('hilos-user-id')).toHaveText(
      String(person.userId),
    )
    await expect(open).toHaveCount(0)

    await setAllowed(page, true)
    switchedOff = false
    await gotoPage(page, card)
    await expect(open).toBeVisible()
    await expect(open).toBeEnabled()
  } finally {
    if (switchedOff) {
      await setAllowed(page, true)
    }
    await personContext.close()
  }
})

test('a takeover that only looks says so, is refused a message, and Stop still returns', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const personPage = await personContext.newPage()
  const strip = page.getByTestId('impersonation-banner')
  let viewOnly = false
  try {
    await signUpAdmin(page)
    const person = await signUp(personPage)

    await setScope(page, 'view')
    viewOnly = true
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Impersonation setting saved.',
    )
    await dismissToasts(page)

    // The takeover rotates the session token, and the client reopens its socket
    // for it; the proof the reopen happened is the next socket.
    await gotoPage(page, `/hilos/user/${person.userId}`)
    let sockets = 0
    page.on('websocket', () => {
      sockets += 1
    })
    await clickSubmit(page.getByTestId('hilos-user-impersonate-open'))
    await clickSubmit(page.getByTestId('hilos-user-impersonate-confirm'))
    await expect(strip).toBeVisible()
    await expect(strip).toContainText(person.name)
    await expect(strip).toContainText('view only')
    await expect.poll(() => sockets).toBeGreaterThan(0)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')

    // Reading is open; writing is refused before it reaches the chat.
    await gotoPage(page, '/')
    await expect(strip).toContainText('view only')
    await typeInto(
      page.getByTestId('message-input'),
      `a message that must not be published ${Date.now()}`,
    )
    await clickSubmit(page.getByTestId('message-send'))
    await expect(page.getByTestId('message-error')).toHaveText(
      VIEW_ONLY_REFUSAL,
    )

    // Stop is the shell's and stays live inside a takeover that only looks.
    await clickSubmit(page.getByTestId('impersonation-stop'))
    await expect(strip).toBeHidden()
    await expect(page.getByTestId('nav-admin')).toBeVisible()

    await setScope(page, 'act')
    viewOnly = false
  } finally {
    if (viewOnly) {
      if (await strip.isVisible()) {
        await clickSubmit(page.getByTestId('impersonation-stop'))
        await expect(strip).toBeHidden()
      }
      await setScope(page, 'act')
    }
    await personContext.close()
  }
})
