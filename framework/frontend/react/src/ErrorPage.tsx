// ErrorPage — the SDK's full-page subscription-error surface. HilosView renders
// it in place of the routed page component whenever the navigator carries a page
// subscription error (subscription_page_error), mapping the HTTP status to a
// title and description. The connection stays live, so "Back to home" navigates
// without a refresh.
import type { PageSubscriptionError } from '@hilos/core'

import { HilosLink } from './HilosLink.js'

/** HTTP status → human-readable title and description for the error surface. */
const ERROR_TEXT: Record<number, { title: string; description: string }> = {
  400: {
    title: 'Bad Request',
    description: 'The request could not be understood.',
  },
  401: { title: 'Unauthorized', description: 'Authentication is required.' },
  // Says that access was refused and nothing about who was refused: this table
  // answers EVERY 403, and the one it used to name - a guest - is the case it is
  // least often shown for. An administrator whose privilege was revoked while the
  // page was open read that they were a guest, which they were not (HIL-779).
  403: {
    title: 'Forbidden',
    description: 'You do not have access to this page.',
  },
  404: {
    title: 'Page Not Found',
    description: 'The page you are looking for does not exist.',
  },
  500: {
    title: 'Internal Server Error',
    description: 'An unexpected error occurred.',
  },
  502: {
    title: 'Bad Gateway',
    description: 'The server received an invalid response.',
  },
  503: {
    title: 'Service Unavailable',
    description: 'The service is temporarily unavailable.',
  },
  504: { title: 'Gateway Timeout', description: 'The gateway timed out.' },
}

const FALLBACK = { title: 'Error', description: 'An error occurred.' }

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
  const text = ERROR_TEXT[error.httpCode] ?? FALLBACK

  return (
    <div
      className="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
      data-id="page-error"
      data-error-code={error.httpCode}
    >
      <h1 className="display-4 fw-bold text-body-secondary">
        {error.httpCode}
      </h1>
      <p className="lead mb-1">{text.title}</p>
      <p className="text-body-secondary">{text.description}</p>
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
