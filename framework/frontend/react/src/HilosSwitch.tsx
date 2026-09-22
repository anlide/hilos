// HilosSwitch — an authoritative-backend switch. A click reports the next
// value but never moves the checkbox itself; the checked input follows only the
// value its owner received from the backend. The shared LoadingButton controller
// delays the busy spinner so a fast reply does not flash it.
import { useEffect, useId, useMemo } from 'react'
import type { HTMLAttributes, MouseEvent } from 'react'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosSwitch}; unknown attributes fall through to the group. */
export interface HilosSwitchProps extends Omit<
  HTMLAttributes<HTMLDivElement>,
  'id' | 'onToggle'
> {
  /** The backend-confirmed position of the switch. */
  checked: boolean
  /** Whether this switch's action is in flight. */
  busy?: boolean
  /** Disable the switch for a reason other than its own action. */
  disabled?: boolean
  /** The stable selector placed on the checkbox. */
  dataId: string
  /** The visible label, when the surface has one. */
  label?: string
  /** The accessible name when no visible label is drawn. */
  'aria-label'?: string
  /** The id of the hint describing the switch. */
  describedBy?: string
  /** Milliseconds to wait before showing the busy spinner. */
  spinnerDelay?: number
  /** Called with the position requested by the user. */
  onToggle(next: boolean): void
}

/**
 * A switch whose displayed position moves only with its checked input.
 *
 * @param props The switch state, labels, callback, and group attributes.
 * @returns The switch group.
 */
export function HilosSwitch({
  checked,
  busy = false,
  disabled = false,
  dataId,
  label,
  'aria-label': ariaLabel,
  describedBy,
  spinnerDelay = DEFAULT_SPINNER_DELAY_MS,
  onToggle,
  className,
  ...rest
}: HilosSwitchProps) {
  const id = useId()
  const spinner = useMemo(
    () => createLoadingButtonState(spinnerDelay),
    [spinnerDelay],
  )
  const showSpinner = useSignal(spinner.showSpinner)

  useEffect(() => {
    spinner.setLoading(busy)
  }, [spinner, busy])
  useEffect(() => () => spinner.dispose(), [spinner])

  const isDisabled = disabled || busy

  function onClick(event: MouseEvent<HTMLInputElement>): void {
    event.preventDefault()
    onToggle(!checked)
  }

  return (
    <div
      {...rest}
      className={`form-check form-switch${className ? ` ${className}` : ''}`}
    >
      <input
        id={id}
        className="form-check-input"
        type="checkbox"
        role="switch"
        checked={checked}
        readOnly
        disabled={isDisabled}
        aria-busy={busy || undefined}
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        data-id={dataId}
        onClick={onClick}
      />
      {label !== undefined ? (
        <label className="form-check-label" htmlFor={id}>
          {label}
        </label>
      ) : null}
      {showSpinner ? (
        <span
          className="spinner-border spinner-border-sm ms-2 align-middle"
          role="status"
        >
          <span className="visually-hidden">Saving…</span>
        </span>
      ) : null}
    </div>
  )
}
