// HilosToastHost — the framework toast stack: transient notices in a fixed
// bottom-end corner, newest at the bottom, each self-expiring on the store's
// timer. It renders the shared core store (hilosToasts), so anything in the SDK
// or in a project can report an outcome without threading a store through inputs —
// the shell mounts this once (HilosLayout) and every page is covered.
//
// Markup is the Bootstrap toast component driven declaratively — `.toast.show`
// rendered by the store rather than Bootstrap's JS Toast (the SDK ships
// Bootstrap's CSS, not its JS; HilosModal does the same). Colored variants use
// the documented header-less form: a body plus a close button on a `text-bg-*`
// surface. Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'
import { hilosToasts, subscribeSignal } from '@hilos/core'
import type {
  HilosToast,
  HilosToastSeverity,
  HilosToastStore,
} from '@hilos/core'

// Each severity maps to a Bootstrap color surface; the close button flips to its
// light variant on the dark surfaces so it stays visible.
const SURFACE: Record<HilosToastSeverity, string> = {
  error: 'text-bg-danger',
  success: 'text-bg-success',
  info: 'text-bg-secondary',
}

/** The application's transient notice stack. */
@Component({
  selector: 'hilos-toast-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="toast-container position-fixed bottom-0 end-0 p-3"
      data-id="hilos-toasts"
    >
      @for (toast of toasts(); track toast.id) {
        <div
          class="toast show align-items-center border-0 d-flex"
          [class]="surface(toast.severity)"
          role="alert"
          aria-live="assertive"
          aria-atomic="true"
          [attr.data-id]="'hilos-toast-' + toast.severity"
        >
          <div class="toast-body">{{ toast.message }}</div>
          <button
            type="button"
            class="btn-close btn-close-white me-2 m-auto"
            aria-label="Close"
            data-id="hilos-toast-close"
            (click)="dismiss(toast.id)"
          ></button>
        </div>
      }
    </div>
  `,
})
export class HilosToastHost {
  /** The stack to render; defaults to the application-wide store. */
  readonly store = input<HilosToastStore>(hilosToasts)

  protected readonly toasts = signal<readonly HilosToast[]>([])

  constructor() {
    // Mirror the store's stack, re-subscribing if the input is swapped. Done in an
    // effect rather than a field initializer because an input is not readable until
    // it is bound.
    effect((onCleanup) => {
      const store = this.store()
      this.toasts.set(store.toasts.get())
      onCleanup(
        subscribeSignal(store.toasts, (list) => {
          this.toasts.set(list)
        }),
      )
    })
  }

  /**
   * The Bootstrap surface class for a severity.
   *
   * @param severity The toast's severity.
   */
  protected surface(severity: HilosToastSeverity): string {
    return SURFACE[severity]
  }

  /**
   * Remove one notice early.
   *
   * @param id The toast id.
   */
  protected dismiss(id: number): void {
    this.store().dismiss(id)
  }
}
