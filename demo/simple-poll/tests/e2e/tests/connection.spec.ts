import { test, expect } from '@playwright/test'

// Step-7.1 transport conformance (multiframework-core.md): the demo ships no
// backend yet, so `connected` is unreachable; the spec asserts the Connection
// machine runs through the Angular adapter — against the dead same-origin /ws
// endpoint it cycles between the two connect-cycle states.
test('connection machine runs through the Angular adapter', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText(/^(connecting|reconnecting)$/)
})
