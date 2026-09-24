// HilosCookiesRefused — the card a browser that refuses cookies sees in place of
// sign-in (HIL-1074), the peer of
// framework/frontend/vue/src/auth/HilosCookiesRefused.vue. Every sign-in rotates
// the session token through a cookie, so no method can work in such a browser,
// and a live form would only let the person sign in for one second. The card
// says why and what to do; it has no buttons of its own — the frame it stands in
// closes as it always does.
// Internal to the two auth screens that draw it (HilosAuthSurface and
// HilosMagicLinkPage), not exported from the package. The words are the core's
// COOKIES_REFUSED_COPY, one set for the three view packages.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import { COOKIES_REFUSED_COPY } from '@hilos/core'

@Component({
  selector: 'hilos-cookies-refused',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="text-center" data-id="cookies-refused">
      @if (linkKept()) {
        <p class="small mb-3" data-id="cookies-refused-link-kept">
          {{ copy.linkKept }}
        </p>
      }
      <span
        class="bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-3 lh-1 mb-3"
      >
        <i class="bi bi-cookie" aria-hidden="true"></i>
      </span>
      <h2
        [attr.id]="headingId() ?? null"
        class="h5 mb-2"
        data-id="cookies-refused-heading"
      >
        {{ copy.title }}
      </h2>
      <p class="small text-body-secondary mb-2">{{ copy.message }}</p>
      <p class="small text-body-secondary mb-0">{{ copy.remedy }}</p>
    </div>
  `,
})
export class HilosCookiesRefused {
  /**
   * The id the heading carries, so the frame around the card is named by it;
   * none — no id at all.
   */
  readonly headingId = input<string | undefined>(undefined)

  /** Head the card with the line that the sign-in link was not spent. */
  readonly linkKept = input(false)

  // A module constant is invisible to an Angular template, so the words reach
  // the markup through a field.
  protected readonly copy = COOKIES_REFUSED_COPY
}
