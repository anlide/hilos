import { test, expect } from '@playwright/test'

import { signUp } from '../helpers/session'
import { gotoPage } from '../helpers/page'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine reports `connected` on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// Session bootstrap e2e: the client-minted cookie rides the handshake and the
// session scope tracks the current user. Under session≠user a fresh visitor is
// anonymous, so the user is established by registering; the session upgrade rides
// the same connection and the current user renders in place.
test('session bootstrap resolves the current user', async ({ page }) => {
  const user = await signUp(page)
  await expect(page.getByTestId('self-user')).toHaveText(user.name)
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

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await expect
    .poll(() =>
      sentFrames.some((payload) => {
        try {
          const message = JSON.parse(payload) as { type?: string; page?: string }

          return message.type === 'page_subscribe' && message.page === 'main'
        } catch {
          return false
        }
      }),
    )
    .toBe(true)
})

// Step-7.3.4 list render e2e: the main page answers with a `page_response`
// carrying the `mainUsers` list; the normalizer folds it into the page scope
// and the roster renders the connected self user as a participant — the first
// list rendered end-to-end.
test('renders the connected user in the participant roster', async ({
  page,
}) => {
  const user = await signUp(page)

  await expect(
    page.getByTestId('participant').filter({ hasText: user.name }),
  ).toBeVisible()
})

// Main page event stream e2e: registering appends a `user_registered` event to
// the `mainEvents` list; the stream resolves the target user's name against the
// entity store and renders the service notice for the self user. Registration is
// now explicit (the auth surface), so the notice fires from the register action.
test('renders the own registration notice in the event stream', async ({
  page,
}) => {
  const user = await signUp(page)

  await expect(
    page
      .getByTestId('event')
      .filter({ hasText: user.name })
      .filter({ hasText: 'registered in chat' }),
  ).toBeVisible()
})

// Main page bot list e2e: the main page answers with a `mainBots` list; with no
// bots seeded the section still renders with its empty state, proving the list
// is wired end-to-end.
test('renders the bot list section', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await expect(page.getByTestId('bots-header')).toBeVisible()
})

// Message composer e2e: submitting the bottom-pinned form sends the `message`
// action frame over the live socket and starts the re-send lockout — the first
// client-to-server action wired end-to-end. The publish itself rides backend
// moderation, so the deterministic assertion is the sent frame plus the gated
// button, not the message appearing in the stream.
test('sends a message action and starts the re-send lockout', async ({
  page,
}) => {
  const sentFrames: string[] = []
  page.on('websocket', (ws) => {
    ws.on('framesent', (frame) => {
      if (typeof frame.payload === 'string') {
        sentFrames.push(frame.payload)
      }
    })
  })

  // The composer is gated for anonymous, so sign in first; the send then rides
  // the same live connection.
  await signUp(page)

  await page.getByTestId('message-input').fill('hello hilos')
  await page.getByTestId('message-send').click()

  await expect
    .poll(() =>
      sentFrames.some((payload) => {
        try {
          const message = JSON.parse(payload) as {
            type?: string
            action?: string
            data?: { content?: string }
          }

          return (
            message.type === 'action' &&
            message.action === 'message' &&
            message.data?.content === 'hello hilos'
          )
        } catch {
          return false
        }
      }),
    )
    .toBe(true)

  await expect(page.getByTestId('message-input')).toHaveValue('')
  await expect(page.getByTestId('message-cooldown')).toBeVisible()
  await expect(page.getByTestId('message-send')).toBeDisabled()
})

// Composer round-trip e2e: a submitted message rides the `message` action; the
// backend moderates and publishes it, the moderation state flows back through
// the `selfConnection` data slot, and the published message renders in the
// event stream — the composer driven by self-connection state end to end.
test('renders a sent message in the event stream after moderation', async ({
  page,
}) => {
  await signUp(page)

  await page.getByTestId('message-input').fill('hello from e2e')
  await page.getByTestId('message-send').click()

  await expect(
    page.getByTestId('event-text').filter({ hasText: 'hello from e2e' }),
  ).toBeVisible()
})
