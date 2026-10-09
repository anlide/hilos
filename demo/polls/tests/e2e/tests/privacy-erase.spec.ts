import { test, expect, type BrowserContext } from '@playwright/test'

import { isSessionCookie } from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page.js'

// The erase on /privacy (HIL-839), and the three things only a real browser can
// answer for it.
//
// The first is the sweep itself: whether the values the framework declares are
// actually gone from THIS browser's two stores after the click. The second is the
// cookie, and it is the load-bearing one — the erase promises a browser that walks
// away carrying a different identifier than it arrived with, and that promise is
// kept on the far side of a rotation ticket, a reconnect and a Set-Cookie on a 101.
// Nothing below the browser can see all three. The third is the page's own word:
// the block replaces itself with what happened, rather than navigating away.
//
// The session cookie is read through the context's jar rather than through
// `document.cookie`: it is HttpOnly, so the page cannot see the value this spec
// is about — the same reason session-rotation.spec.ts reads it that way.

/** A key the framework declares in session storage (`oauthLogin.ts`). */
const OAUTH_PROVIDER_KEY = 'hilos.oauth.provider'

/** A key the framework declares in local storage (`maintenanceHint.ts`). */
const MAINTENANCE_HINT_KEY = 'hilos.protectedMode.hint'

/** The guest's position and the last settings pair (`themeBrowser.ts`). */
const THEME_PICK_KEY = 'hilos.theme.pick'
const THEME_SETTINGS_KEY = 'hilos.theme.settings'

/**
 * Read one cookie matching a predicate out of the context's jar.
 *
 * @param context The browser context holding the jar.
 * @param predicate Predicate to match the cookie name.
 * @returns The cookie's value, or the empty string when the jar holds none.
 */
async function cookieValue(
  context: BrowserContext,
  predicate: (name: string) => boolean,
): Promise<string> {
  const cookies = await context.cookies()

  return cookies.find((cookie) => predicate(cookie.name))?.value ?? ''
}

test('the erase empties this browser and moves it onto a new session', async ({
  page,
  context,
}) => {
  await gotoPage(page, '/privacy')

  // Seed one value in each store the registry names, through the very keys the
  // framework declares — a spec that seeded keys of its own would prove the sweep
  // erases what the spec wrote, not what the framework keeps.
  const serverSettings = await page.evaluate(
    ([provider, hint, pick, settings]) => {
      const receivedSettings = localStorage.getItem(settings)
      sessionStorage.setItem(provider, 'github')
      localStorage.setItem(hint, '1')
      localStorage.setItem(pick, 'dark')
      localStorage.setItem(settings, 'stale browser copy')

      return receivedSettings
    },
    [
      OAUTH_PROVIDER_KEY,
      MAINTENANCE_HINT_KEY,
      THEME_PICK_KEY,
      THEME_SETTINGS_KEY,
    ],
  )
  expect(serverSettings).not.toBeNull()

  // The identifier this browser arrived with: what the erase must change.
  const arrived = await cookieValue(context, isSessionCookie)
  expect(arrived).not.toBe('')

  await page.getByTestId('privacy-erase').click()
  // The confirmation names what goes, one line per registry entry, so the person
  // agrees to a list rather than to an adjective.
  await expect(page.getByTestId('privacy-erase-list')).toBeVisible()
  await expect(
    page.getByTestId('privacy-erase-list').locator('li'),
  ).toHaveCount(6)
  await expect(page.getByTestId('privacy-erase-list')).toContainText(
    'Your theme choice in this browser',
  )
  await expect(page.getByTestId('privacy-erase-list')).toContainText(
    'Remembered theme settings for this site',
  )

  const confirm = page.getByTestId('privacy-erase-confirm')
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()

  // Settled: the block has become the outcome, which is what the page promises
  // instead of navigating or reloading.
  const done = page.getByTestId('privacy-erase-done')
  await expect(done).toBeVisible()
  await expect(page.getByTestId('privacy-erase')).toHaveCount(0)
  await expect(done).toContainText('not account deletion')
  await expect(page.getByTestId('privacy-erase-partial')).toHaveCount(0)

  // The browser half: the guest's choice and the other seeded values are gone.
  // The settings cache is filled again by the new session's handshake below.
  const kept = await page.evaluate(
    ([provider, hint, pick]) => [
      sessionStorage.getItem(provider),
      localStorage.getItem(hint),
      localStorage.getItem(pick),
    ],
    [OAUTH_PROVIDER_KEY, MAINTENANCE_HINT_KEY, THEME_PICK_KEY],
  )
  expect(kept).toEqual([null, null, null])

  // The server half: the value arrives on the 101 of the reconnect the rotation
  // ticket triggers, a round trip after the outcome above was drawn.
  await expect(async () => {
    const now = await cookieValue(context, isSessionCookie)
    expect(now).not.toBe('')
    expect(now).not.toBe(arrived)
  }).toPass()

  // The stale local copy was erased; the server's complete pair replaced it
  // when the replacement session connected.
  await expect(async () => {
    const remembered = await page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_SETTINGS_KEY,
    )
    expect(remembered).toBe(serverSettings)
  }).toPass()

  // And the person is still on the page they walked to, reading what it said.
  expect(new URL(page.url()).pathname).toBe('/privacy')
})
