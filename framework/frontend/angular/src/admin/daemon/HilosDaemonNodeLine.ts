import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import {
  daemonNodeDiagramPath,
  formatDaemonNodeState,
  type HilosDaemonNodeHeading,
} from '@hilos/core'

import { HilosLink } from '../../HilosLink.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** The current node address and the backend's heading verdict. */
@Component({
  selector: 'hilos-daemon-node-line',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <div
      class="d-flex flex-wrap align-items-center gap-2 mb-3 small"
      data-id="hilos-daemon-node-line"
    >
      <span class="text-body-secondary">Node</span>
      <span
        class="badge bg-body-tertiary text-body border font-monospace"
        data-id="hilos-daemon-node-id"
        >{{ nodeId() }}</span
      >
      @if (heading(); as node) {
        @if (node.clustered) {
          @if (node.state; as state) {
            <span
              class="badge border"
              [class]="stateClass(state)"
              [attr.data-state]="state"
              data-id="hilos-daemon-node-state"
            >
              <i
                class="bi me-1"
                [class]="stateIcon(state)"
                aria-hidden="true"
              ></i
              >{{ formatDaemonNodeState(state) }}
            </span>
          }
          @if (diagramPath(); as path) {
            <a
              [hilosLink]="path"
              class="ms-2"
              data-id="hilos-daemon-node-diagram"
              >Change on the diagram</a
            >
          }
        } @else {
          <span
            class="badge bg-body-tertiary text-body border"
            data-id="hilos-daemon-node-solo"
            ><i class="bi bi-hdd me-1" aria-hidden="true"></i>Single
            installation</span
          >
        }
      }
    </div>
  `,
})
export class HilosDaemonNodeLine {
  /** The node named by the current address. */
  readonly nodeId = input.required<string>()
  /** The heading from the page's subscription answer. */
  readonly heading = input.required<HilosDaemonNodeHeading | null>()
  private readonly router = inject(HILOS_ROUTER)
  protected readonly diagramPath = computed(() =>
    this.heading()?.clustered
      ? daemonNodeDiagramPath(this.router.resolvePath)
      : undefined,
  )
  protected readonly formatDaemonNodeState = formatDaemonNodeState
  protected stateClass(state: string): string {
    switch (state) {
      case 'leader':
        return 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
      case 'standby':
        return 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'
      case 'data':
        return 'bg-info-subtle text-info-emphasis border-info-subtle'
      case 'silent':
        return 'text-bg-danger'
      default:
        return 'bg-body-tertiary text-body'
    }
  }
  protected stateIcon(state: string): string {
    switch (state) {
      case 'leader':
        return 'bi-star-fill'
      case 'standby':
        return 'bi-pause-circle'
      case 'data':
        return 'bi-hdd'
      case 'silent':
        return 'bi-plug'
      default:
        return ''
    }
  }
}
