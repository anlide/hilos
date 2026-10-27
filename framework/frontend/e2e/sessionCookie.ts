import type { Page } from '@playwright/test'

/**
 * Session cookie prefix derived by the framework when HILOS_SESSION_COOKIE_NAME is unset.
 * The auxiliary rotation cookie shares this prefix and ends with '_rotate'.
 */
export const SESSION_COOKIE_PREFIX = 'hilos_session_token_'
export const ROTATE_COOKIE_SUFFIX = '_rotate'

export function isSessionCookie(name: string): boolean {
  return (
    name.startsWith(SESSION_COOKIE_PREFIX) &&
    !name.endsWith(ROTATE_COOKIE_SUFFIX)
  )
}

export function isRotateCookie(name: string): boolean {
  return (
    name.startsWith(SESSION_COOKIE_PREFIX) &&
    name.endsWith(ROTATE_COOKIE_SUFFIX)
  )
}

/**
 * Read the session cookie of a browser context, excluding its rotation cookie.
 *
 * @param page Playwright page whose context has already opened the app.
 * @returns The session token this browser presents on its handshake.
 */
export async function sessionToken(page: Page): Promise<string> {
  const cookies = await page.context().cookies()
  const token = cookies.find((cookie) => isSessionCookie(cookie.name))?.value
  if (token === undefined) {
    throw new Error(`no ${SESSION_COOKIE_PREFIX}* cookie on this context`)
  }

  return token
}
