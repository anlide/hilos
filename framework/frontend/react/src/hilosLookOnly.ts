// React context carrying whether the page's area stands inside a takeover where
// the administrator only looks (HIL-1170), and the hook the controls of the mode
// read it through. The application shell (HilosLayout) provides it around the
// page content — its children — and never around the shell itself, so the
// impersonation strip's Stop, "Keep my account" and signing out stay live; the
// value is the core's hilosTakeoverViewOnly, mirrored into React.
//
// The controls of the mode (LoadingButton, HilosSwitch, ConflictActions, the
// bulk operations of a table) read it through useLookOnly() to stand plainly
// disabled, described by the impersonation strip's text. In Vue the same hook
// also reads the admin view mode the admin page shell provides (HIL-1261); the
// React admin page shell does not provide it yet (HIL-1271), so here the
// takeover is the one source.
//
// Why not hilosTakeoverViewOnly itself: it is global, and a control reading it
// directly would lock the shell's own controls too — Stop among them, the one
// way out of the takeover. Outside the page's area the takeover never locks
// anything.
import { createContext, useContext } from 'react'
import { HILOS_IMPERSONATION_STRIP_TEXT_ID } from '@hilos/core'

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
 * Whether the controls around this component may only look: the page's area of
 * a takeover where the administrator only looks, described by the
 * impersonation strip.
 */
export function useLookOnly(): LookOnly {
  const takeover = useContext(HilosTakeoverViewOnlyContext)

  return {
    locked: takeover,
    describedBy: takeover ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined,
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
