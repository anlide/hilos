// The account's live theme choice (HIL-1440, the React port of HIL-1437). Each
// click is one tracked write, with no draft or Save. Only the server handshake
// moves the mark.
import {
  hilosImpersonation,
  hilosSignedIn,
  hilosThemeChoice,
  sendHilosThemePick,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePick,
} from '@hilos/core/base'
import { useEffect, useRef, useState } from 'react'

import { HilosActionError } from '../HilosActionError.js'
import { HilosModal } from '../HilosModal.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

/** Props for {@link HilosProfileTheme}. */
export interface HilosProfileThemeProps {
  /** Whether the profile's Change asked for the window. */
  open: boolean
  /** Close the window — the page sets `open` to false. */
  onClose: () => void
}

/**
 * Whether an account choice can be sent at this moment, read from the live
 * signals rather than from the last render.
 */
function available(): boolean {
  return (
    hilosThemeChoice.get() !== null &&
    hilosSignedIn.get() &&
    hilosImpersonation.get() === null
  )
}

/**
 * The Theme window of the profile: three live positions and the reset.
 *
 * @param props Whether the window is asked for, and how it closes.
 */
export function HilosProfileTheme({ open, onClose }: HilosProfileThemeProps) {
  const choice = useSignal(hilosThemeChoice)
  const signedIn = useSignal(hilosSignedIn)
  const impersonation = useSignal(hilosImpersonation)
  const allowed = choice !== null && signedIn && impersonation === null
  const action = useTrackedAction()
  const { busy, loading, clearError } = action
  const [submitted, setSubmitted] = useState<ThemePick>(null)
  // React state lags a render: a second click in the same tick would reach
  // sendHilosThemePick before `busy` turns true, so the send guards itself.
  const sending = useRef(false)
  // An answer belongs to the opening that sent it. Closing and reopening while
  // the write is pending must not let its success close the new opening.
  const opening = useRef(0)
  const close = useRef(onClose)
  close.current = onClose

  useEffect(() => {
    opening.current++
    if (open) {
      clearError()
      if (!allowed) close.current()
    }
  }, [open, allowed, clearError])
  useEffect(
    () => () => {
      opening.current++
    },
    [],
  )

  async function choose(pick: ThemePick): Promise<void> {
    if (!open || sending.current || !available()) {
      return
    }
    const sentFrom = opening.current
    sending.current = true
    setSubmitted(pick)
    let success: boolean
    try {
      success = await action.run(sendHilosThemePick(pick))
    } finally {
      sending.current = false
    }
    if (success && opening.current === sentFrom && available()) {
      close.current()
    }
  }

  return (
    <HilosModal
      open={open && allowed}
      title={THEME_COPY.menu}
      initialFocus="dialog"
      onClose={onClose}
      actions={({ requestClose }) => (
        <button
          type="button"
          className="btn btn-outline-secondary"
          data-id="profile-theme-close"
          onClick={requestClose}
        >
          Close
        </button>
      )}
    >
      <div data-id="profile-theme-modal">
        <HilosActionError
          action={action}
          detailsTitle="Couldn't change theme"
        />
        <div className="list-group">
          {THEME_POSITIONS.map((row) => {
            const current = row.value === choice?.position
            const pending = loading && submitted === row.value

            return (
              <button
                key={row.value}
                type="button"
                className={`list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative${current ? ' active' : ''}`}
                aria-current={current ? 'true' : undefined}
                disabled={busy}
                aria-busy={busy && submitted === row.value ? true : undefined}
                data-id={`profile-theme-option-${row.value}`}
                onClick={() => void choose(row.value)}
              >
                <i className={`bi ${row.icon}`} aria-hidden="true" />
                <span className="flex-grow-1">{row.label}</span>
                {current && choice?.isDefault && (
                  <span className="badge rounded-pill bg-body-tertiary text-body-emphasis border">
                    {THEME_COPY.default}
                  </span>
                )}
                <i
                  className={`bi bi-check2${!current || pending ? ' invisible' : ''}`}
                  aria-hidden="true"
                />
                {pending && (
                  <span
                    className="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
                    role="status"
                    data-id="profile-theme-loading"
                  >
                    <span className="visually-hidden">Loading…</span>
                  </span>
                )}
              </button>
            )
          })}
        </div>
        <hr />
        <button
          type="button"
          className="btn btn-outline-secondary w-100 position-relative"
          disabled={busy}
          aria-busy={busy && submitted === null ? true : undefined}
          data-id="profile-theme-default"
          onClick={() => void choose(null)}
        >
          <span
            className={loading && submitted === null ? 'invisible' : undefined}
          >
            {THEME_COPY.siteDefault}
          </span>
          {loading && submitted === null && (
            <span
              className="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
              role="status"
              data-id="profile-theme-loading"
            >
              <span className="visually-hidden">Loading…</span>
            </span>
          )}
        </button>
      </div>
    </HilosModal>
  )
}
