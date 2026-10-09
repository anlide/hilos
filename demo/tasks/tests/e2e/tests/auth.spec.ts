import { test, expect } from '@playwright/test'

import { watchTop } from '../../../../../framework/frontend/e2e/index.js'
import {
  mailsTo,
  readMagicLinkCode,
  readMagicLinkUrl,
  readPasswordResetCode,
  readRegisterCode,
} from '../helpers/mail'
import { gotoAuthReturn, gotoPage, PAGE_REFUSED } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  continueFromDone,
  enterIdentifierAndPassword,
  finishWithoutPassword,
  login,
  logout,
  nameFromEmail,
  openSignIn,
  register,
  submitFirstPassword,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
  waitAuthSettled,
} from '../helpers/session'

// Sign-in e2e for the tasks demo (HIL-623). This demo had no auth handler at all
// until the AUTH feature was declared; nothing about the machine is this leaf's,
// so what is proved here is the ACTIVATION — that the framework surface reaches
// this project's seams and comes back with an account in this project's own
// users table.
//
// The core cases carry that: registering by the mailed code — which also pins the
// frame the surface arrives in over the page, as a modal, because that frame is
// this demo's App and not the framework's — signing back in with the password
// after a wrong one, recovering a forgotten one, and the surface standing in
// place of a page a guest is refused.
//
// Following the test consolidation (HIL-1324), signing in by code and magic link,
// address holding across reloads and cancellations, multi-tab registration
// convergence, and two-tab recovery are now carried here. Separate specs cover
// OAuth (oauth.spec.ts), phone code channels (phone-codes.spec.ts), and passkeys
// (passkey.spec.ts).

/** The passphrase a recovery saves, told apart from the one it replaces. */
const RECOVERED_PASSWORD = 'a whole new passphrase'

/**
 * The path and query of a sign-in link, as the address bar should hold it.
 *
 * The letter carries an absolute URL built from `HILOS_MAGIC_LINK_URL`, which is
 * the address a real deployment publishes and NOT the stand's own base — opening
 * it verbatim would leave the stand entirely. Only the path and query belong to
 * the click; the host is the deployment's business.
 *
 * @param url The sign-in URL exactly as the letter spells it.
 * @returns Its path and query, for `gotoAuthReturn`.
 */
function returnPath(url: string): string {
  const parsed = new URL(url)

  return `${parsed.pathname}${parsed.search}`
}

test('holds the address in a modal over the page, and signs the session in on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-user')).not.toBeEmpty()

  await openSignIn(page)

  // A modal, not a replacement: the page underneath is still mounted and still
  // showing the guest's own identity line. The dialog's name is asserted on the
  // first screen only — it follows the surface heading, which moves with the
  // step (HIL-832).
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByTestId('self-user')).toBeVisible()

  // Dismissing it leaves the guest exactly where they were.
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('self-user')).toBeVisible()

  await openSignIn(page)
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('legal-consent-deviation')).toHaveCount(1)
  await expect(page.getByTestId('legal-consent-direction')).toHaveText(
    'stricter',
  )
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  // The surface is on the code step and the session is still a guest: the
  // submit reserved the address, it did not create an account (HIL-415).
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page)
  await continueFromDone(page)

  // The account exists in this demo's own users table and the session carries
  // it: the identity line switches from the guest branch to the account one.
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(page.getByTestId('self-user-id')).not.toBeEmpty()
  // And the shell grows the signed-in region the mockup describes.
  await expect(page.getByTestId('nav-profile-name')).toContainText(
    nameFromEmail(email),
  )
  await expect(
    page.getByTestId('nav-profile-name').getByTestId('hilos-avatar'),
  ).toHaveText(nameFromEmail(email).charAt(0).toUpperCase())
  await expect(page.getByTestId('nav-signin')).toHaveCount(0)
})

test('answers a wrong password inline, then signs the account back in', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(page)
  // Signing out puts the visitor back on the guest branch, which is the state
  // the header's Sign in button belongs to.
  await expect(page.getByTestId('nav-signin')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  await openSignIn(page)
  await enterIdentifierAndPassword(page, email, 'not the password')
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitAuthSettled(page)

  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  // The surface is still standing, so the right password goes into the same
  // form the wrong one was refused on.
  await login(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('recovers a forgotten password and signs in with the new one', async ({
  page,
}) => {
  const email = uniqueEmail()
  const newPassword = 'a whole other passphrase'

  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await logout(page)

  // Recovery starts from the same one field: the lookup finds the account,
  // reveals the password, and the key beside it asks for a code instead.
  await openSignIn(page)
  await enterIdentifierAndPassword(page, email, PASSWORD)
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(
    page.getByTestId('auth-code'),
    await readPasswordResetCode(email),
  )
  await clickSubmit(page.getByTestId('auth-submit'))

  // An accepted recovery code does not sign anybody in: it opens the step that
  // chooses the new password, and only that ends the ceremony.
  const newPasswordField = page.getByTestId('auth-new-password')
  await expect(newPasswordField).toBeVisible()
  await typeInto(newPasswordField, newPassword)
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(page)
  await openSignIn(page)
  await login(page, email, newPassword)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('shows the surface in place of an admin page a guest is refused', async ({
  page,
}) => {
  await gotoPage(page, '/hilos/settings', PAGE_REFUSED)

  // In place of the page, not over it: the refusal IS the reason the surface is
  // on screen, so there is no dialog and no second copy of the machine.
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)

  // Signing in re-decides the page, which is what the surface standing in place
  // of it is for. This account is not an administrator - nothing in the product
  // hands out that flag - so the settings page answers forbidden rather than
  // opening; what matters here is that the 401 is gone and the surface with it.
  const email = uniqueEmail()
  await register(page, email)
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '403',
  )
  // The account is signed in, so the refusal must not call it a guest. The
  // sentence itself is pinned by the SDK unit; e2e pins only the word, so that
  // rewording the copy stays one file of work (HIL-776).
  await expect(page.getByTestId('page-error')).not.toContainText(/guest/i)
})

test('holds the address on submit and creates the account only on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Accepting the terms reserves the address and mails one code — it registers
  // nobody and takes no password, so the page stays gated and the surface steps to
  // the code screen.
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  const code = await readRegisterCode(email)
  // Any code but the delivered one; picked off it so the run can never guess right.
  const wrongCode = code === '000000' ? '111111' : '000000'

  // A wrong code is an inline error on the step the person is already on: no
  // account is left behind and nothing rolls back.
  await submitRegistrationCode(page, wrongCode)
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The delivered code proves the address and hands over the password screen; the
  // password saved there is what creates the account and signs the session in
  // (HIL-825) — and the surface says so instead of vanishing (HIL-422). The gate
  // holds the resume until that panel is acknowledged, which is why the profile is
  // still gated here and comes through on Continue.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('auth-heading')).toHaveText('Choose a password')
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
  await submitFirstPassword(page, PASSWORD)
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
})

test('makes an account with no password, then signs it in by the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // The other way off the registration password step (HIL-1008): taking the exit
  // creates the account with NO password and no other secret of its own, and the
  // mailed link is what it signs in by from then on.
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))
  await finishWithoutPassword(page)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()

  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // An account with no password is not given a password field to fail against:
  // typing its address offers the link instead, and the person sends it with one
  // click. The address is known, so this send does not ask for terms.
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toHaveCount(0)
  await clickSubmit(page.getByTestId('auth-icon-magic-link'))

  await expect(page.getByTestId('auth-heading')).toHaveText('Check your inbox')
  await expect(page.getByTestId('auth-code')).toBeVisible()

  // The sign-in letter carries BOTH halves: a link to click and a companion code
  // to type into this field (HIL-606). Entering the code finishes the sign-in.
  await typeInto(page.getByTestId('auth-code'), await readMagicLinkCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(
    nameFromEmail(email),
  )
})

test('signs a member in by the code, on an address that already has an account', async ({
  page,
}) => {
  // A DIFFERENT account from the case above, and made the ordinary way: the send
  // gate holds a second letter to one address for a minute, so a spec that asked
  // twice for the same one would be waiting on a letter nobody mailed.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()

  // An address that has an account is not held and shows no terms: the letter
  // goes out on the click.
  await expect(page.getByTestId('auth-heading')).toHaveText('Check your inbox')
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(page.getByTestId('auth-code'), await readMagicLinkCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(
    nameFromEmail(email),
  )
})

test('signs a member in by clicking the link in the letter, from a cold load', async ({
  page,
}) => {
  const email = uniqueEmail()

  // An account made the ordinary way first. The envelope stands where it still
  // stands after HIL-1008 — beside the password field of an account that has one,
  // as the way past that password — and a free address no longer offers it at all.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // The other half of the letter (HIL-606 covers the code): this is the person
  // whose mail is open on the same device, who just clicks.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()
  await expect(page.getByTestId('auth-link-sent')).toBeVisible()

  // The click itself: a full browser load of the return route, which is the
  // condition the defect needed (HIL-607). The relay mounts while the socket is
  // still opening, so the confirm has to wait for a connection that can carry it
  // rather than be dropped and reported as an unreachable server.
  await gotoAuthReturn(
    page,
    returnPath(await readMagicLinkUrl(email)),
    'auth-magic',
  )

  // Home, signed in: the relay navigates on success and the session it upgraded
  // is the one this tab is holding.
  await expect(page.getByTestId('nav-logout')).toBeVisible()
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(
    nameFromEmail(email),
  )

  // Two letters and no more: the code the account was made with, and the link it
  // came back by. Whichever half of the second was used, the click bought no third.
  expect(await mailsTo(email)).toHaveLength(2)
})

test('turns a tampered sign-in link down on its own screen', async ({
  page,
}) => {
  const email = uniqueEmail()

  // An account first, for the same reason as the case above: the envelope is
  // offered beside a password, never on a free address (HIL-1008).
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()
  await expect(page.getByTestId('auth-link-sent')).toBeVisible()

  // A token nobody minted. The point is WHICH screen answers: a refusal that
  // reached the server looks like this, and a click that reached nobody used to
  // look exactly the same — which is what made the defect invisible.
  const tampered = new URL(await readMagicLinkUrl(email))
  tampered.searchParams.set('token', 'not-the-token-that-was-mailed')
  await gotoAuthReturn(page, returnPath(tampered.toString()), 'auth-magic')

  await expect(page.getByTestId('auth-magic-error')).toBeVisible()
  await expect(page.getByTestId('auth-magic-to-login')).toBeVisible()
  // Nothing was signed in, and the letter is still the only one.
  await expect(page.getByTestId('nav-logout')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(2)
})

test('says how the letter is going, to every tab of the browser and across a reload', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)

  // The line the person watches instead of guessing. It arrives over the socket a
  // tick behind the screen, so waiting for its last state is also waiting for the
  // whole chain to have run: the command reported "queued", the mail queue reported
  // "sending", and the transport took the letter (HIL-826).
  // The code field is measured before the line settles and after: the line
  // takes room the screen held for it from the start, so neither its arrival
  // nor its changes move what is under it (HIL-977).
  const codeField = page.getByTestId('auth-code')
  await expect(codeField).toBeVisible()
  const codeTop = await watchTop(codeField)
  const sent = `Sent to ${email}`
  await expect(page.getByTestId('auth-send-progress')).toContainText(sent)
  await codeTop.unchanged()

  // The whole line sits behind a button in every state, not only on a refusal.
  await expect(page.getByTestId('auth-send-progress-details')).toBeVisible()

  // A reload keeps it, because the line belongs to the SESSION and not to the
  // socket: the tab that comes back is told what it is owed by its handshake, the
  // same frame that gives it the code screen back.
  await page.reload()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-send-progress')).toContainText(sent)

  // And a second tab of the same browser reads the same line, having sent nothing
  // and asked for nothing - which is the same addressing that keeps it away from
  // every OTHER browser.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-send-progress')).toContainText(sent)

  // One letter for all of that: reading the line costs no send.
  expect(await mailsTo(email)).toHaveLength(1)
})

test('converges a second waiting tab onto the registration the first one confirms', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  // Tab A holds the address from the gated profile.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)

  // Tab B opens the same session's surface and is ALREADY on the code screen,
  // naming the address it never typed (HIL-486): the unfinished step lives on the
  // server, so it is answered to every tab of the session. Nothing is submitted
  // here, so no second letter goes out either — both wait on the one in the inbox.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-surface')).toContainText(email)
  expect(await mailsTo(email)).toHaveLength(1)

  // Tab A confirms. Tab B is not merely moved along: it really enters, resolving
  // the self user of the account tab A just created. It gets no panel of its own,
  // and that is the merged rule rather than a gap: the login rotates the session
  // token and drops every socket but the initiator's (HIL-582), the sentence lives
  // on the CONNECTION (HIL-422), and only the socket that trades the rotation
  // ticket inherits one (HIL-423). Tab B comes back on the new token signed in and
  // un-gated, which is exactly what it shows below.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('nav-signin')).toHaveCount(0)
})

test('puts a held address back on its code step after a silent walk-away', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // Leaving in SILENCE — closing the tab, coming back later — says nothing and
  // sends nothing, so the hold this browser took stands (HIL-608). This is the
  // half of the old "not that address?" that survived HIL-829: the other half,
  // pressing the way out, now frees the address instead (the case below).
  await gotoPage(page, '/profile')

  // And the return is answered with the code step of the letter already sent:
  // nothing was submitted and no second letter was mailed. Only THIS browser's
  // hold answers that way — another browser is offered the ways to register it.
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toContainText(email)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(1)
})

test('gives the held address its own code back, instead of a second registration', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // The same silent return as the case above, carried through to the end: what
  // the returning browser is handed is its own unfinished registration, not the
  // offer to start one on an address it has already reserved (HIL-651). So the
  // code from the FIRST letter is the one this screen accepts, and no second
  // letter was ever mailed.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-password')).toHaveCount(0)

  await submitRegistrationCode(page, code)
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(
    nameFromEmail(email),
  )
  expect(await mailsTo(email)).toHaveLength(1)
})

test('frees the address when the way out is pressed, and starts over on it', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // Pressed, not walked away from: the person said this registration is not
  // wanted, so the hold goes with the wait (HIL-829). The control says as much,
  // at the foot of the card and in red.
  const wayOut = page.getByTestId('auth-cancel-registration')
  await expect(wayOut).toHaveText('Cancel registration')
  await wayOut.click()
  await expect(page.getByTestId('auth-identifier')).toBeVisible()

  // The proof the address is free again is what the lookup answers about it: a
  // free address it offers to register, where a held one would have offered the
  // standing code back. submitRegistration asserts that heading itself, and
  // lands on the code step of a SECOND registration.
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()

  // No second letter, and that is the price of the gate rather than a gap: the
  // cooldown lives on the ADDRESS and outlives the cancel (HIL-421), or the way
  // out would be a channel for mailing a stranger without limit. So the new
  // registration opens under the countdown, and the code already delivered is
  // the one it accepts.
  await expect(page.getByTestId('auth-resend-in')).toContainText(/\d+:\d{2}/)
  expect(await mailsTo(email)).toHaveLength(1)
})

test('gives the code back to a tab that returns to an address another tab of the browser just held', async ({
  page,
  context,
}) => {
  // A held address is reached via two tabs because every exit from the code
  // screen frees the address (HIL-829), which took away the old single-tab way
  // back. The screen itself is kept: when tab B returns to a field whose address
  // tab A just held, it is told its browser already holds a code.
  const email = uniqueEmail()

  // Tab A opens first so the session already exists when tab B arrives.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Tab B opens the surface on the landing modal, standing on an empty field.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)

  // Tab B must step off the field onto the terms screen BEFORE tab A holds the
  // address: typing an address that is ALREADY held immediately moves the
  // machine to the code step (applyDetection, HIL-651), bypassing held_identifier.
  // Continuing to terms is a purely local transition and sends nothing.
  await typeInto(second.getByTestId('auth-identifier'), email)
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Before you continue',
  )

  // Tab A holds the address and sends the one code. Taking a hold broadcasts
  // nothing to the other tabs of the session: hilos_auth_converge only fires on
  // cancel, confirmed code, created account, or expiry (cancelRegistration(),
  // grantRegistrationToSession(), convergeRegistration() and
  // rollBackRegistrationWaiters() in AbstractSessionsLibraryAgent.php), so tab B
  // remains un-moved on its terms screen.
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // Tab B goes back to the identifier field. Returning from terms invokes
  // backToIdentifier() in authFlow.ts, which re-asks the lookup without
  // advancing the step, and screenKeyOf() draws the screen its answer names.
  // Because the address was held while B was away, the lookup answers pending —
  // rendering the held_identifier screen.
  await second.getByTestId('auth-restart').click()
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'You already have a code',
  )
  await expect(second.getByTestId('auth-identifier')).toHaveValue(email)
  await expect(second.getByTestId('auth-password')).toHaveCount(0)
  await expect(second.getByTestId('auth-resume-code')).toHaveText(
    'Enter the code',
  )

  // The way back to the code screen sends nothing and requests no second code:
  // it resumes the held registration locally.
  await clickSubmit(second.getByTestId('auth-resume-code'))
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Confirm your email',
  )

  // The code from the FIRST letter carries tab B through to completion.
  await submitRegistrationCode(second, code)
  await submitFirstPassword(second, PASSWORD)
  await continueFromDone(second)
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // Exactly one letter for both tabs: no second code was mailed.
  expect(await mailsTo(email)).toHaveLength(1)
})

test('carries a proved registration to its password screen, in every tab, and leaves nothing behind if it is dropped', async ({
  page,
  context,
  browser,
}) => {
  // HIL-825, end to end. Three claims in one trip because they are one decision:
  // the code buys a screen and not an account, that screen belongs to the SESSION
  // and not to the tab that typed the code, and a registration abandoned on it
  // leaves neither an account nor a credential.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))

  // The account is not made yet, which is the whole point: between the code and a
  // password there would stand an account with a proved address and no way in.
  await expect(page.getByTestId('auth-heading')).toHaveText('Choose a password')
  await expect(page.getByTestId('auth-new-password')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The hidden login the password manager files the entry under. Absent, it saves
  // a password with no address against it, and its owner cannot use it later.
  await expect(page.getByTestId('auth-username')).toHaveValue(email)

  // A second tab of the same browser opens ON that screen, having typed nothing:
  // the proof is durable on the hold, so the handshake answers every tab of the
  // session with the step the registration really stands on.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await expect(second.getByTestId('auth-code')).toHaveCount(0)

  // Dropped here, on purpose, with nothing saved. Asked from a browser that shares
  // none of this session's state, the address must read as free — no account was
  // made, and no credential was stored for one that was not.
  const stranger = await browser.newContext()
  const strangerPage = await stranger.newPage()
  await gotoPage(strangerPage, '/profile')
  await expect(strangerPage.getByTestId('auth-surface')).toBeVisible()
  await typeInto(strangerPage.getByTestId('auth-identifier'), email)
  await expect(strangerPage.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await expect(strangerPage.getByTestId('auth-password')).toHaveCount(0)
  await stranger.close()
})

test('moves a tab already standing on the address onto the password screen when another tab of the browser proves the code', async ({
  page,
  context,
}) => {
  // HIL-1065, the live half of the step the test above proves at the handshake.
  // The step belongs to the SESSION, so a tab that was open before the
  // registration began, and never asked for a code, follows it without a reload.
  const email = uniqueEmail()

  // Tab A opens first so the session already exists when the other tabs arrive.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Tab B types the address BEFORE tab A holds it: typing an address that is
  // already held jumps straight to the code step (HIL-651).
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await typeInto(second.getByTestId('auth-identifier'), email)
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )

  // Tab C opens the surface and types nothing.
  const third = await context.newPage()
  await gotoPage(third, '/')
  await expect(third.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(third)
  await expect(third.getByTestId('auth-identifier')).toBeVisible()

  // Tab A holds the address and the one letter goes out; taking the hold moves
  // nobody.
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )

  // Tab A proves the code, and tab B follows it with no reload.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('auth-heading')).toHaveText('Choose a password')
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Choose a password',
  )
  await expect(second.getByTestId('auth-new-password')).toBeVisible()

  // Tab C stays where it was. Asserted after tab B moved, so the frame has had
  // its moment to arrive: it now reaches tab C too, and only the client drops it
  // for naming an address that is not on screen.
  await expect(third.getByTestId('auth-identifier')).toHaveValue('')
  await expect(third.getByTestId('auth-new-password')).toHaveCount(0)
})

test('opens a fresh tab on the new-password step once the code is accepted', async ({
  page,
  context,
}) => {
  // HIL-648. The recovery leg's own e2e is HIL-426; this case is here because the
  // defect is here: a tab opened AFTER the code was accepted was drawing the code
  // screen again, a step its session had already passed.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Tab A recovers as far as the accepted code: the key icon beside the password
  // is what starts it, and the mailed code buys the password screen.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await typeInto(
    page.getByTestId('auth-code'),
    await readPasswordResetCode(email),
  )
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()

  // Tab B opens the same session's surface and lands where the SESSION stands -
  // on the new password, not back on the code it never typed. The grant belongs
  // to the session, so the step is answered to every tab of it.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await expect(second.getByTestId('auth-code')).toHaveCount(0)

  // And it is a real screen rather than a drawing: the password typed in the tab
  // that proved nothing itself is accepted, on the grant its session holds. A
  // DIFFERENT passphrase, so a save that silently did nothing could not pass.
  await typeInto(second.getByTestId('auth-new-password'), RECOVERED_PASSWORD)
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('tells the tab that did not save the password that the flow succeeded', async ({
  page,
  context,
}) => {
  // HIL-649. The recovery leg's own e2e is HIL-426; this case is here because the
  // defect is here, and because the case above already stages the two tabs it needs.
  // Saving the password signs the session in, and a sign-in rotates the token and
  // drops every OTHER socket of that session (HIL-582). Tab A therefore came back on
  // the new cookie carrying nothing, the gate saw a session that was up and closed the
  // surface — so the screen the flow had just earned was taken away before it could be
  // read. The flow ended in that tab, and never said it had succeeded.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Tab A gets as far as the password screen, exactly as the case above does.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await typeInto(
    page.getByTestId('auth-code'),
    await readPasswordResetCode(email),
  )
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()

  // Tab B of the same browser is the one that saves it, and so the one that acts.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(second)
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await typeInto(second.getByTestId('auth-new-password'), RECOVERED_PASSWORD)
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // The case: tab A typed nothing and is told what happened anyway, rather than
  // being emptied. Nothing is clicked in it to get there.
  //
  // Waited for with toPass rather than a plain expect, for the same reason
  // session-rotation.spec.ts waits on the rotated cookie that way: what has to
  // happen first is a whole round trip of the browser's own - this tab's socket is
  // dropped by the sign-in, the tab reconnects, trades nothing, and only its new 101
  // carries the sentence. One expect timeout covers that on an idle box and does not
  // when the run shares the machine with a second demo lane.
  await expect(async () => {
    await expect(page.getByTestId('auth-heading')).toHaveText(
      'Password changed',
    )
  }).toPass()
  await expect(page.getByTestId('auth-continue')).toHaveText('Continue')

  // And read once is read everywhere (HIL-865): Continue in the tab that DID act
  // takes the panel off this one too. The truth is the row - clearSessionAck
  // clears the mark and republishes it to every live socket of the session - so
  // what closes the copy here is the cleared mark arriving, not the click.
  await continueFromDone(second)
  await expect(second.getByTestId('auth-surface')).toHaveCount(0)

  // toPass for the same reason as above: this is a whole trip through the server
  // and back down the other tab's socket, not a local unmount.
  await expect(async () => {
    await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  }).toPass()
})

test('lowers the standing panel in the other tab when the session logs out', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  // Tab A registers from the gated profile, so its surface stands IN PLACE rather
  // than in a modal and the shell around it stays reachable. Continue is
  // deliberately not pressed: the session is signed in and still owes the sentence.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Your account is ready',
  )

  // Tab B typed nothing and finished nothing. The mark belongs to the SESSION
  // (HIL-875), so it arrives on this tab's own handshake and the gate raises the
  // same panel here — which is what makes the next step a two-tab case at all.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Your account is ready',
  )
  await expect(second.getByTestId('auth-continue')).toBeVisible()

  // The case: the session ends with the panel still standing. What it announces is
  // about the account this session no longer has, so the logout takes it down; it
  // used to restate it instead, and tab B was left holding an announcement over an
  // anonymous shell, with nothing on screen that could answer it and no way out
  // but a reload.
  await logout(page)

  // toPass because this is a whole trip through the server and back down the other
  // tab's socket, as in the recovery case above, and not a local unmount.
  await expect(async () => {
    await expect(second.getByTestId('auth-surface')).toHaveCount(0)
  }).toPass()
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('nav-profile-name')).toHaveCount(0)
})

test('offers a countdown instead of a resend while the cooldown holds', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // The gate is the backend's and it is armed by the send itself (HIL-421/486), so
  // the screen cannot even offer a second letter yet: where the button would be,
  // it counts down to when one becomes possible.
  await expect(page.getByTestId('auth-resend-in')).toContainText(/\d+:\d{2}/)
  await expect(page.getByTestId('auth-resend')).toHaveCount(0)

  // And the first code is still the live one — nothing was minted behind the
  // countdown, so it still opens the account.
  await submitRegistrationCode(page, code)
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(await mailsTo(email)).toHaveLength(1)
})
