// A browser that refuses cookies cannot stay signed in to Hilos: every sign-in
// rotates the session token through a cookie, and a browser that drops the write
// reconnects as a stranger a second after the password was right. Nothing on the
// wire tells the two apart, so the sign-in surfaces ask the browser itself and,
// when it says no, show why instead of a form that cannot work here (HIL-1074).

/**
 * Whether this browser says it refuses cookies.
 *
 * Strictly `=== false`: Node has a `navigator` whose `cookieEnabled` is
 * undefined (the build, the prerender, the core's own unit tests), and there the
 * answer must read "cookies are accepted" — a static build never draws the
 * refusal. The flag is read once, where a surface is created: the browser fires
 * no event when its settings change, which is why the words send the person to
 * reload the page.
 */
export function browserRefusesCookies(): boolean {
  return typeof navigator !== 'undefined' && navigator.cookieEnabled === false
}

/**
 * The words of the card a cookie-refusing browser sees in place of sign-in.
 *
 * Kept in the core for the reason `RECONNECT_DRAGGING_COPY` is: the three view
 * packages must not drift into three different sentences for one state.
 *
 * `linkKept` heads the card only on the magic-link return page, which in such a
 * browser does not spend the link: the link confirms nothing but the address
 * and is good in any browser, so the person can still open it elsewhere.
 */
export const COOKIES_REFUSED_COPY = {
  title: 'Sign-in needs cookies',
  message:
    "This browser doesn't accept cookies, so you can't stay signed in here.",
  remedy:
    'Allow cookies for this site and reload the page, or sign in from another browser.',
  linkKept:
    "This sign-in link hasn't been used — you can still open it in another browser.",
} as const
