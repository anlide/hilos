// HilosCommunicationsChannelPage — the channel Overview: permitted summary,
// enablement and test actions under the shared entity head. Configuration owns
// the fields table and its edit/reset sessions.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  CHANNEL_ENABLED_FIELD,
  createHilosChannelSummary,
  createHilosCommunicationsActions,
} from '@hilos/core/admin/communications'
import type {
  HilosChannelSummary,
  HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import {
  HilosPages,
  bindPageInstanceName,
  computedSignal,
} from '@hilos/core/base'

import {
  HilosAdminPage,
  HILOS_ROUTER,
  HilosHideable,
  HilosSwitch,
  LoadingButton,
  createHilosTrackedAction,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosCommunicationsChannelHead } from './HilosCommunicationsChannelHead.js'

/** The framework channel Overview: aggregate counts and channel controls. */
@Component({
  selector: 'hilos-communications-channel-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosCommunicationsChannelHead,
    HilosHideable,
    HilosSwitch,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="entity" tabsLabel="Channel pages">
      <ng-template #headVisual>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="visual"
        />
      </ng-template>
      <ng-template #headBadges>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="badges"
        />
      </ng-template>
      <ng-template #headDescription>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="description"
        />
      </ng-template>

      @if (summary(); as channel) {
        <div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
          <div class="col" data-id="channel-summary-configuration">
            <div class="border rounded-3 p-3 h-100">
              <h2 class="small text-body-secondary mb-1">Configuration</h2>
              <div class="fs-4 fw-semibold lh-sm my-1">
                {{
                  channel.configured
                    ? 'Configured'
                    : channel.missingFields + ' missing'
                }}
              </div>
              <div class="small text-body-secondary">
                {{
                  channel.configured
                    ? 'every field has a value'
                    : 'fields hold a bare default'
                }}
              </div>
            </div>
          </div>
          <div class="col" data-id="channel-summary-fields">
            <div class="border rounded-3 p-3 h-100">
              <h2 class="small text-body-secondary mb-1">Fields</h2>
              <div class="fs-4 fw-semibold lh-sm my-1">
                {{ channel.fieldCount }}
              </div>
              <div class="small text-body-secondary">
                {{
                  channel.secretFieldCount
                    ? channel.secretFieldCount + ' secret, set in env only'
                    : 'no secrets'
                }}
              </div>
            </div>
          </div>
          <div class="col" data-id="channel-summary-overridden">
            <div class="border rounded-3 p-3 h-100">
              <h2 class="small text-body-secondary mb-1">Overridden here</h2>
              <div class="fs-4 fw-semibold lh-sm my-1">
                {{ channel.overriddenFieldCount }}
              </div>
              <div class="small text-body-secondary">
                {{
                  channel.overriddenFieldCount
                    ? 'reset returns them to env or the default'
                    : 'every value comes from env or the default'
                }}
              </div>
            </div>
          </div>
        </div>

        <section
          class="border rounded-3 mb-4"
          data-id="channel-controls"
          aria-labelledby="hilos-channel-controls-title"
        >
          <h2
            class="h6 px-3 py-2 mb-0 border-bottom bg-body-tertiary rounded-top-3"
            id="hilos-channel-controls-title"
          >
            Channel
          </h2>
          <div
            class="d-flex align-items-center justify-content-between gap-3 p-3 border-bottom"
          >
            <div>
              <h3 class="h6 mb-1">Enabled</h3>
              <div class="small text-body-secondary">
                When off, no new deliveries go out through it; queued ones still
                do.
              </div>
            </div>
            <hilos-hideable [value]="channel.enabled">
              <ng-template let-enabled>
                <hilos-switch
                  [checked]="enabled"
                  [busy]="toggle.busy()"
                  [aria-label]="'Enable ' + channel.label"
                  dataId="hilos-channel-enabled"
                  (toggle)="onToggle($event)"
                />
              </ng-template>
            </hilos-hideable>
          </div>
          <div
            class="d-flex align-items-center justify-content-between gap-3 p-3"
          >
            <div>
              <h3 class="h6 mb-1">Test notification</h3>
              <div class="small text-body-secondary">
                One notification to you, through this channel only.
              </div>
            </div>
            <button
              hilosLoadingButton
              class="btn-outline-primary btn-sm"
              [loading]="test.busy()"
              data-id="hilos-channel-test"
              (click)="sendTest()"
            >
              Send test notification
            </button>
          </div>
        </section>
      }
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsChannelPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosCommunicationsContext>()

  protected readonly page = HilosPages.COMMUNICATIONS_CHANNEL
  protected readonly summary = signal<HilosChannelSummary | null>(null)
  private readonly router = inject(HILOS_ROUTER)
  private readonly channelSignal = computedSignal(
    () =>
      (this.router.currentRoute.get().params['channelId'] as
        string | undefined) ?? '',
  )
  private readonly actions = computed(() =>
    createHilosCommunicationsActions(this.context()),
  )
  protected readonly toggle = createHilosTrackedAction()
  protected readonly test = createHilosTrackedAction()

  constructor() {
    // Read only the current channel page's server summary; the same core signal
    // supplies its live name to the shared head and is released with the view.
    effect((onCleanup) => {
      const context = this.context()
      const channelSummary = createHilosChannelSummary(context)
      const currentSummary = computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.COMMUNICATIONS_CHANNEL &&
          page !== HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION &&
          page !== HilosPages.COMMUNICATIONS_DELIVERIES
        ) {
          return null
        }

        const summary = channelSummary.get()

        return summary?.channel === this.channelSignal.get() ? summary : null
      })
      const stopSummary = mirrorHilosSignal(currentSummary, this.summary)
      const unbindName = bindPageInstanceName(
        context.scopes,
        HilosPages.COMMUNICATIONS_CHANNEL,
        computedSignal(() => currentSummary.get()?.label),
      )
      onCleanup(() => {
        unbindName()
        stopSummary()
        this.summary.set(null)
      })
    })
  }

  /** Send the server-confirmed channel's enablement change once. */
  protected onToggle(next: boolean): void {
    const channel = this.summary()
    if (channel === null || this.toggle.busy()) {
      return
    }

    void this.toggle.run(
      this.actions().sendChannelSet(
        channel.channel,
        CHANNEL_ENABLED_FIELD,
        next,
      ),
    )
  }

  /** Send one test through the current channel's real delivery path. */
  protected sendTest(): void {
    const channel = this.summary()
    if (channel === null || this.test.busy()) {
      return
    }

    void this.test.run(this.actions().sendChannelTest(channel.channel))
  }
}
