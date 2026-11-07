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
// and drops the row. With `channelRow` the same switch renders as a
// delivery-channel row — a decorative icon, the label and this browser's status
// on the left, the switch and its loader on the right — still one input and one
// action flow. Mirrors the Vue toggle. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_NOTIFICATION_PREFERENCES_COPY as COPY,
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushPermission,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'

import { mirrorHilosSignal } from './hilosSignal.js'

// Distinct id bases so two toggles on one page never share a label `for`.
let pushToggleSeq = 0

/** The per-device web-push switch for the profile notification section. */
@Component({
  selector: 'hilos-push-device-toggle',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      [class]="
        channelRow()
          ? 'd-flex align-items-center gap-3 py-3 border-bottom'
          : 'mb-2'
      "
      [attr.data-id]="'hilos-notification-preference-' + channel()"
    >
      @if (channelRow()) {
        <i
          class="bi {{ rowIcon() }} fs-5 text-body-secondary flex-shrink-0"
          aria-hidden="true"
          [attr.data-id]="'hilos-notification-preference-icon-' + channel()"
        ></i>
        <div class="flex-grow-1 overflow-hidden">
          <label class="fw-semibold small" [attr.for]="baseId">{{
            label()
          }}</label>
          <div
            class="hilos-stack small text-body-secondary text-break"
            [attr.data-id]="
              'hilos-notification-preference-subtitle-' + channel()
            "
          >
            <span class="invisible" aria-hidden="true" inert>{{
              copy.pushSubscribed
            }}</span>
            <span class="invisible" aria-hidden="true" inert>{{
              copy.pushNotSubscribed
            }}</span>
            <span class="invisible" aria-hidden="true" inert>{{
              copy.pushUnsupported
            }}</span>
            <span class="invisible" aria-hidden="true" inert>{{
              copy.pushBlocked
            }}</span>
            @if (hint()) {
              <div
                [id]="baseId + '-hint'"
                [attr.data-id]="
                  'hilos-notification-preference-hint-' + channel()
                "
              >
                {{ hint() }}
              </div>
            } @else {
              <div [id]="baseId + '-subtitle'">
                {{
                  subscribed() ? copy.pushSubscribed : copy.pushNotSubscribed
                }}
              </div>
            }
          </div>
        </div>
      }
      <div [class]="channelRow() ? 'flex-shrink-0 text-nowrap' : null">
        <div class="form-check form-switch mb-0">
          <input
            [id]="baseId"
            class="form-check-input"
            type="checkbox"
            role="switch"
            [checked]="subscribed()"
            [disabled]="hint() !== '' || busy()"
            [attr.aria-describedby]="describedBy()"
            [attr.aria-busy]="busy()"
            [attr.data-id]="'hilos-notification-preference-toggle-' + channel()"
            (change)="toggle($event)"
          />
          @if (!channelRow()) {
            <label class="form-check-label" [attr.for]="baseId">{{
              label()
            }}</label>
          }
          <span
            [class]="
              channelRow()
                ? 'hilos-stack d-inline-grid ms-2 align-middle'
                : null
            "
          >
            @if (channelRow()) {
              <span
                class="spinner-border spinner-border-sm invisible"
                aria-hidden="true"
                inert
              ></span>
            }
            @if (busy()) {
              <span
                class="spinner-border spinner-border-sm align-middle"
                [class.ms-2]="!channelRow()"
                role="status"
                [attr.data-id]="
                  'hilos-notification-preference-pending-' + channel()
                "
                ><span class="visually-hidden">Saving…</span></span
              >
            }
          </span>
          @if (!channelRow()) {
            <div class="hilos-stack form-text mt-0">
              <span class="invisible" aria-hidden="true" inert>{{
                copy.pushBlocked
              }}</span>
              <span class="invisible" aria-hidden="true" inert>{{
                copy.pushUnsupported
              }}</span>
              @if (hint()) {
                <div
                  [id]="baseId + '-hint'"
                  [attr.data-id]="
                    'hilos-notification-preference-hint-' + channel()
                  "
                >
                  {{ hint() }}
                </div>
              }
            </div>
          }
        </div>
      </div>
    </div>
  `,
})
export class HilosPushDeviceToggle {
  /** The connection the subscribe/unsubscribe action is sent over. */
  readonly connection = input.required<HilosConnection>()
  /** The push channel's registry name, used for stable element ids. */
  readonly channel = input.required<string>()
  /** The push channel's human label. */
  readonly label = input.required<string>()
  /** The channel's VAPID public key (base64url) the browser subscribes with. */
  readonly vapidPublicKey = input.required<string>()
  /** The store the toggle renders; defaults to the shared framework store. */
  readonly store = input<HilosPushSubscriptionStore>(hilosPushSubscription)
  /** Render a delivery-channel row instead of the compact device switch. */
  readonly channelRow = input<boolean>(false)
  /** Decorative Bootstrap Icons token for the delivery-channel row. */
  readonly icon = input<string | undefined>(undefined)

  protected readonly copy = COPY
  protected readonly supported = signal(this.boundStore.supported.get())
  protected readonly permission = signal<HilosPushPermission>(
    this.boundStore.permission.get(),
  )
  protected readonly subscribed = signal(this.boundStore.subscribed.get())
  protected readonly busy = signal(this.boundStore.busy.get())
  protected readonly baseId = `hilos-push-device-toggle-${pushToggleSeq++}`
  protected readonly rowIcon = computed(() => this.icon() ?? 'bi-broadcast')

  // `denied` is terminal — the browser will not re-prompt — and an unsupported
  // browser cannot subscribe at all; both disable the switch and show a hint
  // instead of retrying.
  protected readonly hint = computed(() => {
    if (!this.supported()) {
      return COPY.pushUnsupported
    }
    if (this.permission() === 'denied') {
      return COPY.pushBlocked
    }

    return ''
  })

  // The hint when there is one; otherwise the row's status line, and nothing
  // for the compact switch.
  protected readonly describedBy = computed(() => {
    if (this.hint()) {
      return `${this.baseId}-hint`
    }

    return this.channelRow() ? `${this.baseId}-subtitle` : null
  })

  constructor() {
    // Mirror the store the input holds now, and follow a new one: the fields
    // above start from the default store, and this puts the passed store's
    // values in before the first render and on every change after it.
    effect((onCleanup) => {
      const store = this.boundStore
      const unsubscribes = [
        mirrorHilosSignal(store.supported, this.supported),
        mirrorHilosSignal(store.permission, this.permission),
        mirrorHilosSignal(store.subscribed, this.subscribed),
        mirrorHilosSignal(store.busy, this.busy),
      ]
      const releasePermission = store.observePermission?.()
      if (releasePermission === undefined) {
        void store.refresh()
      }
      onCleanup(() => {
        releasePermission?.()
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
      })
    })
  }

  /** The store the input holds now — the one the view reads and calls. */
  private get boundStore(): HilosPushSubscriptionStore {
    return this.store()
  }

  // Per-device: subscribe or unsubscribe THIS browser rather than setting a
  // shared preference. The store owns the browser dance and the server round-trip.
  protected toggle(event: Event): void {
    const target = event.target as HTMLInputElement
    const enabled = target.checked
    // The channel row keeps the store's position while the browser operation runs.
    if (this.channelRow()) {
      target.checked = this.subscribed()
    }
    if (enabled) {
      void this.boundStore.enable(this.connection(), this.vapidPublicKey())
    } else {
      void this.boundStore.disable(this.connection())
    }
  }
}
