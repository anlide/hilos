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
// hidden, so the user sees the whole channel set; where the mounting page names
// the profile section that adds an address (HIL-1166), the hint links there.
// Each channel is a row as in Vue (HIL-1658): a decorative icon, the name and
// under it the public address or that hint, the switch on the right.
// Mandatory types carry no toggle
// — a switch that cannot be turned off is worse than none — only the note. Sparse
// opt-out: no muted row means allowed. Mirrors the Vue section. Bootstrap classes
// only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_NOTIFICATION_PREFERENCES_COPY,
  hilosNotificationPreferences,
  hilosPushSubscription,
  NOTIFICATION_ACTION_CHANNEL_SET,
  notificationChannelIcon as resolveNotificationChannelIcon,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
  type HilosPageCrumb,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'

import { HilosLink } from './HilosLink.js'
import { mirrorHilosSignal } from './hilosSignal.js'
import { HilosPushDeviceToggle } from './HilosPushDeviceToggle.js'
import { HilosSwitch } from './HilosSwitch.js'

// Distinct id bases so two sections on one page never share a label `for`.
let preferencesSeq = 0

/**
 * The profile notification-preferences section: a switch per channel plus the
 * mandatory-types note.
 */
@Component({
  selector: 'hilos-notification-preferences',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink, HilosPushDeviceToggle, HilosSwitch],
  template: `
    <section
      [attr.aria-labelledby]="baseId + '-heading'"
      data-id="hilos-notification-preferences"
    >
      <h2
        [id]="baseId + '-heading'"
        class="h6 text-uppercase text-body-secondary mb-2"
      >
        Delivery channels
      </h2>
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
        it renders the per-device toggle, drawn as this same kind of row, rather
        than the preference switch. -->
        @if (row.config?.['vapid_public']; as vapidPublicKey) {
          <hilos-push-device-toggle
            [connection]="connection()"
            [channel]="row.channel"
            [label]="row.label"
            [vapidPublicKey]="vapidPublicKey"
            [store]="pushStore()"
            [channelRow]="true"
            [icon]="notificationChannelIcon(row.channel)"
          />
        } @else {
          <div
            class="d-flex align-items-center gap-3 py-3 border-bottom"
            [attr.data-id]="'hilos-notification-preference-' + row.channel"
          >
            <i
              class="bi fs-5 text-body-secondary flex-shrink-0"
              [class]="notificationChannelIcon(row.channel)"
              aria-hidden="true"
              [attr.data-id]="
                'hilos-notification-preference-icon-' + row.channel
              "
            ></i>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold small">{{ row.label }}</div>
              <div
                class="hilos-stack small text-body-secondary text-break"
                [attr.data-id]="
                  'hilos-notification-preference-subtitle-' + row.channel
                "
              >
                <!-- The no-address hint, hidden, holds the line's room whether
                the row shows its address or the hint. -->
                <span class="invisible" aria-hidden="true" inert>
                  @if (addressLink(); as link) {
                    {{ copy.noAddressBefore }} {{ link.label }}
                    {{ copy.noAddressAfter }}
                  } @else {
                    {{ copy.noAddress }}
                  }
                </span>
                @if (row.hasAddress) {
                  <div [id]="rowId(row.channel) + '-subtitle'">
                    {{ row.address }}
                  </div>
                } @else {
                  <div
                    [id]="rowId(row.channel) + '-hint'"
                    [attr.data-id]="
                      'hilos-notification-preference-hint-' + row.channel
                    "
                  >
                    @if (addressLink(); as link) {
                      {{ copy.noAddressBefore }}
                      <a
                        [hilosLink]="link.to"
                        [attr.data-id]="
                          'hilos-notification-preference-address-' + row.channel
                        "
                        >{{ link.label }}</a
                      >
                      {{ copy.noAddressAfter }}
                    } @else {
                      {{ copy.noAddress }}
                    }
                  </div>
                }
              </div>
            </div>
            <div class="hilos-stack flex-shrink-0 text-nowrap">
              <!-- The switch with its loader, hidden, holds the room the
              loader takes while a toggle is in flight. -->
              <div
                class="form-check form-switch mb-0 invisible"
                aria-hidden="true"
                inert
              >
                <span class="form-check-input"></span>
                <span
                  class="spinner-border spinner-border-sm ms-2 align-middle"
                ></span>
              </div>
              <hilos-switch
                class="mb-0"
                [checked]="row.allowed"
                [busy]="isPending(row.channel)"
                [disabled]="!row.hasAddress"
                [aria-label]="row.label"
                [aria-describedby]="
                  rowId(row.channel) + (row.hasAddress ? '-subtitle' : '-hint')
                "
                [dataId]="'hilos-notification-preference-toggle-' + row.channel"
                (toggle)="toggle(row, $event)"
              />
            </div>
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
  /** The store the section renders; defaults to the shared framework store. */
  readonly store = input<HilosNotificationPreferencesStore>(
    hilosNotificationPreferences,
  )
  /** The per-device push store the push channel's toggle renders. */
  readonly pushStore = input<HilosPushSubscriptionStore>(hilosPushSubscription)
  /** The profile section where an address is added, or null when the project has none. */
  readonly addressSection = input<HilosPageCrumb | null>(null)
  /** The path of that section; without it the hint stays plain text. */
  readonly addressTo = input<string | undefined>(undefined)
  protected readonly copy = HILOS_NOTIFICATION_PREFERENCES_COPY
  protected readonly notificationChannelIcon = resolveNotificationChannelIcon

  protected readonly channels = signal<
    readonly HilosNotificationChannelState[]
  >(this.boundStore.channels.get())
  protected readonly mandatoryNote = signal(this.boundStore.mandatoryNote.get())
  protected readonly pending = signal<ReadonlySet<string>>(
    this.boundStore.pending.get(),
  )
  protected readonly baseId = `hilos-notification-preferences-${preferencesSeq++}`

  // The no-address hint links into the address section only when the page
  // names both the section and its path; otherwise it stays plain text.
  protected readonly addressLink = computed(() => {
    const section = this.addressSection()
    const to = this.addressTo()

    return section && to ? { label: section.label, to } : null
  })

  constructor() {
    // Mirror the store the input holds now, and follow a new one: the fields
    // above start from the default store, and this puts the passed store's
    // values in before the first render and on every change after it.
    effect((onCleanup) => {
      const store = this.boundStore
      const unsubscribes = [
        mirrorHilosSignal(store.channels, this.channels),
        mirrorHilosSignal(store.mandatoryNote, this.mandatoryNote),
        mirrorHilosSignal(store.pending, this.pending),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
      })
    })
  }

  /** The store the input holds now — the one the view reads and calls. */
  private get boundStore(): HilosNotificationPreferencesStore {
    return this.store()
  }

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
  protected toggle(row: HilosNotificationChannelState, enabled: boolean): void {
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
