import { test, expect } from '../test.js'
import { gotoPage } from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the smoke check asserts the BTC Tracker product brand.
// Category-1 smoke (testing-strategy.md): the Vue app renders from the built
// artifact through the docker test stack. Contains, not equals: the root is the
// SDK application shell carrying the brand, the connection indicator, and the
// routed page.
test('root page connects, stays anonymous, and renders BTC Tracker', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')

  await test.step('websocket transport reaches connected', async () => {
    // Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
    // live daemon through the test nginx /ws WebSocket upgrade proxy, and the
    // Connection machine — running through the Vue adapter — reports `connected`
    // on the page.
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
  })

  await test.step('session bootstrap leaves the visitor anonymous', async () => {
    // Session bootstrap e2e: the daemon-issued cookie rides the handshake, the
    // sessions library resolves an anonymous session, and the framework
    // handshake_response answers that there is no account. This demo hands a
    // visitor no name, so the line says the visit is anonymous.
    await expect(page.getByTestId('self-anonymous')).toHaveText(
      'Browsing anonymously',
    )
    await expect(page.getByTestId('self-user')).toHaveCount(0)
  })

  await test.step('blank page renders', async () => {
    await expect(page.getByTestId('app-root')).toContainText('BTC Tracker')
  })
})
