import { test, expect } from '@playwright/test'
import { gotoPage } from '../helpers/page'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine — running through the Angular adapter — reports
// `connected` on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// Session bootstrap e2e: the client-minted cookie rides the handshake, the
// poll backend resolves an agent-local user and answers handshake_response,
// the normalizer ingests it into the session scope, and the current user
// renders through the Angular adapter.
test('session bootstrap resolves the current user', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('self-user')).toHaveText(/^User\d{4}$/)
})
