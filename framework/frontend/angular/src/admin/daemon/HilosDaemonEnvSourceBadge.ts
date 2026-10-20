import { NgClass } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import {
  daemonEnvSourceBadge,
  type HilosDaemonEnvSource,
} from '@hilos/core/admin/daemon'

/** Angular view of the Daemon EnvSourceBadge. */
@Component({
  selector: 'hilos-daemon-env-source-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass],
  template: `@let badge = this.badge();

    <span
      class="badge rounded-pill flex-shrink-0"
      [ngClass]="[
        'bg-' + badge.tone + '-subtle',
        'text-' + badge.tone + '-emphasis',
        'border',
        'border-' + badge.tone + '-subtle',
      ]"
      [title]="badge.hint"
    >
      {{ badge.label }}
    </span> `,
})
export class HilosDaemonEnvSourceBadge {
  readonly source = input.required<HilosDaemonEnvSource>()
  protected readonly badge = computed(() => daemonEnvSourceBadge(this.source()))
}
