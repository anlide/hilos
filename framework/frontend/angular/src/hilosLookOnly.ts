// The injection tokens carrying whether the controls around a component may only
// look, and the helper the controls of the mode read them through. Two places
// say so, each about its own region of the screen:
//
// - The admin page shell (HilosAdminPage) provides whether a viewer of the
//   admin view mode stands on this page (HIL-1261) — true while the admin
//   section is 'view' to this browser (hilosAdminAccess, HIL-1253).
// - The application shell (HilosLayout) provides to its projected content —
//   the page — whether this session is inside a takeover where the
//   administrator only looks (HIL-1170, the core's hilosTakeoverViewOnly
//   mirrored into Angular), and provides false to its own view, so the
//   impersonation strip's Stop, "Keep my account" and signing out stay live.
//
// The controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the
// bulk operations of a table) read both through injectLookOnly() to stand
// plainly disabled, described by the strip that says why.
//
// Why not hilosAdminAccess or hilosTakeoverViewOnly themselves: both are
// global. A signed-in non-admin on a node in the view mode is 'view' on every
// screen, and a control reading it directly would lock that viewer's own
// profile and the shell's own controls — the deletion strip's "Keep my
// account", the impersonation strip's Stop, the one way out of the takeover.
// Without a provider neither ever locks anything: outside the admin page shell
// the admin view mode is always false, and outside the page's area the
// takeover is too.
import { InjectionToken, computed, inject } from '@angular/core'
import type { Signal } from '@angular/core'
import {
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
} from '@hilos/core'

/**
 * Provide/inject token for whether a viewer of the admin view mode stands on
 * the admin page around this component.
 */
export const HILOS_ADMIN_VIEW_MODE = new InjectionToken<Signal<boolean>>(
  'HilosAdminViewMode',
)

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
   * The id of the strip text the locked control names in `aria-describedby` —
   * both, space-separated, when both say so — or undefined while it is live.
   */
  readonly describedBy: Signal<string | undefined>
}

/**
 * Whether the controls around this component may only look: a viewer of the
 * admin view mode on an admin page (described by the view-mode strip), or the
 * page's area of a takeover where the administrator only looks (described by
 * the impersonation strip). Call it in an injection context (a field
 * initializer).
 */
export function injectLookOnly(): LookOnly {
  const viewMode = inject(HILOS_ADMIN_VIEW_MODE, { optional: true })
  const takeover = inject(HILOS_TAKEOVER_VIEW_ONLY, { optional: true })
  const viewing = computed(() => viewMode?.() ?? false)
  const lookingOnly = computed(() => takeover?.() ?? false)

  return {
    locked: computed(() => viewing() || lookingOnly()),
    describedBy: computed(() => {
      const ids = [
        ...(viewing() ? [HILOS_VIEW_MODE_STRIP_TEXT_ID] : []),
        ...(lookingOnly() ? [HILOS_IMPERSONATION_STRIP_TEXT_ID] : []),
      ]

      return ids.length > 0 ? ids.join(' ') : undefined
    }),
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
