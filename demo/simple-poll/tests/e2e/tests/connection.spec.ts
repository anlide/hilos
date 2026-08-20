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

// Session bootstrap e2e: the daemon-issued cookie rides the handshake, the poll
// backend resolves an anonymous session and names the visitor behind it on its
// own `guest_identity` signal (HIL-611), and that name renders through the
// Angular adapter. The framework handshake_response arrives right after and
// answers that there is no account — which is why the line reads "Browsing as".
test('session bootstrap names the visitor', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('self-user')).toHaveText(/^Guest\d{4}$/)
})
