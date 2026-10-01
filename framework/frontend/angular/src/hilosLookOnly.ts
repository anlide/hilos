// The injection token carrying whether the page's area stands inside a takeover
// where the administrator only looks (HIL-1170), and the helper the controls of
// the mode read it through. The application shell (HilosLayout) provides it to
// its projected content — the page — and provides false to its own view, so
// the impersonation strip's Stop, "Keep my account" and signing out stay live;
// the value is the core's hilosTakeoverViewOnly, mirrored into Angular.
//
// The controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the
// bulk operations of a table) read it through injectLookOnly() to stand plainly
// disabled, described by the impersonation strip's text. In Vue the same helper
// also reads the admin view mode the admin page shell provides (HIL-1261); the
// Angular admin page shell does not provide it yet (HIL-1272), so here the
// takeover is the one source.
//
// Why not hilosTakeoverViewOnly itself: it is global, and a control reading it
// directly would lock the shell's own controls too — Stop among them, the one
// way out of the takeover. Without a provider the takeover never locks
// anything.
import { InjectionToken, computed, inject } from '@angular/core'
import type { Signal } from '@angular/core'
import { HILOS_IMPERSONATION_STRIP_TEXT_ID } from '@hilos/core'

/**
 * Provide/inject token for whether the page's area stands inside a takeover
 * where the administrator only looks.
 */
export const HILOS_TAKEOVER_VIEW_ONLY = new InjectionToken<Signal<boolean>>(
  'HilosTakeoverViewOnly',
)

/** Whether a control may only look, and the strip that says why. */
export interface LookOnly {
  /** Whether the control stands plainly disabled. */
  readonly locked: Signal<boolean>
  /**
   * The id of the strip text the locked control names in `aria-describedby`,
   * or undefined while it is live.
   */
  readonly describedBy: Signal<string | undefined>
}

/**
 * Whether the controls around this component may only look: the page's area of
 * a takeover where the administrator only looks, described by the
 * impersonation strip. Call it in an injection context (a field initializer).
 */
export function injectLookOnly(): LookOnly {
  const takeover = inject(HILOS_TAKEOVER_VIEW_ONLY, { optional: true })
  const locked = computed(() => takeover?.() ?? false)

  return {
    locked,
    describedBy: computed(() =>
      locked() ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined,
    ),
  }
}

/**
 * Join the ids that describe a control: its own, then the strip that says why
 * it may only look.
 *
 * @param own The control's own `aria-describedby`, if any.
 * @param strip The strip's id while the control is locked, or undefined.
 * @returns The joined ids, or null when neither is present (no attribute).
 */
export function joinDescribedBy(
  own: string | undefined,
  strip: string | undefined,
): string | null {
  if (strip === undefined) {
    return own === undefined || own === '' ? null : own
  }

  return own === undefined || own === '' ? strip : `${own} ${strip}`
}
