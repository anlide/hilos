// LoadingButton — enhances a native button to disable itself and show a spinner
// while an async action is in flight (the authoritative-backend pattern: act ->
// block -> backend reply clears it). The delayed-spinner timer is the headless
// core state machine (createLoadingButtonState); this view only drives `loading`
// into it and renders `showSpinner`. The selector is an attribute on a native
// button, so the host IS the button: the Bootstrap variant class, the native
// (click), the form `type`, and aria fall through with no wrapper element, and a
// disabled host suppresses the click natively (no swallow handler needed). The
// label stays in the layout under the spinner so the button keeps its width.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'

/**
 * A button that blocks and shows a delayed spinner while an action is in flight.
 * Use it as an attribute on a native button:
 * `<button hilosLoadingButton [loading]="busy()" (click)="save()">Save</button>`.
 */
@Component({
  selector: 'button[hilosLoadingButton]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'btn position-relative',
    '[attr.type]': 'type()',
    '[disabled]': 'isDisabled()',
  },
  template: `
    <span [class.invisible]="showSpinner()"><ng-content /></span>
    @if (showSpinner()) {
      <span
        class="position-absolute top-50 start-50 translate-middle"
        data-id="loading-button-spinner"
      >
        <span class="spinner-border spinner-border-sm" role="status">
          <span class="visually-hidden">Loading…</span>
        </span>
      </span>
    }
  `,
})
export class LoadingButton {
  /** Whether the action is in flight: disables the button and arms the spinner. */
  readonly loading = input(false)
  /** Disable independently of loading (e.g. an invalid form). */
  readonly disabled = input(false)
  /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
  readonly loadingDelay = input(DEFAULT_SPINNER_DELAY_MS)
  /** Native button type; `submit` inside a form, `button` otherwise. */
  readonly type = input<'button' | 'submit' | 'reset'>('button')

  // The delay is read through a getter so the controller is built once at field
  // init (the input is not yet bound) yet always arms with the current value.
  private readonly spinner = createLoadingButtonState(() => this.loadingDelay())
  protected readonly showSpinner = hilosSignal(this.spinner.showSpinner)
  protected readonly isDisabled = computed(
    () => this.disabled() || this.loading(),
  )

  constructor() {
    effect(() => {
      this.spinner.setLoading(this.loading())
    })
    inject(DestroyRef).onDestroy(() => {
      this.spinner.dispose()
    })
  }
}
