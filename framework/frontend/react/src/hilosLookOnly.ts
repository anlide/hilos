// React contexts carrying whether the controls around a component may only
// look, and the hook the controls of the mode read them through. Two places
// say so, each about its own region of the screen:
//
// - The admin page shell (HilosAdminPage) provides whether a viewer of the
//   admin view mode stands on this page (HIL-1261) — true while the admin
//   section is 'view' to this browser (hilosAdminAccess, HIL-1253).
// - The application shell (HilosLayout) provides, around the page's area only,
//   whether this session is inside a takeover where the administrator only
//   looks (HIL-1170, hilosTakeoverViewOnly) — never around the shell itself,
//   so the impersonation strip's Stop, "Keep my account" and signing out stay
//   live.
//
// The controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the
// bulk operations of a table) read both through useLookOnly() to stand plainly
// disabled, described by the strip that says why.
//
// Why not hilosAdminAccess or hilosTakeoverViewOnly themselves: both are
// global. A signed-in non-admin on a node in the view mode is 'view' on every
// screen, and a control reading it directly would lock that viewer's own
// profile and the shell's own controls — the deletion strip's "Keep my
// account", the impersonation strip's Stop (HilosLayout). Outside the admin
// page shell the admin view mode context is always false, and outside the
// page's area the takeover never locks anything.
import { createContext, useContext } from 'react'
import {
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
} from '@hilos/core'

/**
 * Provides whether a viewer of the admin view mode stands on the admin page
 * around this component; false outside the admin page shell.
 */
export const HilosAdminViewModeContext = createContext(false)

/**
 * Provides whether the page's area stands inside a takeover where the
 * administrator only looks; false outside the area the shell wraps.
 */
export const HilosTakeoverViewOnlyContext = createContext(false)

/** Whether a control may only look, and the strip that says why. */
export interface LookOnly {
  /** Whether the control stands plainly disabled. */
  readonly locked: boolean
  /**
   * The id of the strip text the locked control names in `aria-describedby`,
   * or undefined while it is live.
   */
  readonly describedBy: string | undefined
}

/**
 * Whether the controls around this component may only look: a viewer of the
 * admin view mode on an admin page (described by the view-mode strip), or the
 * page's area of a takeover where the administrator only looks (described by
 * the impersonation strip).
 */
export function useLookOnly(): LookOnly {
  const viewMode = useContext(HilosAdminViewModeContext)
  const takeover = useContext(HilosTakeoverViewOnlyContext)

  const ids = [
    ...(viewMode ? [HILOS_VIEW_MODE_STRIP_TEXT_ID] : []),
    ...(takeover ? [HILOS_IMPERSONATION_STRIP_TEXT_ID] : []),
  ]

  return {
    locked: viewMode || takeover,
    describedBy: ids.length > 0 ? ids.join(' ') : undefined,
  }
}

/**
 * Join the ids that describe a control: its own, then the strip that says why
 * it may only look.
 *
 * @param own The control's own `aria-describedby`, if any.
 * @param strip The strip's id while the control is locked, or undefined.
 * @returns The joined ids, or undefined when neither is present.
 */
export function joinDescribedBy(
  own: string | undefined,
  strip: string | undefined,
): string | undefined {
  if (strip === undefined) {
    return own
  }

  return own === undefined || own === '' ? strip : `${own} ${strip}`
}
