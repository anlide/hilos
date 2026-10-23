// LoadingButton — enhances a native button to lock itself the moment its async
// action is in flight and show a spinner while it stays there (the
// authoritative-backend pattern: act -> block -> backend reply clears it). The
// lock is at once — a second press is swallowed and the button says it is busy
// — but the look is not: the button keeps its look, and its focus, until the
// spinner comes, and only then looks disabled, so a fast reply changes nothing
// on screen. The delayed-spinner timer is the headless core state machine
// (createLoadingButtonState); this view only drives `loading` into it and
// renders `showSpinner`. The selector is an attribute on a native button, so the
// host IS the button: the Bootstrap variant class, the native (click), the form
// `type`, and aria fall through with no wrapper element. A button its own flight
// locks is still enabled, so it swallows a blocked press itself, and the
// caller's (click) listens on the very same element: the swallow listens in the
// capture phase, which the button runs before any listener of the bubble phase.
// The label stays in the layout under the spinner so the button keeps its width.
// In the page's area of a takeover that only looks the button stands plainly
// disabled, described by the impersonation strip (HIL-1170); it changes neither
// its color, nor its size, nor its words. A button marked opensWindow stays
// live. Its own `aria-describedby` is an input, so the strip's id is joined to
// it rather than written over it.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  DEFAULT_SPINNER_DELAY_MS,
  createLoadingButtonState,
} from '@hilos/core/base'

import { injectLookOnly, joinDescribedBy } from './hilosLookOnly.js'
import { hilosSignal } from './hilosSignal.js'

/**
 * A button that locks at once and shows a delayed spinner while its own action
 * is in flight. Use it as an attribute on a native button:
 * `<button hilosLoadingButton [loading]="busy()" (click)="save()">Save</button>`.
 */
@Component({
  selector: 'button[hilosLoadingButton]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'btn position-relative',
    '[class.disabled]': 'showSpinner()',
    '[attr.type]': 'type()',
    '[disabled]': 'nativeDisabled()',
    '[attr.aria-busy]': 'loading() || null',
    '[attr.aria-disabled]': 'blocked() || null',
    '[attr.aria-describedby]': 'describedBy()',
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
  /**
   * Whether the button's own action is in flight — the immediate flag, raised
   * on the press and lowered by the reply, never a deferred one: the delay is
   * the button's own timer. It locks the button at once (busy, a press
   * swallowed) and arms the spinner; the disabled look comes with the spinner.
   */
  readonly loading = input(false)
  /**
   * An independent reason the button may not be pressed — a form not yet
   * filled, a screen that only looks — never the button's own action in
   * flight. Disables it natively at once, while it loads as well.
   */
  readonly disabled = input(false)
  /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
  readonly loadingDelay = input(DEFAULT_SPINNER_DELAY_MS)
  /** Native button type; `submit` inside a form, `button` otherwise. */
  readonly type = input<'button' | 'submit' | 'reset'>('button')
  /**
   * The press only opens a window, at once or after the server's word; a
   * takeover that only looks leaves it live — what the window would send stands
   * on a control of the mode.
   */
  readonly opensWindow = input(false)
  /** The ids describing the button (a row's reason on the person's card). */
  readonly ariaDescribedby = input<string>(undefined, {
    alias: 'aria-describedby',
  })

  // The delay is read through a getter so the controller is built once at field
  // init (the input is not yet bound) yet always arms with the current value.
  private readonly spinner = createLoadingButtonState(() => this.loadingDelay())
  protected readonly showSpinner = hilosSignal(this.spinner.showSpinner)
  private readonly lookOnly = injectLookOnly()
  private readonly lockedToLook = computed(
    () => this.lookOnly.locked() && !this.opensWindow(),
  )
  // A reason other than its own action turns the button off natively from the
  // first moment; its own action in flight only blocks it, so the focus stays on
  // it and its look holds until the spinner comes.
  protected readonly nativeDisabled = computed(
    () => this.disabled() || this.lockedToLook(),
  )
  protected readonly blocked = computed(
    () => this.nativeDisabled() || this.loading(),
  )
  // Locked to look, the button is also described by the strip that says why,
  // beside whatever describes it already.
  protected readonly describedBy = computed(() =>
    joinDescribedBy(
      this.ariaDescribedby(),
      this.lockedToLook() ? this.lookOnly.describedBy() : undefined,
    ),
  )
  private readonly host = inject<ElementRef<HTMLButtonElement>>(ElementRef)

  constructor() {
    effect(() => {
      this.spinner.setLoading(this.loading())
    })
    // A blocked press is canceled before it leaves: no submit of the button's
    // form, by a click or by Enter in a field, no (click) of the caller on this
    // same button, and no click on its ancestors. A press it lets through goes on
    // as it came.
    const swallowBlocked = (event: Event): void => {
      if (this.blocked()) {
        event.preventDefault()
        event.stopImmediatePropagation()
      }
    }
    const button = this.host.nativeElement
    button.addEventListener('click', swallowBlocked, true)
    inject(DestroyRef).onDestroy(() => {
      button.removeEventListener('click', swallowBlocked, true)
      this.spinner.dispose()
    })
  }
}
