// HilosMaintenance — the maintenance surface of the app shell. HilosLayout
// renders it over the routed content for as long as the connection reports
// protected mode, on every url, so a visitor who arrives mid-maintenance sees
// planned work rather than a generic outage. The words come from the backend
// registry and travel the wire (the Hilos i18n model); this component holds
// layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
// known but no sentence arrived with it. It is a state, not a page: no links,
// no retry button — the mode lifts on its own and the core reloads the document.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import { PROTECTED_MODE_FALLBACK_COPY } from '@hilos/core'
import type { ProtectedModeStatus } from '@hilos/core'

/** The full-screen maintenance state of the shell. */
@Component({
  selector: 'hilos-maintenance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center"
      data-id="maintenance"
      [attr.data-operation]="status().operation ?? null"
      role="status"
      aria-live="polite"
    >
      <i
        class="bi bi-tools display-4 text-body-secondary mb-3"
        aria-hidden="true"
      ></i>
      <h1 class="h3 mb-2" data-id="maintenance-title">{{ title() }}</h1>
      <p class="text-body-secondary mb-0" data-id="maintenance-message">
        {{ message() }}
      </p>
    </div>
  `,
})
export class HilosMaintenance {
  /** The freeze to render, as the connection reports it. */
  readonly status = input.required<ProtectedModeStatus>()

  protected readonly title = computed(
    () => this.status().title ?? PROTECTED_MODE_FALLBACK_COPY.title,
  )
  protected readonly message = computed(
    () => this.status().message ?? PROTECTED_MODE_FALLBACK_COPY.message,
  )
}
