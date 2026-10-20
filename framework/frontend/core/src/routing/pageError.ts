// Local route misses and component import failures use the existing error
// surface's presentation shape, never a new wire schema or cross-process state.
// Server title and message still arrive on the page-subscription error
// (HIL-1516); these fallbacks cover failures the server cannot answer.
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

/** HTTP status of a page whose component could not be imported. */
const PAGE_LOAD_FAILED_STATUS = 503

/** Machine-readable code for a local component import failure. */
const PAGE_LOAD_FAILED_CODE = 'page_load_failed'

/** A safe local fallback; loader diagnostics never reach the error surface. */
// TODO(i18n): Remove this client-side fallback when backend-provided local error copy is available.
export const PAGE_LOAD_FAILED_ERROR: PageError = Object.freeze({
  httpCode: PAGE_LOAD_FAILED_STATUS,
  errorCode: PAGE_LOAD_FAILED_CODE,
  title: 'Page could not be loaded',
  message: 'Please try opening this page again.',
})
