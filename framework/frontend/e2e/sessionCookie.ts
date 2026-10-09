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
