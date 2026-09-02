import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoAdmitted, gotoMaintenance, gotoPage } from '../helpers/page'
import {
  closeProtectedMode,
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

// The framework backup page, the one admin surface this leaf's block lives on.
const BACKUP_URL = '/hilos/backup'

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
  browser,
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

  // A browser of its own, and it has to be one: since HIL-666 the admission is held
  // against the session, so a second tab of the browser that typed the code is inside
  // with it. "Everybody else" begins at the next cookie.
  const bystanderContext = await browser.newContext()
  const bystander = await bystanderContext.newPage()
  await gotoMaintenance(bystander, ADMIN_URL)
  await expect(bystander.getByTestId('maintenance-pass-form')).toBeVisible()
  await bystanderContext.close()
})

test('the code lets the whole browser in, and the tab that was waiting comes out on its own', async ({
  page,
  browser,
}) => {
  // HIL-666 acceptance. The verifier is a person: they read the code out once and
  // then reload, open a second tab, follow a link. Admitting the socket that spelled
  // the code admitted exactly one of those and left the rest of the browser standing
  // on the stub holding a key the node had already accepted.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  const pass = await mintProtectedModePass()

  // The tab that will do the typing, and one that is already standing on the stub
  // when it happens — the ordinary case, since the operator reads the code out to
  // somebody who is already looking at the maintenance screen.
  await gotoMaintenance(page, ADMIN_URL)
  const waiting = await page.context().newPage()
  await gotoMaintenance(waiting, ADMIN_URL)

  await presentCode(page, pass)
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // Pushed, and that is the whole point of asserting it on a live tab: nothing here
  // navigates or reloads, and no connection was torn down when the mode turned on,
  // so a tab left out of this frame would stand on the stub for the rest of the
  // window.
  await expect(waiting.getByTestId('maintenance')).toBeHidden()

  // And a tab opened afterwards is inside without ever being shown the field: it
  // arrives with the same cookie and an accept key the row has never seen.
  const later = await page.context().newPage()
  await gotoAdmitted(later, ADMIN_URL)
  await expect(later.getByTestId('maintenance')).toBeHidden()

  // The admission belongs to one browser and not to everybody: another one keeps
  // the stub, and the field, for the whole window.
  const strangerContext = await browser.newContext()
  const stranger = await strangerContext.newPage()
  await gotoMaintenance(stranger, ADMIN_URL)
  await expect(stranger.getByTestId('maintenance-pass-form')).toBeVisible()
  await strangerContext.close()

  await later.close()
  await waiting.close()
})

test('the tabs the operator already had open are never raised to the stub', async ({
  page,
  context,
  browser,
}) => {
  // The HIL-748 criterion, closed by this leaf: the frame announcing the freeze used
  // to spare one socket, so the operator's other tabs went to a maintenance screen
  // describing the operation their owner was running, and only an F5 took them back.
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const sessionToken = await sessionTokenOf(context)
  expect(sessionToken).not.toBe('')

  const otherTab = await context.newPage()
  await gotoPage(otherTab, '/')
  await expect(otherTab.getByTestId('maintenance')).toBeHidden()

  expect(await enterProtectedMode(OPERATION, '', sessionToken)).toBe('active')

  // A stranger's browser is the barrier before every negative assertion below: it
  // proves the frame has actually gone out on this node, so "still no stub here"
  // means the frame passed this browser by rather than that it has not arrived yet.
  // It stands on the admin url because the second barrier needs it to: the sentence
  // that stands in for the code field is an administrative surface's, and a public
  // one shows neither of the two (HIL-615).
  const strangerContext = await browser.newContext()
  const stranger = await strangerContext.newPage()
  await gotoMaintenance(stranger, ADMIN_URL)

  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(otherTab.getByTestId('maintenance')).toBeHidden()

  // The window frame is a second broadcast, and it spares the same browser.
  expect(await leaveProtectedMode()).toBe('verifying')
  await expect(stranger.getByTestId('maintenance-pass-pending')).toBeVisible()
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(otherTab.getByTestId('maintenance')).toBeHidden()
  await strangerContext.close()

  expect(await openProtectedMode()).toBe('inactive')
  await otherTab.close()
})

test('leaving the window takes the field and the sentence away together', async ({
  page,
}) => {
  // The open, which is one of the window's two exits; the close back into the full
  // freeze is the other, and it takes the same pair away — asserted on its own
  // below, because the frames it rides are different ones.
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

test('a finished operation lands in the verification window, and either exit ends it', async () => {
  // The ladder as the master reports it, both exits walked in one climb: the window
  // is a phase of the same freeze, and it ends either by closing back into that
  // freeze or by opening out of it.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  const verifying = await inspectProtectedMode()
  expect(verifying.phase).toBe('verifying')
  expect(verifying.operation).toBe(OPERATION)
  expect(verifying.passCount).toBe(0)
  // The half of the freeze where the node runs again — which is what makes
  // verifying anything possible at all: the gate is open and the roster the freeze
  // took down has been replayed.
  expect(verifying.agentStartGateClosed).toBe(false)
  expect(verifying.stoppedAgents).toEqual([])

  // The count is the one place a number about the circle exists: the wire carries
  // a boolean, so a browser learns that a code stands and never how many.
  await mintProtectedModePass()
  expect((await inspectProtectedMode()).passCount).toBe(1)

  // The exit an operator takes when the verifiers found something wrong. Everything
  // the window relaxed goes back at once, which is why all four are read together:
  // a close that voided the codes but left the node running would report exactly
  // the same passCount.
  expect(await closeProtectedMode()).toBe('active')

  const refrozen = await inspectProtectedMode()
  expect(refrozen.phase).toBe('active')
  expect(refrozen.operation).toBe(OPERATION)
  expect(refrozen.passCount).toBe(0)
  expect(refrozen.agentStartGateClosed).toBe(true)
  expect(refrozen.stoppedAgents.length).toBeGreaterThan(0)

  // And the other exit, taken from the full freeze the close just restored — so
  // the close leaves a node another destructive operation could run on, rather
  // than a phase nothing gets out of.
  await openProtectedMode()

  const lifted = await inspectProtectedMode()
  expect(lifted.phase).toBe('inactive')
  expect(lifted.passCount).toBe(0)
})

test('closing the window puts the admitted verifier back behind the stub', async ({
  page,
}) => {
  // The browser half of the close, and the reason it is asserted on a live tab
  // rather than after a reload: what is in question is the delivery of the
  // transition — the frame goes to everyone but the connection that asked — and a
  // reload would only prove the frame a cold load gets.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  const pass = await mintProtectedModePass()

  await gotoMaintenance(page, ADMIN_URL)
  await presentCode(page, pass)
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // The second tab, in on the same admission and never asked for a code, goes back
  // with the first one: the close voids the admission itself, not one socket's copy
  // of it.
  const secondTab = await page.context().newPage()
  await gotoAdmitted(secondTab, ADMIN_URL)
  await expect(secondTab.getByTestId('maintenance')).toBeHidden()

  expect(await closeProtectedMode()).toBe('active')

  // Back behind the stub with everybody else, and without the field or the
  // sentence that stands in for it: both bits of the frame go down together, so
  // the surface never offers a box that admits nothing.
  await expect(page.getByTestId('maintenance')).toBeVisible()
  await expect(page.getByTestId('maintenance-pass-form')).toBeHidden()
  await expect(page.getByTestId('maintenance-pass-pending')).toBeHidden()
  await expect(secondTab.getByTestId('maintenance')).toBeVisible()
  await secondTab.close()
})

test('a code from a closed window opens no later one', async ({
  page,
  context,
}) => {
  // The price of doing nothing, named in P-127. The count going to zero says the
  // row forgot the hash; only this says nobody walks in on the forgotten code once
  // a window is open again — which is the whole of what voiding a pass has to mean.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')
  const closedWindowPass = await mintProtectedModePass()

  await gotoMaintenance(page, ADMIN_URL)
  await presentCode(page, closedWindowPass)
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // Close and leave again: a second window of the same freeze, which is the
  // sequence an operator walks when the first round found something wrong.
  expect(await closeProtectedMode()).toBe('active')
  expect(await leaveProtectedMode()).toBe('verifying')
  const openWindowPass = await mintProtectedModePass()
  expect(openWindowPass).not.toBe(closedWindowPass)

  // The old code, typed by hand: the tab dropped its own copy when the close said
  // the window was over, so this is a verifier reading it off the note they were
  // given a minute ago.
  await expect(page.getByTestId('maintenance-pass-form')).toBeVisible()
  await presentCode(page, closedWindowPass)
  await expect(page.getByTestId('maintenance-pass-error')).toBeVisible()
  await expect(page.getByTestId('maintenance')).toBeVisible()

  // The current code, so the refusal above is about the code and not about a
  // window that had stopped admitting anyone.
  const verifier = await context.newPage()
  await gotoMaintenance(verifier, ADMIN_URL)
  await presentCode(verifier, openWindowPass)
  await expect(verifier.getByTestId('maintenance')).toBeHidden()
  await verifier.close()
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

test('a load into a frozen node never flashes the ordinary layout', async ({
  page,
}) => {
  // The defect HIL-613 closes: the shell paints before the welcome frame lands,
  // so a browser reloading into maintenance sees the whole application for a
  // frame — navbar, brand, footer — and only then the stub. The watcher goes in
  // before the app does, because that frame is what has to be proved absent.
  await watchFirstFrame(page)
  expect(await enterProtectedMode(OPERATION)).toBe('active')

  await gotoMaintenance(page, '/')

  const watch = await readFirstFrameWatch(page)
  expect(watch).not.toBeNull()
  // Before anything it saw: a watcher that failed to install reports an absence
  // it never looked for, and every assertion below would pass on it.
  expect(watch?.error).toBe('')
  // The hold engaged and then let go on the welcome — without both halves the
  // case below would pass on a page that simply never booted.
  expect(watch?.states).toEqual(['held', 'ready'])
  // And in between the shell drew nothing: the navbar was never in the document.
  expect(watch?.brand).toBe(false)
})

test('the verification window offers the reopen block to the operator, and to nobody else', async ({
  page,
  browser,
}) => {
  // HIL-676 acceptance. The block is the one thing on the backup page that is answered
  // PERSONALLY: two admins subscribe to the same page in the same window and only one of
  // them is offered the lever. Nothing short of two real browsers can show that, because
  // the whole decision lives on the session behind the connection.
  //
  // Both accounts are made BEFORE the freeze: under it there is no signing up.
  await signUpAdmin(page)
  await gotoPage(page, BACKUP_URL)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const operatorSession = await sessionTokenOf(page.context())
  expect(operatorSession).not.toBe('')

  const verifierContext = await browser.newContext()
  const verifier = await verifierContext.newPage()
  await signUpAdmin(verifier)
  await gotoPage(verifier, BACKUP_URL)
  await expect(verifier.getByTestId('hilos-viewport-table')).toBeVisible()

  // Entered for the operator's BROWSER, exactly as a restore started from this page
  // enters it, and then ended - which is what leaves the node in the window.
  expect(await enterProtectedMode(OPERATION, '', operatorSession)).toBe('active')
  expect(await leaveProtectedMode()).toBe('verifying')

  // The operator, back on the page. The block is there and it carries its button.
  await gotoPage(page, BACKUP_URL)
  await expect(page.getByTestId('hilos-backup-reopen-panel')).toBeVisible()
  await expect(page.getByTestId('hilos-backup-reopen')).toBeVisible()

  // The verifier, admitted by the code the operator read out. They get the product -
  // that is what the window is for - and they do not get the lever: the pass names a
  // session the node let in, not the session that asked for the operation.
  const pass = await mintProtectedModePass()
  await gotoMaintenance(verifier, BACKUP_URL)
  await presentCode(verifier, pass)
  await expect(verifier.getByTestId('maintenance')).toBeHidden()
  await expect(verifier.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(verifier.getByTestId('hilos-backup-reopen-panel')).toHaveCount(0)

  // The click is not driven here and cannot be: the row of a test freeze names the test
  // driver's carrier as its initiator, so BackupAgent would rightly refuse a reopen it
  // did not start. What the button does is held by the framework tests instead.
  await verifierContext.close()
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

/**
 * The maintenance hint the core keeps in localStorage
 * (`PROTECTED_MODE_HINT_STORAGE_KEY`, framework/frontend/core/src/connection/
 * maintenanceHint.ts). Restated as a literal because the e2e package builds
 * against no framework source at all; the core exports the same key from its
 * barrel so the two can be held side by side.
 */
const MAINTENANCE_HINT_KEY = 'hilos.protectedMode.hint'

/** Where the watcher below parks its record on the page. */
const BOOT_WATCH_HOOK = 'hilosBootWatch'

/** What the watcher saw the shell do with its first frames. */
interface BootWatch {
  /** Whether the ordinary navbar was ever in the document at all. */
  brand: boolean
  /** Every boot state the marker went through, in order. */
  states: string[]
  /** Why the watcher never got to look, or empty when it did. */
  error: string
}

/**
 * Plant the maintenance hint and start recording what the shell paints.
 *
 * Installed before the application's own scripts, which is the only vantage
 * point a frame that must never exist can be observed from: an assertion after
 * the fact cannot tell "never drawn" from "drawn and gone", and a screenshot
 * proves only that the layout is not there now. A mutation observer sees both.
 *
 * The hint is what a browser would already be carrying, having met this node's
 * maintenance once before; planting it is how a cold load reaches the state the
 * fix is about, without a first visit spent warming it up.
 *
 * @param page The Playwright page, before anything is opened on it.
 */
async function watchFirstFrame(page: Page): Promise<void> {
  await page.addInitScript(
    ([hintKey, hook]: string[]) => {
      window.localStorage.setItem(hintKey, '1')
      const watch = { brand: false, states: [] as string[], error: '' }
      Object.defineProperty(window, hook, { value: watch })
      const look = (): void => {
        if (document.querySelector('[data-id="nav-brand"]') !== null) {
          watch.brand = true
        }
        const state = document
          .querySelector('[data-id="hilos-boot-state"]')
          ?.getAttribute('data-state')
        if (state != null && watch.states.at(-1) !== state) {
          watch.states.push(state)
        }
      }
      try {
        // The document, not `document.documentElement`: an init script runs
        // before the parser has built the root element, so that property is
        // still null here and observing it throws. The document is a node from
        // the start, and `subtree` reaches everything the parser adds under it.
        new MutationObserver(look).observe(document, {
          attributes: true,
          childList: true,
          subtree: true,
        })
        look()
      } catch (cause) {
        // A watcher that dies quietly reports a layout it never looked for as
        // absent, which is this case passing without having tested anything.
        watch.error = String(cause)
      }
    },
    [MAINTENANCE_HINT_KEY, BOOT_WATCH_HOOK],
  )
}

/**
 * Read back what the watcher saw, or null when it never ran.
 *
 * Null is worth telling apart from an empty record: a watcher that failed to
 * install would report a layout it never looked for as absent, and the case
 * would pass without having tested anything.
 *
 * @param page The Playwright page the watcher was installed on.
 */
function readFirstFrameWatch(page: Page): Promise<BootWatch | null> {
  return page.evaluate(
    (hook) =>
      (window as unknown as Record<string, BootWatch | undefined>)[hook] ?? null,
    BOOT_WATCH_HOOK,
  )
}
