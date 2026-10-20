// ErrorPage — the SDK's full-page subscription-error surface. HilosView renders
// it in place of the routed page component whenever the navigator carries a page
// subscription error (subscription_page_error), displaying its server title and
// message. The connection stays live, so "Back to home" navigates
// without a refresh. Styling is Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { PageError } from '@hilos/core/base'

import { HilosLink } from './HilosLink.js'

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
      <p class="lead mb-1">{{ error().title }}</p>
      <p class="text-body-secondary">{{ error().message }}</p>
      <a hilosLink="/" class="btn btn-primary mt-3" data-id="page-error-home">
        Back to home
      </a>
    </div>
  `,
})
export class ErrorPage {
  /** The page error the navigator carries. */
  readonly error = input.required<PageError>()
}
