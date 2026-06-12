import { test, expect } from '@playwright/test'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine reports `connected` on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// Session bootstrap e2e: the client-minted cookie rides the handshake, the
// backend registers the user and answers handshake_response, the normalizer
// ingests it into the session scope, and the current user renders.
test('session bootstrap resolves the current user', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('self-user')).toHaveText(/^User\d{4}$/)
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

  await page.goto('/')
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
  await page.goto('/')
  await expect(page.getByTestId('self-user')).toHaveText(/^User\d{4}$/)
  const selfName = (await page.getByTestId('self-user').textContent()) ?? ''

  await expect(
    page.getByTestId('participant').filter({ hasText: selfName }),
  ).toBeVisible()
})
