// HilosPushDeviceToggle — the profile "Notifications" section's control for the
// web-push channel (HIL-199). Unlike the other channel switches, which set the
// per-user preference (allowed/muted, shared across the user's tabs), web push
// is per-device browser state: this switch subscribes or unsubscribes THIS
// browser. It renders the framework-owned push-subscription store
// (hilosPushSubscription), which reflects the live PushManager rather than a
// server snapshot, so it reads the device on mount. A denied or unsupported
// browser cannot subscribe, so the switch is disabled with a hint rather than
// retrying. Enabling prompts for permission, subscribes with the channel's VAPID
// public key, and registers the subscription server-side; disabling unsubscribes
// and drops the row. Mirrors the Vue toggle. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  type OnInit,
} from '@angular/core'
import {
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushSubscriptionStore,
} from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'

// Distinct id bases so two toggles on one page never share a label `for`.
let pushToggleSeq = 0

/** The per-device web-push switch for the profile notification section. */
@Component({
  selector: 'hilos-push-device-toggle',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="form-check form-switch mb-2"
      [attr.data-id]="'hilos-notification-preference-' + channel()"
    >
      <input
        [id]="baseId"
        class="form-check-input"
        type="checkbox"
        role="switch"
        [checked]="subscribed()"
        [disabled]="hint() !== '' || busy()"
        [attr.aria-describedby]="hint() ? baseId + '-hint' : null"
        [attr.aria-busy]="busy()"
        [attr.data-id]="'hilos-notification-preference-toggle-' + channel()"
        (change)="toggle($event)"
      />
      <label class="form-check-label" [attr.for]="baseId">{{ label() }}</label>
      @if (busy()) {
        <span
          class="spinner-border spinner-border-sm ms-2 align-middle"
          role="status"
          [attr.data-id]="'hilos-notification-preference-pending-' + channel()"
          ><span class="visually-hidden">Saving…</span></span
        >
      }
      @if (hint()) {
        <div
          [id]="baseId + '-hint'"
          class="form-text mt-0"
          [attr.data-id]="'hilos-notification-preference-hint-' + channel()"
        >
          {{ hint() }}
        </div>
      }
    </div>
  `,
})
export class HilosPushDeviceToggle implements OnInit {
  /** The connection the subscribe/unsubscribe action is sent over. */
  readonly connection = input.required<HilosConnection>()
  /** The push channel's registry name, used for stable element ids. */
  readonly channel = input.required<string>()
  /** The push channel's human label. */
  readonly label = input.required<string>()
  /** The channel's VAPID public key (base64url) the browser subscribes with. */
  readonly vapidPublicKey = input.required<string>()
  /**
   * The store the toggle renders; defaults to the shared framework store. Read
   * once at construction, so an override is for a test or second window.
   */
  readonly store = input<HilosPushSubscriptionStore>(hilosPushSubscription)

  private readonly boundStore = this.store()
  protected readonly supported = hilosSignal(this.boundStore.supported)
  protected readonly permission = hilosSignal(this.boundStore.permission)
  protected readonly subscribed = hilosSignal(this.boundStore.subscribed)
  protected readonly busy = hilosSignal(this.boundStore.busy)
  protected readonly baseId = `hilos-push-device-toggle-${pushToggleSeq++}`

  // `denied` is terminal — the browser will not re-prompt — and an unsupported
  // browser cannot subscribe at all; both disable the switch and show a hint
  // instead of retrying.
  protected readonly hint = computed(() => {
    if (!this.supported()) {
      return 'Push notifications are not supported on this device.'
    }
    if (this.permission() === 'denied') {
      return 'Push notifications are blocked in your browser settings.'
    }

    return ''
  })

  /**
   * Read the live browser state (support, permission, subscription) so the switch
   * reflects this device; the store's default signals are safe until then.
   */
  ngOnInit(): void {
    void this.boundStore.refresh()
  }

  // Per-device: subscribe or unsubscribe THIS browser rather than setting a
  // shared preference. The store owns the browser dance and the server round-trip.
  protected toggle(event: Event): void {
    if ((event.target as HTMLInputElement).checked) {
      void this.boundStore.enable(this.connection(), this.vapidPublicKey())
    } else {
      void this.boundStore.disable(this.connection())
    }
  }
}
