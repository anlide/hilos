import { test, expect } from '@playwright/test'

// Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
// live daemon through the test nginx /ws WebSocket upgrade proxy, and the
// Connection machine — running through the React adapter — reports
// `connected` on the page.
test('websocket transport reaches connected', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})
