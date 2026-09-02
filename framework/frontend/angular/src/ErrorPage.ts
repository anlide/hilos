// ErrorPage — the SDK's full-page subscription-error surface. HilosView renders
// it in place of the routed page component whenever the navigator carries a page
// subscription error (subscription_page_error), mapping the HTTP status to a
// title and description. The connection stays live, so "Back to home" navigates
// without a refresh. Styling is Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
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

/** The full-page subscription-error surface. */
@Component({
  selector: 'hilos-error-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <div
      class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
      data-id="page-error"
      [attr.data-error-code]="error().httpCode"
    >
      <h1 class="display-4 fw-bold text-body-secondary">
        {{ error().httpCode }}
      </h1>
      <p class="lead mb-1">{{ text().title }}</p>
      <p class="text-body-secondary">{{ text().description }}</p>
      <a hilosLink="/" class="btn btn-primary mt-3" data-id="page-error-home">
        Back to home
      </a>
    </div>
  `,
})
export class ErrorPage {
  /** The page subscription error the navigator carries. */
  readonly error = input.required<PageSubscriptionError>()

  protected readonly text = computed(
    () => ERROR_TEXT[this.error().httpCode] ?? FALLBACK,
  )
}
