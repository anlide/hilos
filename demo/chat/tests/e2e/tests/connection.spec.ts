import { test, expect } from '@playwright/test'

import {
  isSessionCookie,
  SESSION_COOKIE_PREFIX,
} from '../../../../../framework/frontend/e2e/index.js'
import { orphanSessionToken, signUp } from '../helpers/session'
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

// Identity-line e2e (HIL-625): the other branch of that same line. A visitor with
// no account reads an explicit anonymous sentence, and the authenticated branch's
// marker is ABSENT rather than empty — Chat hands a guest no name, so there is
// nothing for `self-user` to carry, and an empty one is the bug this pins.
test('renders the anonymous identity line for a visitor with no account', async ({
  page,
}) => {
  await gotoPage(page, '/')

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
  await gotoPage(page, '/')

  const session = (await context.cookies()).find((cookie) =>
    isSessionCookie(cookie.name),
  )
  if (session === undefined) {
    throw new Error(`the stand issued no ${SESSION_COOKIE_PREFIX}* cookie`)
  }
  await context.addCookies([{ ...session, value: orphanSessionToken() }])

  await gotoPage(page, '/')

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

  await gotoPage(page, '/')
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
