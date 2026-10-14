import { NgClass } from '@angular/common'
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosDaemonEnvValueView } from '@hilos/core'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'

/** Angular view of the Daemon EnvValue. */
@Component({
  selector: 'hilos-daemon-env-value',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass, HilosHiddenMark],
  template: `@let view = this.view();

    @if (view.kind === 'hidden') {
      <hilos-hidden-mark></hilos-hidden-mark>
    } @else if (view.kind === 'secret') {
      <span class="text-body-secondary d-inline-flex align-items-center gap-1">
        <i class="bi bi-key" aria-hidden="true"></i>{{ view.text }}
      </span>
    } @else if (view.kind === 'none') {
      <span class="text-body-secondary" [class.text-danger]="required()"
        >—</span
      >
    } @else if (view.kind === 'empty') {
      <span
        class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
        title="An empty string is a value"
      >
        empty
      </span>
    } @else if (view.kind === 'boolean') {
      <input
        type="checkbox"
        class="form-check-input mt-0 flex-shrink-0"
        disabled
        tabindex="-1"
        [checked]="view.on"
        [attr.aria-label]="view.on ? 'On' : 'Off'"
      />
    } @else if (view.kind === 'text') {
      <code [ngClass]="{ 'fst-italic': view.numeric }">
        {{ view.text }}
      </code>
    } `,
})
export class HilosDaemonEnvValue {
  readonly required = input(true)
  readonly view = input.required<HilosDaemonEnvValueView>()
}
