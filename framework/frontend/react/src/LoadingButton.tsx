// LoadingButton — a button that locks itself the moment its async action is in
// flight and shows a spinner while it stays there (the authoritative-backend
// pattern: act -> block -> backend reply clears it). The lock is at once — a
// second press is swallowed and the button says it is busy — but the look is
// not: the button keeps its look, and its focus, until the spinner comes, and
// only then looks disabled, so a fast reply changes nothing on screen. The
// delayed-spinner timer is the headless core state machine
// (createLoadingButtonState); this view only drives `loading` into it and
// renders `showSpinner`. The label stays in the layout under the spinner so the
// button keeps its width. Pass the Bootstrap variant as a className
// (`className="btn-primary"`); className, aria, and data attributes fall
// through to the button. Inside an admin page a viewer of the admin view mode
// finds the button plainly disabled, described by the mode's strip (HIL-1261),
// and so does an administrator in the page's area of a takeover that only
// looks, described by the impersonation strip (HIL-1170); it changes neither
// its color, nor its size, nor its words. A button marked opensWindow stays
// live for both.
import { useEffect, useMemo } from 'react'
import type { ButtonHTMLAttributes } from 'react'
import {
  DEFAULT_SPINNER_DELAY_MS,
  createLoadingButtonState,
} from '@hilos/core/base'

import { joinDescribedBy, useLookOnly } from './hilosLookOnly.js'
import { useSignal } from './useSignal.js'

/** Props for {@link LoadingButton}; unknown attributes fall through to the button. */
export interface LoadingButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /**
   * Whether the button's own action is in flight — the immediate flag, raised
   * on the press and lowered by the reply, never a deferred one: the delay is
   * the button's own timer. It locks the button at once (busy, a press
   * swallowed) and arms the spinner; the disabled look comes with the spinner.
   */
  loading?: boolean
  /**
   * An independent reason the button may not be pressed — a form not yet
   * filled, a screen that only looks — never the button's own action in
   * flight. Disables it natively at once, while it loads as well.
   */
  disabled?: boolean
  /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
  loadingDelay?: number
  /**
   * The press only opens a window, at once or after the server's word; the
   * admin view mode and a takeover that only looks leave it live — what the
   * window would send stands on a control of the mode.
   */
  opensWindow?: boolean
}

/**
 * A button that locks at once and shows a delayed spinner while its own action
 * is in flight.
 *
 * @param props The button props; `loading`, `disabled`, `loadingDelay`,
 *   `opensWindow`, and the standard button attributes (`type`, `className`,
 *   `onClick`, …).
 */
export function LoadingButton({
  loading = false,
  disabled = false,
  loadingDelay = DEFAULT_SPINNER_DELAY_MS,
  opensWindow = false,
  type = 'button',
  className,
  children,
  onClick,
  'aria-busy': ariaBusy,
  'aria-disabled': ariaDisabled,
  'aria-describedby': ariaDescribedby,
  ...rest
}: LoadingButtonProps) {
  // The controller is recreated only if the delay changes; the dispose effect
  // releases the previous one. Driving `loading` is a separate effect.
  const spinner = useMemo(
    () => createLoadingButtonState(loadingDelay),
    [loadingDelay],
  )
  const showSpinner = useSignal(spinner.showSpinner)

  useEffect(() => {
    spinner.setLoading(loading)
  }, [spinner, loading])
  useEffect(() => () => spinner.dispose(), [spinner])

  const lookOnly = useLookOnly()
  const lockedToLook = lookOnly.locked && !opensWindow

  // A reason other than its own action turns the button off natively from the
  // first moment; its own action in flight only blocks it, so the focus stays on
  // it and its look holds until the spinner comes.
  const nativeDisabled = disabled || lockedToLook
  const blocked = nativeDisabled || loading

  // The caller's attributes reach the button as they are, save that none of
  // them takes back the busy or blocked state the button says of itself. Locked
  // to look, it is also described by the strip that says why, beside whatever
  // describes it already (a row's reason on the person's card).
  return (
    <button
      {...rest}
      type={type}
      disabled={nativeDisabled}
      aria-busy={loading ? true : ariaBusy}
      aria-disabled={blocked ? true : ariaDisabled}
      aria-describedby={joinDescribedBy(
        ariaDescribedby,
        lockedToLook ? lookOnly.describedBy : undefined,
      )}
      className={`btn position-relative${className ? ` ${className}` : ''}${showSpinner ? ' disabled' : ''}`}
      onClick={(event) => {
        // A blocked press is canceled before it leaves: no submit of the
        // button's form, by a click or by Enter in a field, and no click on its
        // ancestors.
        if (blocked) {
          event.preventDefault()
          event.stopPropagation()

          return
        }
        onClick?.(event)
      }}
    >
      <span className={showSpinner ? 'invisible' : undefined}>{children}</span>
      {showSpinner ? (
        <span
          className="position-absolute top-50 start-50 translate-middle"
          data-id="loading-button-spinner"
        >
          <span className="spinner-border spinner-border-sm" role="status">
            <span className="visually-hidden">Loading…</span>
          </span>
        </span>
      ) : null}
    </button>
  )
}
