import { test, expect } from '../test.js'
import {
  gotoPage,
  watchThemeFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { currentUser } from '../helpers/handshakeCurrentUser.js'

test('websocket connects with an anonymous session and null current user', async ({
  page,
}) => {
  const frames = watchThemeFrames(page)
  await gotoPage(expect, page, '/')

  // Step-7.1 transport e2e (testing-strategy.md): the built app reaches the
  // live daemon through the test nginx /ws WebSocket upgrade proxy, and the
  // Connection machine — running through the React adapter — reports `connected`
  // on the page.
  await test.step('websocket transport reaches connected', async () => {
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
  })

  await test.step('guest handshake carries an explicit null current user', async () => {
    await expect
      .poll(
        () =>
          frames.filter((frame) => frame.type === 'handshake_response').length,
      )
      .toBeGreaterThan(0)
    for (const frame of frames) {
      if (frame.type === 'handshake_response') {
        expect(currentUser(expect, frame), frame.raw).toBeNull()
      }
    }
  })

  // Session bootstrap e2e: the daemon-issued cookie rides the handshake, the
  // sessions library resolves an anonymous session, and the framework
  // handshake_response answers that there is no account. This demo hands a
  // visitor no name, so the line says the visit is anonymous.
  await test.step('session bootstrap leaves the visitor anonymous', async () => {
    await expect(page.getByTestId('self-anonymous')).toHaveText(
      'Browsing anonymously',
    )
    await expect(page.getByTestId('self-user')).toHaveCount(0)
  })
})
