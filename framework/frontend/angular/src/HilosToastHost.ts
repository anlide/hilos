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

// Only a failure earns an interrupt; a success or a plain notice waits its turn
// rather than cutting into what the screen-reader user is listening to.
const LIVE_REGION: Record<
  HilosToastSeverity,
  { role: string; ariaLive: 'assertive' | 'polite' }
> = {
  error: { role: 'alert', ariaLive: 'assertive' },
  success: { role: 'status', ariaLive: 'polite' },
  info: { role: 'status', ariaLive: 'polite' },
}

/**
 * Whether a focus event only moved focus inside the stack.
 *
 * The cursor and keyboard focus are two independent holds on the countdown: the
 * host only reports them, the store counts them. Tabbing from one close button to
 * the next is neither an arrival nor a leave, and `relatedTarget` is what tells
 * those apart; without the check the holds would never balance out.
 *
 * @param event The bubbled focusin / focusout.
 */
function movesWithin(event: FocusEvent): boolean {
  const container = event.currentTarget as HTMLElement

  return container.contains(event.relatedTarget as Node | null)
}

/** The application's transient notice stack. */
@Component({
  selector: 'hilos-toast-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="toast-container position-fixed bottom-0 end-0 p-3"
      data-id="hilos-toasts"
      (mouseover)="holdOnCursor()"
      (mouseleave)="releaseCursorHold()"
      (focusin)="holdOnFocus($event)"
      (focusout)="releaseOnBlur($event)"
    >
      @for (toast of toasts(); track toast.id) {
        <div
          class="toast show align-items-center border-0 d-flex"
          [class]="surface(toast.severity)"
          [attr.role]="role(toast.severity)"
          [attr.aria-live]="ariaLive(toast.severity)"
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

  // The host owns at most one hold of each kind and knows whether it is holding it,
  // because a toast that closes under the pointer takes the release event with it:
  // Chrome and WebKit fire no mouseleave and no focusout for an element that leaves
  // the DOM, and they never make it up afterwards. So both holds are given back by
  // hand in dismiss(), and the cursor's one is re-taken from mouseover rather than
  // mouseenter — mouseover also fires for the toast that slides under a cursor
  // standing still, which is exactly what happens to the notice below the one just
  // closed.
  private cursorHeld = false

  private focusHeld = false

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
   * The live-region role for a severity.
   *
   * @param severity The toast's severity.
   */
  protected role(severity: HilosToastSeverity): string {
    return LIVE_REGION[severity].role
  }

  /**
   * How urgently a screen reader announces a severity.
   *
   * @param severity The toast's severity.
   */
  protected ariaLive(severity: HilosToastSeverity): string {
    return LIVE_REGION[severity].ariaLive
  }

  /**
   * Remove one notice early.
   *
   * @param id The toast id.
   */
  protected dismiss(id: number): void {
    this.releaseCursorHold()
    this.releaseFocusHold()
    this.store().dismiss(id)
  }

  /** Freeze the countdown while the cursor rests on the stack. */
  protected holdOnCursor(): void {
    if (!this.cursorHeld) {
      this.cursorHeld = true
      this.store().pause()
    }
  }

  /** Give back the cursor's hold, if this host is the one holding it. */
  protected releaseCursorHold(): void {
    if (this.cursorHeld) {
      this.cursorHeld = false
      this.store().resume()
    }
  }

  /**
   * Freeze the countdown when keyboard focus arrives from outside the stack.
   *
   * @param event The bubbled focusin.
   */
  protected holdOnFocus(event: FocusEvent): void {
    if (!this.focusHeld && !movesWithin(event)) {
      this.focusHeld = true
      this.store().pause()
    }
  }

  /**
   * Release the focus hold once focus actually leaves the stack.
   *
   * @param event The bubbled focusout.
   */
  protected releaseOnBlur(event: FocusEvent): void {
    if (!movesWithin(event)) {
      this.releaseFocusHold()
    }
  }

  /** Give back the focus hold, if this host is the one holding it. */
  private releaseFocusHold(): void {
    if (this.focusHeld) {
      this.focusHeld = false
      this.store().resume()
    }
  }
}
