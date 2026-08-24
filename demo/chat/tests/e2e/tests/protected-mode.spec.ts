import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

import { gotoAdmitted, gotoMaintenance, gotoPage } from '../helpers/page'
import {
  enterProtectedMode,
  inspectProtectedMode,
  leaveProtectedMode,
  mintProtectedModePass,
  openProtectedMode,
  openProtectedModeIfAny,
  sessionTokenOf,
} from '../helpers/protectedMode'

// Protected-mode e2e (HIL-344): the debt HIL-268 and HIL-522 were closed with —
// the freeze and its browser stub had never been driven from a browser at all,
// because there was no way to enter the mode without a real restore. There is one
// now, and it is the production entry: the CLI asks an initiator agent, the agent
// asks its daemon. Nothing here forces a runtime row.
//
// The whole node freezes for the duration, so this spec must never leave one
// behind: the runner is serialized (CI=1 → workers: 1), and the teardown lifts
// unconditionally.

// The framework default from Hilos::PROTECTED_MODE_STUB, which this demo does not
// override.
const STUB_TITLE = 'Maintenance in progress'
const STUB_MESSAGE =
  'The application is briefly unavailable while a maintenance operation finishes.' +
  ' It will come back on its own.'

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

test('a live window shows the maintenance stub while the mode is on', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // Entering with no accept key is the main scenario: a freeze asked for from a
  // terminal has no browser connection to keep alive, so this window is locked
  // out like every other.
  expect(await enterProtectedMode(OPERATION)).toBe('active')

  const maintenance = page.getByTestId('maintenance')
  await expect(maintenance).toBeVisible()
  await expect(page.getByTestId('maintenance-title')).toHaveText(STUB_TITLE)
  await expect(page.getByTestId('maintenance-message')).toHaveText(STUB_MESSAGE)

  // This attribute is what proves the words came from the backend rather than
  // from the frontend last resort: the two copies are deliberately kept identical
  // (PROTECTED_MODE_FALLBACK_COPY mirrors the framework default), so the text
  // alone cannot tell them apart — but the operation name has no frontend default
  // at all, and this one was chosen by the caller a moment ago.
  await expect(maintenance).toHaveAttribute('data-operation', OPERATION)

  // The operation ending does NOT let this window back in: it lands in the
  // verification window, where only a presented pass is admitted, and this one
  // has none. That is the whole leaf — nothing reopens by finishing its own work.
  expect(await leaveProtectedMode()).toBe('verifying')
  await expect(page.getByTestId('maintenance')).toBeVisible()

  expect(await openProtectedMode()).toBe('inactive')

  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

test('the browser that asked keeps its way in across a reload and a second tab', async ({
  page,
  context,
  browser,
}) => {
  // HIL-655 acceptance, and the observation it was written from: the operator who
  // started a restore pressed F5 and locked themselves out of watching it, because
  // the way in was the accept key of one socket and a reload mints a new one.
  //
  // A cold connection first — the session cookie is minted on the first 101, and it
  // is the only handle a browser has on its own identity here: the welcome frame
  // carries no accept key, so nothing in the page can name the socket it arrived on.
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const sessionToken = await sessionTokenOf(context)
  expect(sessionToken).not.toBe('')

  // Entered for this BROWSER and for no particular socket: the accept key is left
  // empty on purpose, so whatever gets in below got in as the session.
  expect(await enterProtectedMode(OPERATION, '', sessionToken)).toBe('active')

  // The reload. A brand new accept key, the same cookie, and the same person.
  await gotoAdmitted(page, '/')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // The second tab, which the freeze was never told about at all — it did not exist
  // when the operation started, and under the freeze no agent is left running to
  // register it anywhere. The master recognizes it off its own connection list.
  const secondTab = await context.newPage()
  await gotoAdmitted(secondTab, ADMIN_URL)
  await expect(secondTab.getByTestId('maintenance')).toBeHidden()

  // The right belongs to one session and not to everybody: another browser is held
  // exactly as it was before this leaf.
  const strangerContext = await browser.newContext()
  const stranger = await strangerContext.newPage()
  await gotoMaintenance(stranger, '/')
  await expect(stranger.getByTestId('maintenance-title')).toHaveText(STUB_TITLE)
  await strangerContext.close()

  expect(await openProtectedMode()).toBe('inactive')

  // Both tabs of the session are inside after the lift too — which is what a restore
  // leaves behind, and the half SessionCarrier already answered for (HIL-479).
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(secondTab.getByTestId('maintenance')).toBeHidden()
  await secondTab.close()
})

test('the verification window waits for a code before it offers a field', async ({
  page,
}) => {
  // Both loads are cold, and deliberately so: the stub is painted from the
  // welcome frame, before any subscription, so this is the frame where the
  // verdict has to be right without a round trip.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  // A public url in the window looks exactly like the active phase, in both
  // states of the window. That is HIL-615 untouched: a visitor holds no code, and
  // a field would announce to him that a window is open at all.
  await gotoMaintenance(page, '/')
  await expect(page.getByTestId('maintenance-title')).toHaveText(STUB_TITLE)
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()

  // The same phase, the same freeze, an administrative url — and no code minted
  // yet. The verifier is told to wait rather than handed a box that can take
  // nothing, which is the whole of HIL-616.
  await gotoMaintenance(page, ADMIN_URL)
  await expect(page.getByTestId('maintenance-pass-pending')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()

  // A stamp on this document: the first mint must reach this page as a frame, so
  // whatever is on screen afterwards has to be the same document that was there
  // before it.
  await page.evaluate(() => {
    ;(window as Window & { hil616Stamp?: boolean }).hil616Stamp = true
  })
  const urlBeforeTheMint = page.url()

  await mintProtectedModePass()

  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-submit')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
  expect(page.url()).toBe(urlBeforeTheMint)
  expect(
    await page.evaluate(
      () => (window as Window & { hil616Stamp?: boolean }).hil616Stamp === true,
    ),
  ).toBe(true)

  // A public url still shows neither, now that a code is standing: the surface
  // type decides, not what the window holds.
  const publicTab = await page.context().newPage()
  await gotoMaintenance(publicTab, '/')
  await expect(publicTab.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(publicTab.getByTestId('maintenance-pass-pending')).toBeHidden()
  await publicTab.close()
})

test('a wrong code leaves the connection frozen and says so', async ({
  page,
}) => {
  // Testable for the first time: until a test could mint, there was no right key
  // to contrast a wrong one with. A frozen node has no agent to compose a
  // refusal, so what answers is the welcome that comes back still locking this
  // connection out - and the client turns that silence into the message below.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  await mintProtectedModePass()

  await gotoMaintenance(page, ADMIN_URL)
  await presentCode(page, 'not-the-code-that-was-minted')

  await expect(page.getByTestId('maintenance-pass-error')).toBeVisible()
  await expect(page.getByTestId('maintenance')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()
})

test('the minted code admits the verifier while everyone else keeps the stub', async ({
  page,
}) => {
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  const pass = await mintProtectedModePass()
  expect(pass).not.toBe('')

  await gotoMaintenance(page, ADMIN_URL)
  await presentCode(page, pass)

  // The verifier sees the product; the freeze is still on for everybody else,
  // which is what the window is for.
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const bystander = await page.context().newPage()
  await gotoMaintenance(bystander, ADMIN_URL)
  await expect(bystander.getByTestId('maintenance-pass-form')).toBeVisible()
  await bystander.close()
})

test('leaving the window takes the field and the sentence away together', async ({
  page,
}) => {
  // The open is the only exit the driven path has: closing back to a full freeze
  // is an operator command, owned by the agent that runs real operations, and no
  // test-only name for it exists yet.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  await mintProtectedModePass()

  await gotoMaintenance(page, ADMIN_URL)
  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()

  expect(await openProtectedMode()).toBe('inactive')

  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
})

test('a public page live through the switch gains no code field', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  expect(await enterProtectedMode(OPERATION)).toBe('active')
  await expect(page.getByTestId('maintenance')).toBeVisible()

  expect(await leaveProtectedMode()).toBe('verifying')
  await mintProtectedModePass()

  // A second tab proves the window really is open to browsers right now and has a
  // code standing, so the assertion below is about the surface type and not about
  // a frame still in flight.
  const verifierTab = await page.context().newPage()
  await gotoMaintenance(verifierTab, ADMIN_URL)
  await expect(verifierTab.getByTestId('maintenance-pass-form')).toBeVisible()
  await verifierTab.close()

  // The public page never navigated, so its route — and its verdict — never
  // changed: neither the switch into the window nor the mint inside it adds
  // anything to it.
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
})

test('a finished operation lands in the verification window, and only the open ends it', async () => {
  // The ladder as the master reports it: the window is a phase of the same freeze,
  // so the start gate stays closed and no pass is outstanding until one is minted.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  const verifying = await inspectProtectedMode()
  expect(verifying.phase).toBe('verifying')
  expect(verifying.operation).toBe(OPERATION)
  expect(verifying.passCount).toBe(0)

  // The count is the one place a number about the circle exists: the wire carries
  // a boolean, so a browser learns that a code stands and never how many.
  await mintProtectedModePass()
  expect((await inspectProtectedMode()).passCount).toBe(1)

  await openProtectedMode()

  const lifted = await inspectProtectedMode()
  expect(lifted.phase).toBe('inactive')
  expect(lifted.passCount).toBe(0)
})

test('the inspector answers mid-freeze, when every other agent is stopped', async () => {
  // The reason the inspector is answered by the master and not by an agent: at
  // this moment there is no agent left to ask but the initiator.
  await enterProtectedMode(OPERATION)

  const frozen = await inspectProtectedMode()
  expect(frozen.rtMounted).toBe(true)
  expect(frozen.phase).toBe('active')
  expect(frozen.operation).toBe(OPERATION)
  expect(frozen.agentStartGateClosed).toBe(true)
  expect(frozen.initiatorAgentType).not.toBeNull()

  await openProtectedMode()

  const lifted = await inspectProtectedMode()
  expect(lifted.phase).toBe('inactive')
  expect(lifted.agentStartGateClosed).toBe(false)
  expect(lifted.operation).toBeNull()
})

test('entering twice is refused with a reason instead of timing out', async () => {
  await enterProtectedMode(OPERATION)

  // The core drops a repeat enable with a warning and answers nobody, so without
  // the agent's pre-check this would be a mute timeout.
  await expect(enterProtectedMode(OPERATION)).rejects.toThrow(/already active/)
})

/**
 * Types a code into the verifier's field and drives the submit button.
 *
 * @param page The Playwright page showing the maintenance surface.
 * @param code The code to present.
 */
async function presentCode(page: Page, code: string): Promise<void> {
  const field = page.getByTestId('maintenance-pass')
  await field.fill('')
  await field.pressSequentially(code, { delay: 10 })

  const submit = page.getByTestId('maintenance-pass-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
}
