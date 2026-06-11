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
