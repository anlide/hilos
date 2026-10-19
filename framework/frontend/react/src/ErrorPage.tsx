// ErrorPage — the SDK's full-page subscription-error surface. HilosView renders
// it in place of the routed page component whenever the navigator carries a page
// subscription error (subscription_page_error), displaying its server title and
// message. The connection stays live, so "Back to home" navigates
// without a refresh.
import type { PageSubscriptionError } from '@hilos/core'

import { HilosLink } from './HilosLink.js'

/** Props for {@link ErrorPage}: the page subscription error to display. */
export interface ErrorPageProps {
  /** The page subscription error the navigator carries. */
  error: PageSubscriptionError
}

/**
 * The full-page subscription-error surface.
 *
 * @param props The page subscription error to display.
 */
export function ErrorPage({ error }: ErrorPageProps) {
  return (
    <div
      className="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
      data-id="page-error"
      data-error-code={error.httpCode}
    >
      <h1 className="display-4 fw-bold text-body-secondary">
        {error.httpCode}
      </h1>
      <p className="lead mb-1">{error.title}</p>
      <p className="text-body-secondary">{error.message}</p>
      <HilosLink
        to="/"
        className="btn btn-primary mt-3"
        data-id="page-error-home"
      >
        Back to home
      </HilosLink>
    </div>
  )
}
