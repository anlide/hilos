import { test, expect } from '@playwright/test'

import { clickSubmit, signUp, typeInto } from '../helpers/session'
import { gotoPage } from '../helpers/page'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'

// spec-owner: demo — chat's main screen: participants, event stream, bots list and messages

// Chat's main screen: the participant roster, bot list, message composer and
// event stream over the live socket.

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

  // The stand's model answers only what a spec dictated, so the permission is
  // ordered up front under a key the text carries; with none the text would come
  // back into the field as "Moderation unavailable" and the input never empty.
  const key = modelKey()
  const text = `hello hilos ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

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
            message.data?.content === text
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

  const key = modelKey()
  const text = `hello from e2e ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

  await expect(
    page.getByTestId('event-text').filter({ hasText: text }),
  ).toBeVisible()
})

// The chat message list owns its own scroll: the shell is a fixed-height
// viewport (vh-100 + overflow-hidden) and the event stream is an overflow-auto
// region (the min-h-0 flex chain), so messages scroll inside the card rather
// than growing the document.
test('the event stream is its own scroll region', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const overflowY = await page
    .getByTestId('events-scroll')
    .evaluate((el) => getComputedStyle(el).overflowY)
  expect(overflowY).toBe('auto')

  // The document itself never scrolls: the shell is locked to the viewport, so
  // only inner regions (here the event stream) scroll.
  const documentScrolls = await page.evaluate(
    () =>
      document.documentElement.scrollHeight >
      document.documentElement.clientHeight + 1,
  )
  expect(documentScrolls).toBe(false)
})
