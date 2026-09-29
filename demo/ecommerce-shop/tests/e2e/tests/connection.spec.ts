import { test, expect } from '@playwright/test'
import { gotoPage } from '../helpers/page'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine — running through the React adapter — reports `connected`
// on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

// Session bootstrap e2e: the daemon-issued cookie rides the handshake, the
// sessions library resolves an anonymous session, and the framework
// handshake_response answers that there is no account. This demo hands a
// visitor no name, so the line says the visit is anonymous.
test('session bootstrap leaves the visitor anonymous', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('self-anonymous')).toHaveText(
    'Browsing anonymously',
  )
  await expect(page.getByTestId('self-user')).toHaveCount(0)
})
