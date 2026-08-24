// The OAuth waiting modal (HIL-633), the Angular peer of
// framework/frontend/vue/src/auth/HilosOAuthWaitModal.vue. What a person sees in
// the page they stayed on while a provider window is open in front of it. The shell
// mounts it once, beside the toast host, so no project wires it and no page has to
// know a trip may be running over it.
//
// It shows for a LINK and not for a sign-in: a link is started from a live page that
// must be held still while the trip runs, whereas a sign-in is already parked on the
// auth surface's waiting screen, and a modal over that would be the same wait said
// twice. Cancel is offered only while the person is at the provider — once the code
// is being exchanged the window has closed itself and there is nothing left to take
// back. Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  signal,
} from '@angular/core'
import {
  cancelOAuthTrip,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
} from '@hilos/core'

import { HilosModal } from '../HilosModal.js'
import { hilosSignal } from '../hilosSignal.js'

/** The wait an OAuth link puts over the page that started it. */
@Component({
  selector: 'hilos-oauth-wait-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModal],
  template: `
    <hilos-modal
      [(open)]="modalOpen"
      [title]="title()"
      [closeOnEsc]="cancelable()"
      [closeOnBackdrop]="cancelable()"
      (cancel)="onCloseRequest()"
    >
      <div class="text-center py-2" data-id="auth-oauth-wait">
        <div class="spinner-border text-primary mb-3" role="status">
          <span class="visually-hidden">Waiting</span>
        </div>
        <p class="mb-0 small text-body-secondary">{{ message() }}</p>
      </div>

      <ng-template #modalActions>
        @if (cancelable()) {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="auth-oauth-wait-cancel"
            (click)="cancel()"
          >
            Cancel
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosOAuthWaitModal {
  protected readonly trip = hilosSignal(oauthTrip)

  /**
   * Whether the dialog stands open, mirrored from the trip.
   *
   * A signal the dialog can WRITE and not a computed off the trip, because
   * `HilosModal.open` is a `model()`: its close button sets that model false, and
   * a one-way binding would leave the dialog hidden with the trip still running —
   * the parent's expression never changed, so Angular would never re-apply it.
   * Two-way, {@link onCloseRequest} can put it back.
   */
  protected readonly modalOpen = signal(false)

  // Only the provider leg can be taken back; the exchange leg is our own round trip
  // and closing over it would leave the account half-linked in the person's head.
  protected readonly cancelable = computed(
    () => this.trip()?.phase === 'authorizing',
  )

  protected readonly title = computed(() => {
    const trip = this.trip()

    return trip === null ? '' : oauthTripTitle(trip)
  })

  protected readonly message = computed(() => {
    const trip = this.trip()

    return trip === null ? '' : oauthTripMessage(trip)
  })

  constructor() {
    effect(() => {
      this.modalOpen.set(this.trip()?.intent === 'link')
    })
  }

  /** End the trip the way a person ends it. */
  protected cancel(): void {
    cancelOAuthTrip()
  }

  /**
   * Answer the dialog's own close attempts (Escape, backdrop, the header's ×).
   * The trip owns whether this is open, so a close that cannot be honored is put
   * straight back — ending the trip is what closes this, not the other way round.
   */
  protected onCloseRequest(): void {
    if (this.cancelable()) {
      cancelOAuthTrip()

      return
    }
    this.modalOpen.set(true)
  }
}
