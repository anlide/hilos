import { test, expect } from '@playwright/test'
import { PROFILE_ADD_SMS_REQUEST_ACTION } from '../../../../../framework/frontend/core/src/base/index.js'

import {
  PASSWORD,
  SESSION_COOKIE_PREFIX,
  armSocketDrop,
  clickUnavailableControl,
  controlSocketReconnect,
  countSentFrames,
  expectConnectedInteraction,
  expectDisconnectedInteraction,
  expectWelcomedSockets,
  nextClientPaint,
  watchClientDocument,
  watchInteractionNodes,
  watchOpenedSockets,
  dropSocket,
  isSessionCookie,
  watchHandshakeFrames,
  type HandshakeFrameLog,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'
import { orphanSessionToken, signUp } from '../helpers/session.js'
import { uniquePhone } from '../../../../../framework/frontend/scripts/standSms.mjs'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine reports `connected` on the page. The guest's live
// welcome names the stand's project time zone, and the welcome after a
// dropped socket does too. Chat leaves the zone unset, so both frames carry
// the catalog default UTC. A non-UTC zone is proved by the backend tests.
test('websocket transport reaches connected', async ({ page }) => {
  await armSocketDrop(page)
  const welcomes = watchHandshakeFrames(page)

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect
    .poll(() => welcomes.map(welcomeProjectTimeZone))
    .toEqual([STAND_PROJECT_TIME_ZONE])

  const seen = welcomes.length
  const reconnected = page.waitForEvent('websocket')
  await dropSocket(page)
  await reconnected
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect
    .poll(() => welcomes.slice(seen).map(welcomeProjectTimeZone))
    .toEqual([STAND_PROJECT_TIME_ZONE])
})

// Session bootstrap e2e: the client-minted cookie rides the handshake and the
// session scope tracks the current user. Under session≠user a fresh visitor is
// anonymous, so the user is established by registering; the session upgrade rides
// the same connection and the current user renders in place.
test('session bootstrap resolves the current user', async ({ page }) => {
  const user = await signUp(expect, page)
  await expect(page.getByTestId('self-user')).toHaveText(user.name)
})

// Identity-line e2e (HIL-625): the other branch of that same line. A visitor with
// no account reads an explicit anonymous sentence, and the authenticated branch's
// marker is ABSENT rather than empty — Chat hands a guest no name, so there is
// nothing for `self-user` to carry, and an empty one is the bug this pins.
test('renders the anonymous identity line for a visitor with no account', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')

  await expect(page.getByTestId('self-anonymous')).toHaveText(
    'Browsing anonymously',
  )
  await expect(page.getByTestId('self-user')).toHaveCount(0)
})

// The case that opened HIL-625: an anonymized restore purged hilos_session while
// the browser kept its cookie, so the jar names a session the server has never
// heard of. The server does not refuse it — it opens a fresh anonymous session
// under that token — and the screen has to say so rather than render a
// "Signed in as" with nothing after it.
//
// The value is swapped inside the jar's real entry rather than assembled from the
// base URL, so the domain, path and flags stay whatever the stand issued and the
// test does not need to know its scheme or port.
test('renders the anonymous identity line when the cookie names no session', async ({
  page,
  context,
}) => {
  await gotoPage(expect, page, '/')

  const session = (await context.cookies()).find((cookie) =>
    isSessionCookie(cookie.name),
  )
  if (session === undefined) {
    throw new Error(`the stand issued no ${SESSION_COOKIE_PREFIX}* cookie`)
  }
  await context.addCookies([{ ...session, value: orphanSessionToken() }])

  await gotoPage(expect, page, '/')

  await expect(page.getByTestId('self-anonymous')).toHaveText(
    'Browsing anonymously',
  )
  await expect(page.getByTestId('self-user')).toHaveCount(0)
})

// Step-7.3.3 page-subscription infra e2e: on cold load the app subscribes the
// page named by the URL, so a page_subscribe frame for `main` goes out over
// the live nginx /ws upgrade once the connection reaches `connected`.
test('subscribes the URL page on load', async ({ page }) => {
  const sentFrames: string[] = []
  page.on('websocket', (ws) => {
    ws.on('framesent', (frame) => {
      if (typeof frame.payload === 'string') {
        sentFrames.push(frame.payload)
      }
    })
  })

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await expect
    .poll(() =>
      sentFrames.some((payload) => {
        try {
          const message = JSON.parse(payload) as {
            type?: string
            page?: string
          }

          return message.type === 'page_subscribe' && message.page === 'main'
        } catch {
          return false
        }
      }),
    )
    .toBe(true)
})

/** Chat stand default: the catalog answer when the project zone is unset. */
const STAND_PROJECT_TIME_ZONE = 'UTC'

// A missing or unreadable zone stays undefined, so a malformed welcome fails
// the UTC check instead of dropping out of it.
function welcomeProjectTimeZone(frame: HandshakeFrameLog): unknown {
  const data = frame.data
  if (typeof data !== 'object' || data === null) return undefined
  if (!('projectTimeZone' in data)) return undefined
  return data.projectTimeZone
}

// A held outage must be observable in the built SDK, including native fieldset
// inheritance, real pointer/keyboard gestures and the original mounted draft.
test('holds page and modal interaction until the real reconnect', async ({
  page,
}) => {
  const control = await controlSocketReconnect(page)
  const sockets = watchOpenedSockets(page)
  const sentActions = countSentFrames(page, '"type":"action"')
  const smsRequests = countSentFrames(page, PROFILE_ADD_SMS_REQUEST_ACTION)
  const watches: { dispose(): Promise<void> }[] = []
  try {
    await signUp(expect, page)
    await gotoPage(expect, page, '/profile/sign-in')
    await expectPageReady(expect, page)
    const document = await watchClientDocument(page)
    watches.push(document)
    const view = page.getByTestId('profile-sign-in-view')
    const identities = page.getByTestId('profile-identities-list')
    const add = page.getByTestId('profile-sign-in-add')
    const actionLink = page.getByTestId('hilos-breadcrumb-hilos_profile')
    const mainControls = { actionLink }
    const originalIdentities = await identities.innerText()
    const originalUrl = page.url()
    const mainNodes = await watchInteractionNodes(expect, page, [
      view,
      identities,
      add,
      actionLink,
    ])
    watches.push(mainNodes)
    await expectConnectedInteraction(expect, page, mainControls)
    await expect(add).toBeEnabled()
    let socketCount = sockets.length
    await expectWelcomedSockets(expect, sockets, socketCount)
    const mainActions = sentActions()

    // The page has no modal yet: both native and non-native controls refuse work.
    await control.drop()
    await expectDisconnectedInteraction(expect, page, mainControls)
    await expect(add).toBeDisabled()
    await expect(add).toHaveJSProperty('disabled', false)
    await clickUnavailableControl(page, add)
    await clickUnavailableControl(page, actionLink)
    await actionLink.focus()
    await page.keyboard.press('Enter')
    await page.keyboard.press('Space')
    await nextClientPaint(page)
    expect(page.url()).toBe(originalUrl)
    expect(sentActions()).toBe(mainActions)
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(identities).toHaveText(originalIdentities, {
      useInnerText: true,
    })
    await mainNodes.unchanged()
    await document.unchanged()

    await control.release()
    await expectWelcomedSockets(expect, sockets, ++socketCount)
    await expectConnectedInteraction(expect, page, mainControls)
    await expectPageReady(expect, page)
    await expect(add).toBeEnabled()
    await mainNodes.unchanged()
    await document.unchanged()
    expect(page.url()).toBe(originalUrl)
    expect(sentActions()).toBe(mainActions)

    // Open the ordinary protected flow; no SMS request is ever submitted.
    await clickSubmit(expect, add)
    const password = page.getByTestId('step-up-password')
    const choosePhone = page.getByTestId('profile-sign-in-choose-phone')
    await expect(password.or(choosePhone)).toBeVisible()
    if (await password.isVisible()) {
      await typeInto(password, PASSWORD)
      await clickSubmit(
        expect,
        page.getByTestId('profile-sign-in-add-step-up-confirm'),
      )
    }
    await clickSubmit(expect, choosePhone)
    const dialog = page.getByTestId('modal')
    const field = page.getByTestId('profile-add-sms-phone')
    const submit = page.getByTestId('profile-add-sms-request')
    const cancel = page.getByTestId('profile-sign-in-add-cancel')
    const modalControls = { actionLink, modal: { field, submit, cancel } }
    const draft = uniquePhone()
    await typeInto(field, draft)
    await expectConnectedInteraction(expect, page, modalControls)
    await field.focus()
    const modalNodes = await watchInteractionNodes(expect, page, [
      view,
      identities,
      dialog,
      page.getByTestId('profile-sign-in-add-modal'),
      field,
    ])
    watches.push(modalNodes)
    const modalActions = sentActions()

    await control.drop()
    await expectDisconnectedInteraction(expect, page, modalControls)
    // The input's own flag remains false: Chromium inherits the fieldset lock.
    await expect(field).toHaveJSProperty('disabled', false)
    await page.keyboard.press('Escape')
    await expectDisconnectedInteraction(expect, page, modalControls)
    await dialog.click({ position: { x: 2, y: 2 } })
    await expectDisconnectedInteraction(expect, page, modalControls)
    for (const unavailable of [
      field,
      submit,
      cancel,
      dialog.getByTestId('modal-close'),
    ]) {
      await clickUnavailableControl(page, unavailable)
      await expectDisconnectedInteraction(expect, page, modalControls)
    }
    await page.keyboard.press('9')
    await page.keyboard.press('Enter')
    for (const key of ['Tab', 'Shift+Tab', 'Tab']) {
      await page.keyboard.press(key)
      await expectDisconnectedInteraction(expect, page, modalControls)
    }
    await nextClientPaint(page)
    await expect(field).toHaveValue(draft)
    await expect(identities).toHaveText(originalIdentities, {
      useInnerText: true,
    })
    await modalNodes.unchanged()
    await document.unchanged()
    expect(page.url()).toBe(originalUrl)
    expect(sentActions()).toBe(modalActions)

    await control.release()
    await expectWelcomedSockets(expect, sockets, ++socketCount)
    await expectConnectedInteraction(expect, page, modalControls)
    await expectPageReady(expect, page)
    await nextClientPaint(page)
    await modalNodes.unchanged()
    await document.unchanged()
    await expect(field).toHaveValue(draft)
    await expect(identities).toHaveText(originalIdentities, {
      useInnerText: true,
    })
    expect(page.url()).toBe(originalUrl)
    expect(sentActions()).toBe(modalActions)

    // Only a new explicit close gesture can raise the existing dirty guard.
    await clickSubmit(expect, cancel)
    await expect(page.getByTestId('modal-confirm')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('modal-confirm-cancel'))
    await expect(page.getByTestId('modal-confirm')).toBeHidden()
    await expect(field).toHaveValue(draft)
    await clickSubmit(expect, cancel)
    await clickSubmit(expect, page.getByTestId('modal-confirm-discard'))
    await expect(dialog).toBeHidden()
    await expectConnectedInteraction(expect, page, mainControls)
    await expect(add).toBeEnabled()

    // The main page still obeys the shell after the window's lifetime ends.
    const closedActions = sentActions()
    await control.drop()
    await expectDisconnectedInteraction(expect, page, mainControls)
    await expect(add).toBeDisabled()
    await control.release()
    await expectWelcomedSockets(expect, sockets, ++socketCount)
    await expectConnectedInteraction(expect, page, mainControls)
    await expectPageReady(expect, page)
    await expect(add).toBeEnabled()
    await expect(identities).toHaveText(originalIdentities, {
      useInnerText: true,
    })
    await mainNodes.unchanged()
    await document.unchanged()
    expect(page.url()).toBe(originalUrl)
    expect(sentActions()).toBe(closedActions)
    expect(smsRequests()).toBe(0)
    await expect(page.getByTestId('profile-add-sms-code')).toBeHidden()
  } finally {
    try {
      await Promise.all(watches.map((watch) => watch.dispose()))
    } finally {
      try {
        await control.dispose()
      } finally {
        // The existing socket/frame observers are Page-scoped; close their owner.
        await page.close()
      }
    }
  }
})
