import type { Browser, BrowserContextOptions, Page } from '@playwright/test'

/**
 * Leave a second administrator on the stand, made in a browser context of its
 * own.
 *
 * The server refuses to revoke the last administrator, and no seed holds one,
 * so a test that takes the flag away needs another administrator to stay
 * behind. The test brings that one itself, before its revoke, rather than
 * leaning on an administrator some earlier test of the run happened to leave —
 * so it passes pointed alone on a fresh stand too.
 *
 * The grant is the demo's, not this helper's: each demo reaches its own
 * command-channel address and signs its visitor up its own way, so the caller
 * hands that step in. It runs in a fresh context, not in a new tab of the
 * test's page: identity is the session cookie, and two tabs of one context are
 * one person. A context made off the browser fixture inherits none of the
 * project's `use` options — the tolerance for the stand's self-signed
 * certificate included — so the caller passes them on.
 *
 * The context closes whether the grant succeeds or throws, and a failed grant
 * is not swallowed: the scenario does not go on without its second
 * administrator. The account is single-use and stays on the stand until its
 * reset — nothing here revokes it, deletes it, or remembers it for the next
 * test.
 *
 * @param browser The browser the test runs in.
 * @param grantAdmin The demo's grant: makes the visitor on the page it is
 *   handed an administrator and returns that person's user id.
 * @param contextOptions The project's `use` options the new context needs —
 *   `baseURL` and `ignoreHTTPSErrors`.
 */
export async function leaveSecondAdmin(
  browser: Browser,
  grantAdmin: (page: Page) => Promise<number>,
  contextOptions: BrowserContextOptions,
): Promise<void> {
  const context = await browser.newContext(contextOptions)
  try {
    const page = await context.newPage()
    await grantAdmin(page)
  } finally {
    await context.close()
  }
}
