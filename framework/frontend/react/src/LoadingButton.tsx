// LoadingButton — a button that disables itself and shows a spinner while an
// async action is in flight (the authoritative-backend pattern: act -> block ->
// backend reply clears it). The delayed-spinner timer is the headless core state
// machine (createLoadingButtonState); this view only drives `loading` into it
// and renders `showSpinner`. The spinner appears only after a short delay so a
// fast reply never flashes it, and the label stays in the layout under the
// spinner so the button keeps its width. Pass the Bootstrap variant as a
// className (`className="btn-primary"`); className, aria, and data attributes
// fall through to the button.
import { useEffect, useMemo } from 'react'
import type { ButtonHTMLAttributes } from 'react'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link LoadingButton}; unknown attributes fall through to the button. */
export interface LoadingButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Whether the action is in flight: disables the button and arms the spinner. */
  loading?: boolean
  /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
  loadingDelay?: number
}

/**
 * A button that blocks and shows a delayed spinner while an action is in flight.
 *
 * @param props The button props; `loading`, `loadingDelay`, and the standard
 *   button attributes (`disabled`, `type`, `className`, `onClick`, …).
 */
export function LoadingButton({
  loading = false,
  disabled = false,
  loadingDelay = DEFAULT_SPINNER_DELAY_MS,
  type = 'button',
  className,
  children,
  onClick,
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

  const isDisabled = disabled || loading

  return (
    <button
      {...rest}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
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
