import { test, expect, type Page } from '@playwright/test'
import {
  nameFromEmail,
  register,
  uniqueEmail,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  signUpAdmin,
  tableRowKeyByText,
} from '../../../../../framework/frontend/e2e/index.js'

import { openBotEdit, watchBotSearch } from '../helpers/bots'
import { logout, signUp } from '../helpers/session.js'
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
  const user = await signUp(expect, page)

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
  const user = await signUp(expect, page)

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
  await gotoPage(expect, page, '/')
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
  await signUp(expect, page)

  // The stand's model answers only what a spec dictated, so the permission
  // is ordered up front under a key the text carries; with none the text
  // would come back into the field as "Moderation is unavailable right now"
  // and the input never empty.
  const key = modelKey()
  const text = `hello hilos ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

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
  await signUp(expect, page)

  const key = modelKey()
  const text = `hello from e2e ${key}`
  await dictateModerationVerdict(key, true, 'ok')

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(
    page.getByTestId('event-text').filter({ hasText: text }),
  ).toBeVisible()
})

// The chat message list owns its own scroll: the shell is a fixed-height
// viewport (vh-100 + overflow-hidden) and the event stream is an overflow-auto
// region (the min-h-0 flex chain), so messages scroll inside the card rather
// than growing the document.
test('the event stream is its own scroll region', async ({ page }) => {
  await gotoPage(expect, page, '/')
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

test('gates sending behind the surface, and returns the identity line to anonymous on logout', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // Anonymous read: the live event stream renders without a session.
  await expect(page.getByTestId('events-scroll')).toBeVisible()

  // The composer is gated: the message input is disabled and the send control
  // becomes a Sign in button rather than sending.
  await expect(page.getByTestId('message-input')).toBeDisabled()
  await expect(page.getByTestId('message-signin')).toBeVisible()

  // The composer's Sign in button opens the same surface as the auth-gate modal (requireAuth),
  // in place over the live page.
  await page.getByTestId('message-signin').click()
  const modal = page.getByTestId('modal')
  await expect(modal).toBeVisible()
  await expect(modal.getByTestId('auth-surface')).toBeVisible()

  // Registering through the modal upgrades the session; the gate closes the modal
  // off the session upgrade and the composer un-gates in place.
  await register(expect, page, email)
  await expect(modal).toBeHidden()
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('message-signin')).toHaveCount(0)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // The identity line's live transition (HIL-625). Logging out is one of the
  // four ways into the anonymous state named in the ticket — purge, expiry and
  // an anonymized restore are the others — and on the wire all four are the
  // same thing: a handshake response with no current user. This is the one of
  // them a browser can walk into, and it walks into it WITHOUT a navigation: the
  // session scope drops the user, and the line re-renders off that ref the same
  // way the shell drops the profile link. What the line must not do is keep the
  // "Signed in as" sentence with the name gone from it.
  await logout(expect, page)

  await expect(page.getByTestId('self-anonymous')).toHaveText(
    'Browsing anonymously',
  )
  await expect(page.getByTestId('self-user')).toHaveCount(0)
})

/**
 * A seeded bot that stays quiet while active: it speaks only on a topic of its
 * own (food), and no test of this demo dictates that topic to the analyzer.
 */
const QUIET_BOT = 'Quinn'

/**
 * Switch a seeded bot on or off in the bots admin table.
 *
 * @param page An administrator's page.
 * @param name The seeded bot's name.
 * @param active Whether the bot is to be active.
 * @param windows The observer installed before the first document.
 */
async function setBotActive(
  page: Page,
  name: string,
  active: boolean,
  windows: Awaited<ReturnType<typeof watchBotSearch>>,
): Promise<void> {
  await openBotEdit(page, name, windows)
  await page.getByTestId('admin-bots-active').setChecked(active)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/**
 * Count full document loads from this moment.
 *
 * The window's cold load has already happened. A click that stays inside the
 * live document leaves the count where it is.
 *
 * @param page The window whose later loads to count.
 * @returns A reader of the loads since this call.
 */
function countLaterLoads(page: Page): () => number {
  let loads = 0
  page.on('load', () => {
    loads += 1
  })

  return () => loads
}

/**
 * The page settled, the socket stayed up, and the document did not reload.
 *
 * @param page The window that just followed a link.
 * @param loads Document loads since that window's cold load.
 * @param baseline The load count before the click.
 */
async function expectSameDocument(
  page: Page,
  loads: () => number,
  baseline: number,
): Promise<void> {
  await expectPageReady(expect, page)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(loads()).toBe(baseline)
}

/**
 * Open the person's page and Quinn's page from the home roster, then return
 * through Conversations. Every step is a click in the document already open.
 *
 * @param page A window already on `/`, with its load count running.
 * @param userId The person's durable id.
 * @param name The name the person page must show.
 * @param rowKey Quinn's row key, read from the bots table.
 * @param loads Document loads since the cold load of this window.
 */
async function followHomeLinks(
  page: Page,
  userId: number,
  name: string,
  rowKey: string,
  loads: () => number,
): Promise<void> {
  const baseline = loads()

  await page.getByTestId(`participant-link-${userId}`).click()
  await expectSameDocument(page, loads, baseline)
  expect(new URL(page.url()).pathname).toBe(`/user/${userId}`)
  await expect(page.getByTestId('user-name')).toHaveText(name)

  await page.getByTestId('nav-conversations').click()
  await expectSameDocument(page, loads, baseline)
  expect(new URL(page.url()).pathname).toBe('/')

  await page.getByTestId(`bot-link-${rowKey}`).click()
  await expectSameDocument(page, loads, baseline)
  // The address shows the click opened Quinn's page; the heading
  // shows the name.
  expect(new URL(page.url()).pathname).toBe(`/bot/${rowKey}`)
  await expect(page.getByTestId('bot-name')).toHaveText(QUIET_BOT)

  await page.getByTestId('nav-conversations').click()
  await expectSameDocument(page, loads, baseline)
  expect(new URL(page.url()).pathname).toBe('/')
}

// The roster rows are real links. An administrator and a separate guest open
// the person's page and the quiet seeded bot from those links and come back
// through Conversations. The main list shows only active bots, so Quinn is
// switched on for the walk and off again afterwards. The load count starts
// after each window's first home navigation; later clicks must not reload it.
test.describe('home links', () => {
  test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

  test('opens a person and a bot from the home links without a reload', async ({
    browser,
    page,
  }) => {
    // Two bot edits, a second window, and both walks outlive the
    // single-client cap.
    test.slow()

    const windows = await watchBotSearch(page)
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const guestContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    let quinnActive = false

    try {
      const userId = await signUpAdmin(expect, page, {
        entryId: 'message-signin',
      })
      const name = (await page.getByTestId('self-user').innerText()).trim()
      await setBotActive(page, QUIET_BOT, true, windows)
      quinnActive = true
      const rowKey = await tableRowKeyByText(expect, page, QUIET_BOT)

      const guestPage = await guestContext.newPage()
      await gotoPage(expect, page, '/')
      await expectPageReady(expect, page)
      const adminLoads = countLaterLoads(page)
      await gotoPage(expect, guestPage, '/')
      await expectPageReady(expect, guestPage)
      const guestLoads = countLaterLoads(guestPage)

      await followHomeLinks(page, userId, name, rowKey, adminLoads)
      await followHomeLinks(guestPage, userId, name, rowKey, guestLoads)
    } finally {
      try {
        await guestContext.close()
      } finally {
        if (quinnActive) {
          await setBotActive(page, QUIET_BOT, false, windows)
        }
      }
    }
  })
})
