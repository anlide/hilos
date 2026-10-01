// LoadingButton — a button that disables itself and shows a spinner while an
// async action is in flight (the authoritative-backend pattern: act -> block ->
// backend reply clears it). The delayed-spinner timer is the headless core state
// machine (createLoadingButtonState); this view only drives `loading` into it
// and renders `showSpinner`. The spinner appears only after a short delay so a
// fast reply never flashes it, and the label stays in the layout under the
// spinner so the button keeps its width. Pass the Bootstrap variant as a
// className (`className="btn-primary"`); className, aria, and data attributes
// fall through to the button. In the page's area of a takeover that only looks
// the button stands plainly disabled, described by the impersonation strip
// (HIL-1170); it changes neither its color, nor its size, nor its words. A
// button marked opensWindow stays live.
import { useEffect, useMemo } from 'react'
import type { ButtonHTMLAttributes } from 'react'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { joinDescribedBy, useLookOnly } from './hilosLookOnly.js'
import { useSignal } from './useSignal.js'

/** Props for {@link LoadingButton}; unknown attributes fall through to the button. */
export interface LoadingButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Whether the action is in flight: disables the button and arms the spinner. */
  loading?: boolean
  /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
  loadingDelay?: number
  /**
   * The press only opens a window, at once or after the server's word; a
   * takeover that only looks leaves it live — what the window would send stands
   * on a control of the mode.
   */
  opensWindow?: boolean
}

/**
 * A button that blocks and shows a delayed spinner while an action is in flight.
 *
 * @param props The button props; `loading`, `loadingDelay`, `opensWindow`, and
 *   the standard button attributes (`disabled`, `type`, `className`, `onClick`, …).
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

  // Locked to look, the button is also described by the strip that says why,
  // beside whatever describes it already (a row's reason on the person's card).
  const lookOnly = useLookOnly()
  const lockedToLook = lookOnly.locked && !opensWindow
  const isDisabled = disabled || loading || lockedToLook

  return (
    <button
      {...rest}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      aria-describedby={joinDescribedBy(
        ariaDescribedby,
        lockedToLook ? lookOnly.describedBy : undefined,
      )}
      className={`btn position-relative${className ? ` ${className}` : ''}`}
      onClick={(event) => {
        if (!isDisabled) {
          onClick?.(event)
        }
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
