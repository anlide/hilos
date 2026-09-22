// HilosSwitch — an authoritative-backend switch. A click reports the next
// value but never moves the checkbox itself; the checked input follows only the
// value its owner received from the backend. The shared LoadingButton controller
// delays the busy spinner so a fast reply does not flash it.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
} from '@angular/core'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'

// Distinct ids so two renderings of one table row never share a label `for`.
let switchSeq = 0

/** A switch whose displayed position moves only with its checked input. */
@Component({
  selector: 'hilos-switch',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'form-check form-switch',
  },
  template: `
    <input
      [id]="id"
      class="form-check-input"
      type="checkbox"
      role="switch"
      [checked]="checked()"
      [disabled]="isDisabled()"
      [attr.aria-busy]="busy() || null"
      [attr.aria-label]="ariaLabel() || null"
      [attr.aria-describedby]="describedBy() || null"
      [attr.data-id]="dataId()"
      (click)="onClick($event)"
    />
    @if (label(); as visibleLabel) {
      <label class="form-check-label" [attr.for]="id">
        {{ visibleLabel }}
      </label>
    }
    @if (showSpinner()) {
      <span
        class="spinner-border spinner-border-sm ms-2 align-middle"
        role="status"
      >
        <span class="visually-hidden">Saving…</span>
      </span>
    }
  `,
})
export class HilosSwitch {
  /** The backend-confirmed position of the switch. */
  readonly checked = input.required<boolean>()
  /** Whether this switch's action is in flight. */
  readonly busy = input(false)
  /** Disable the switch for a reason other than its own action. */
  readonly disabled = input(false)
  /** The stable selector placed on the checkbox. */
  readonly dataId = input.required<string>()
  /** The visible label, when the surface has one. */
  readonly label = input<string>()
  /** The accessible name when no visible label is drawn. */
  readonly ariaLabel = input<string>(undefined, { alias: 'aria-label' })
  /** The id of the hint describing the switch. */
  readonly describedBy = input<string>()
  /** Milliseconds to wait before showing the busy spinner. */
  readonly spinnerDelay = input(DEFAULT_SPINNER_DELAY_MS)
  /** The position requested by the user. */
  readonly toggle = output<boolean>()

  private readonly spinner = createLoadingButtonState(() => this.spinnerDelay())
  protected readonly id = `hilos-switch-${switchSeq++}`
  protected readonly showSpinner = hilosSignal(this.spinner.showSpinner)
  protected readonly isDisabled = computed(() => this.disabled() || this.busy())

  constructor() {
    effect(() => {
      this.spinner.setLoading(this.busy())
    })
    inject(DestroyRef).onDestroy(() => {
      this.spinner.dispose()
    })
  }

  protected onClick(event: MouseEvent): void {
    event.preventDefault()
    this.toggle.emit(!this.checked())
  }
}
