// ErrorPage — the SDK's full-page subscription-error surface. HilosView renders
// it in place of the routed page component whenever the navigator carries a page
// subscription error (subscription_page_error), displaying its server title and
// message. The connection stays live, so "Back to home" navigates
// without a refresh. Styling is Bootstrap classes only (styling-rules.md).
//
// A refusal that carries the optional transitionMessage — the server's own line
// for a page whose resource was deleted while it stood open (HIL-1603) — shows it
// under the description, as plain text. The key's presence in the current error
// is the only thing that draws it: no status, code or phrase is checked, and the
// next error without the key takes the line away. An opted-in page shell draws
// this surface in place of its content and returns focus to its Back to home
// link, which it reads through homeLink().
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  input,
  viewChild,
} from '@angular/core'
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
      <!-- Two branches rather than a bound class: one would push the
      description's words onto a line of their own, and the template's line
      breaks would reach the text the surface holds. -->
      @if (error().transitionMessage !== undefined) {
        <p class="text-body-secondary mb-1">{{ error().message }}</p>
        <p
          class="small text-body-secondary"
          data-id="page-error-transition-message"
        >
          {{ error().transitionMessage }}
        </p>
      } @else {
        <p class="text-body-secondary">{{ error().message }}</p>
      }
      <a
        #home
        hilosLink="/"
        class="btn btn-primary mt-3"
        data-id="page-error-home"
      >
        Back to home
      </a>
    </div>
  `,
})
export class ErrorPage {
  /** The page error the navigator carries. */
  readonly error = input.required<PageError>()

  private readonly home = viewChild.required<ElementRef<HTMLElement>>('home')

  /**
   * The surface's Back to home anchor.
   *
   * @returns The anchor element, once the surface is rendered.
   */
  homeLink(): HTMLElement {
    return this.home().nativeElement
  }
}
