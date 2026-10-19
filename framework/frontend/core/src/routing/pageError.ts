// The local miss a navigator shows for an address no declared route claims.
// It is a presentation value for the existing error surface, not a wire schema
// and not a stored cross-process state. Server title and message still arrive
// on the page-subscription error (HIL-1516); this object only covers the case
// the server is never asked about.
import { type PageSubscriptionError } from '../protocol/pageError.js'

/**
 * Drop the string index `z.looseObject` adds. `Omit` against that index
 * collapses every remaining field to `unknown`, so the index goes first and
 * `page` second. The result is still the subscription error without its page.
 */
type PageSubscriptionErrorFields = {
  [
    Key in keyof PageSubscriptionError as string extends Key ? never : Key
  ]: PageSubscriptionError[Key]
}

/**
 * The error surface's view of a failure: the subscription error without the
 * page it correlates. A real {@link PageSubscriptionError} still fits, and the
 * page key stays on the subscription.
 */
export type PageError = Omit<PageSubscriptionErrorFields, 'page'>

/** HTTP status of an address no route claims. */
const ROUTE_NOT_FOUND_STATUS = 404

/** Machine-readable code of that local miss. It is not `not_served`. */
const ROUTE_NOT_FOUND_CODE = 'route_not_found'

/**
 * The local route miss. Visible before the socket opens, and independent of
 * whether the connection succeeds.
 */
// TODO(i18n): Remove this client-side fallback when backend-provided local error copy is available.
export const ROUTE_NOT_FOUND_ERROR: PageError = Object.freeze({
  httpCode: ROUTE_NOT_FOUND_STATUS,
  errorCode: ROUTE_NOT_FOUND_CODE,
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
})
