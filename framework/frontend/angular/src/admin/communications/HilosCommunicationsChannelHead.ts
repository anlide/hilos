import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosChannelSummary } from '@hilos/core/admin/communications'
import { HilosHideable } from '@hilos/angular/base'

/** One presentational part of the channel entity block. */
@Component({
  selector: 'hilos-communications-channel-head',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosHideable],
  template: `
    @if (part() === 'visual') {
      <span
        class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="channel-head-icon"
      >
        <i class="bi bi-envelope-paper"></i>
      </span>
    }
    @if (part() === 'badges') {
      @if (summary(); as channel) {
        <span data-id="channel-head-enabled">
          <hilos-hideable [value]="channel.enabled">
            <ng-template let-enabled>
              <span
                class="badge"
                [class.text-bg-success]="enabled"
                [class.text-bg-secondary]="!enabled"
              >
                {{ enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </ng-template>
          </hilos-hideable>
        </span>
        <span
          class="badge"
          [class.text-bg-success]="channel.configured"
          [class.text-bg-warning]="!channel.configured"
          [attr.title]="channel.missingFields + ' field(s) not set'"
          data-id="channel-head-configured"
        >
          {{
            channel.configured
              ? 'Configured'
              : channel.missingFields + ' missing'
          }}
        </span>
      }
    }
    @if (part() === 'description') {
      @if (summary(); as channel) {
        <p class="text-body-secondary mb-0" data-id="channel-head-description">
          Channel <code>{{ channel.channel }}</code> · driver
          <code>{{ channel.driver ?? '—' }}</code>
        </p>
      }
    }
  `,
})
export class HilosCommunicationsChannelHead {
  /** The permitted summary from the page response, when it is available. */
  readonly summary = input<HilosChannelSummary | null>(null)

  /** Which single part of the shared entity block to draw. */
  readonly part = input.required<'visual' | 'badges' | 'description'>()
}
