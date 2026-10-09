// The administrator's impersonation settings (HIL-1170): the first case moved to
// polls; this view-only takeover test waits for HIL-1290 and writes a chat message.
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

// Red in runs 0749 (HIL-1237) and 0770 (HIL-1179) of the 32 full runs since it
// came, green alone in one lane on the same HEAD. The first attempt passes every
// check and times out in its own cleanup: after Stop, setScope(page, 'act')
// opens the impersonation settings and the edit button never shows. The scope
// is the installation's and stays on view, so both retries fail at their first
// setScope(page, 'view') with Save disabled. Parked by the owner on 03.10.2026
// (HOTFIX) without a diagnosis of why the edit button is missing after Stop.
test.fixme('a takeover that only looks says so, is refused a message, and Stop still returns', async ({
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
