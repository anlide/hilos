import { test, expect } from '@playwright/test'
import { PROFILE_ADD_SMS_REQUEST_ACTION } from '../../../../../framework/frontend/core/src/base/index.js'
import {
  PASSWORD,
  createTestUser,
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
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'
import { uniquePhone } from '../../../../../framework/frontend/scripts/standSms.mjs'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine — running through the Angular adapter — reports
// `connected` on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// Session bootstrap e2e: the daemon-issued cookie rides the handshake, the polls
// backend resolves an anonymous session and names the visitor behind it on its
// own `guest_identity` signal (HIL-611), and that name renders through the
// Angular adapter. The framework handshake_response arrives right after and
// answers that there is no account — which is why the line reads "Browsing as".
test('session bootstrap names the visitor', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('self-user')).toHaveText(/^Guest\d{4}$/)
})

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
    await createTestUser(expect, page)
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
