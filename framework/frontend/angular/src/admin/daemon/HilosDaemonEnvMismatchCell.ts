import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import {
  daemonEnvMismatchCellView,
  daemonEnvMismatchFromExample,
  daemonEnvMismatchStandsOut,
  type HilosDaemonEnvMismatchRow,
} from '@hilos/core'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'
import { HilosDaemonEnvSourceBadge } from './HilosDaemonEnvSourceBadge.js'

/** Angular view of the Daemon EnvMismatchCell. */
@Component({
  selector: 'hilos-daemon-env-mismatch-cell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonEnvValue, HilosDaemonEnvSourceBadge],
  template: `@let row = this.row();
    @let nodeId = this.nodeId();
    @let view = this.view();
    @let standsOut = this.standsOut();
    @let fromExample = this.fromExample();

    <div
      class="d-flex flex-column gap-1"
      [attr.data-id]="
        'hilos-daemon-env-mismatch-cell-' + row.rowKey + '-' + nodeId
      "
      [attr.data-kind]="view.kind"
    >
      @if (view.kind === 'unknown') {
        <span
          class="text-body-secondary"
          title="Silent or no environment report: unknown, not a match"
        >
          ?
        </span>
        <span class="visually-hidden">
          Silent or no environment report: unknown, not a match
        </span>
      } @else if (view.kind === 'undeclared') {
        <span class="text-danger"> Not declared </span>
      } @else if (view.kind === 'none') {
        <span>—</span>
        <hilos-daemon-env-source-badge
          [source]="view.source"
        ></hilos-daemon-env-source-badge>
      } @else if (view.kind === 'digest') {
        <code class="small">{{ view.text }}</code>
        <hilos-daemon-env-source-badge
          [source]="view.source"
        ></hilos-daemon-env-source-badge>
      } @else if (view.kind === 'value') {
        <hilos-daemon-env-value [view]="view.view"></hilos-daemon-env-value>
        <hilos-daemon-env-source-badge
          [source]="view.source"
        ></hilos-daemon-env-source-badge>
      }
      @if (standsOut) {
        <span
          class="small text-danger"
          [attr.data-id]="
            'hilos-daemon-env-mismatch-cell-' +
            row.rowKey +
            '-' +
            nodeId +
            '-stands-out'
          "
        >
          <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i
          >Differs from the others
        </span>
      }
      @if (fromExample) {
        <span
          class="small text-warning-emphasis"
          [attr.data-id]="
            'hilos-daemon-env-mismatch-cell-' +
            row.rowKey +
            '-' +
            nodeId +
            '-from-example'
          "
        >
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>From
          .env.example
        </span>
      }
    </div> `,
})
export class HilosDaemonEnvMismatchCell {
  readonly row = input.required<HilosDaemonEnvMismatchRow>()
  readonly nodeId = input.required<string>()
  readonly servingNodeId = input.required<string | null>()
  readonly valuesMatch = input.required<boolean>()
  protected readonly view = computed(() =>
    daemonEnvMismatchCellView(
      this.row(),
      this.nodeId(),
      this.servingNodeId(),
      this.valuesMatch(),
    ),
  )
  protected readonly standsOut = computed(
    () =>
      !this.valuesMatch() &&
      daemonEnvMismatchStandsOut(this.row(), this.nodeId()),
  )
  protected readonly fromExample = computed(
    () =>
      this.valuesMatch() &&
      daemonEnvMismatchFromExample(this.row(), this.nodeId()),
  )
}
