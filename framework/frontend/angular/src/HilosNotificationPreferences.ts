// HilosNotificationPreferences — the profile "Notifications" section (HIL-485):
// one switch per globally-enabled channel letting the signed-in user opt in or
// out, plus an always-on note when the project declares any mandatory
// notification type. It renders the framework-owned preference store
// (hilosNotificationPreferences), which the mounting profile surface feeds from
// the profile page data (the section snapshot) and the
// `notification_preferences_changed` signal (live multi-device sync) — see
// notificationPreferences.ts. A toggle is tracked, never optimistic: it marks the
// row pending (a per-row loader on the switch) and fires the
// `profile_notification_channel_set` action; the switch turns only when the
// server fans the changed signal back to every one of the user's tabs, and a send
// that never leaves simply settles the loader and snaps the row back. A channel
// with no address for it is shown disabled with a hint to add one rather than
// hidden, so the user sees the whole channel set. Mandatory types carry no toggle
// — a switch that cannot be turned off is worse than none — only the note. Sparse
// opt-out: no muted row means allowed. Mirrors the Vue section. Bootstrap classes
// only.
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import {
  hilosNotificationPreferences,
  hilosPushSubscription,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
  type HilosPushSubscriptionStore,
} from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'
import { HilosPushDeviceToggle } from './HilosPushDeviceToggle.js'

// Distinct id bases so two sections on one page never share a label `for`.
let preferencesSeq = 0

/**
 * The profile notification-preferences section: a switch per channel plus the
 * mandatory-types note.
 */
@Component({
  selector: 'hilos-notification-preferences',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPushDeviceToggle],
  template: `
    <section
      [attr.aria-labelledby]="baseId + '-heading'"
      data-id="hilos-notification-preferences"
    >
      <h2 [id]="baseId + '-heading'" class="h5">Notifications</h2>
      @if (channels().length === 0) {
        <p
          class="text-body-secondary mb-0"
          data-id="hilos-notification-preferences-empty"
        >
          No notification channels are available.
        </p>
      }
      @for (row of channels(); track row.channel) {
        <!-- A web-push channel ships its VAPID public key as the row's config;
        it renders the per-device toggle rather than the preference switch. -->
        @if (row.config?.vapid_public; as vapidPublicKey) {
          <hilos-push-device-toggle
            [connection]="connection()"
            [channel]="row.channel"
            [label]="row.label"
            [vapidPublicKey]="vapidPublicKey"
            [store]="pushStore()"
          />
        } @else {
          <div
            class="form-check form-switch mb-2"
            [attr.data-id]="'hilos-notification-preference-' + row.channel"
          >
            <input
              [id]="rowId(row.channel)"
              class="form-check-input"
              type="checkbox"
              role="switch"
              [checked]="row.allowed"
              [disabled]="!row.hasAddress || isPending(row.channel)"
              [attr.aria-describedby]="
                row.hasAddress ? null : rowId(row.channel) + '-hint'
              "
              [attr.aria-busy]="isPending(row.channel)"
              [attr.data-id]="
                'hilos-notification-preference-toggle-' + row.channel
              "
              (change)="toggle(row, $event)"
            />
            <label class="form-check-label" [attr.for]="rowId(row.channel)">
              {{ row.label }}
            </label>
            @if (isPending(row.channel)) {
              <span
                class="spinner-border spinner-border-sm ms-2 align-middle"
                role="status"
                [attr.data-id]="
                  'hilos-notification-preference-pending-' + row.channel
                "
                ><span class="visually-hidden">Saving…</span></span
              >
            }
            @if (!row.hasAddress) {
              <div
                [id]="rowId(row.channel) + '-hint'"
                class="form-text mt-0"
                [attr.data-id]="
                  'hilos-notification-preference-hint-' + row.channel
                "
              >
                Add an address in your profile to enable this channel.
              </div>
            }
          </div>
        }
      }
      @if (mandatoryNote()) {
        <p
          class="text-body-secondary small mb-0 mt-2"
          data-id="hilos-notification-preferences-mandatory"
        >
          Security messages are always delivered.
        </p>
      }
    </section>
  `,
})
export class HilosNotificationPreferences {
  /** The connection the toggle action is sent over. */
  readonly connection = input.required<HilosConnection>()
  /**
   * The store the section renders; defaults to the shared framework store. Read
   * once at construction (like the bell captures its store), so an override is
   * for a test or second window, not a runtime rebind.
   */
  readonly store = input<HilosNotificationPreferencesStore>(
    hilosNotificationPreferences,
  )
  /** The per-device push store the push channel's toggle renders. */
  readonly pushStore = input<HilosPushSubscriptionStore>(hilosPushSubscription)

  private readonly boundStore = this.store()
  protected readonly channels = hilosSignal(this.boundStore.channels)
  protected readonly mandatoryNote = hilosSignal(this.boundStore.mandatoryNote)
  protected readonly pending = hilosSignal(this.boundStore.pending)
  protected readonly baseId = `hilos-notification-preferences-${preferencesSeq++}`

  protected rowId(channel: string): string {
    return `${this.baseId}-${channel}`
  }

  protected isPending(channel: string): boolean {
    return this.pending().has(channel)
  }

  // Toggling is tracked, not optimistic: mark the row pending, send the action,
  // and let the changed signal settle it. A send that never leaves (no live
  // connection) settles the loader here so the row snaps back to its last
  // confirmed state instead of hanging spinning.
  protected toggle(row: HilosNotificationChannelState, event: Event): void {
    const enabled = (event.target as HTMLInputElement).checked
    this.boundStore.markPending(row.channel)
    const sent = this.connection().sendAction(NOTIFICATION_ACTION_CHANNEL_SET, {
      channel: row.channel,
      enabled,
    })
    if (!sent) {
      this.boundStore.clearPending(row.channel)
    }
  }
}
