import {
  computedSignal,
  createSignal,
  hilosImpersonation,
  hilosSignedIn,
  type HilosTimeZonePicker,
  type HilosTimeZonePickerRow,
} from '@hilos/core/base'
import { useEffect, useMemo, useRef, useState } from 'react'

import { HilosActionError } from '../HilosActionError.js'
import { HilosModal } from '../HilosModal.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

/** Props for the profile's immediate Time zone choice. */
export interface HilosProfileTimeZoneProps {
  /** Whether the page asked for this window. */
  open: boolean
  /** Close this opening in its parent. */
  onClose: () => void
  /** The page's lifecycle-owned core picker. */
  picker: HilosTimeZonePicker
}

/** Read the live session again before dispatch and before closing on success. */
function available(): boolean {
  return hilosSignedIn.get() && hilosImpersonation.get() === null
}

/** Render the core catalog and previews; only the session echo moves the mark. */
export function HilosProfileTimeZone({
  open,
  onClose,
  picker,
}: HilosProfileTimeZoneProps) {
  const query = useSignal(picker.query)
  // This opening's gate keeps a mounted, closed view from eagerly subscribing
  // to expensive previews. The core still owns every clock and calculation.
  const preview = useMemo(() => {
    const shown = createSignal(false)
    return {
      shown,
      rows: computedSignal<readonly HilosTimeZonePickerRow[]>(() =>
        shown.get() ? picker.rows.get() : [],
      ),
      automatic: computedSignal(() =>
        shown.get() ? picker.automatic.get() : null,
      ),
    }
  }, [picker])
  const rows = useSignal(preview.rows)
  const automatic = useSignal(preview.automatic)
  const signedIn = useSignal(hilosSignedIn)
  const impersonation = useSignal(hilosImpersonation)
  const allowed = signedIn && impersonation === null
  const action = useTrackedAction({ toast: false })
  const { busy, loading, clearError } = action
  const [submitted, setSubmitted] = useState<string | null>(null)
  const sending = useRef(false)
  const opening = useRef(0)
  const close = useRef(onClose)
  close.current = onClose
  const current = useRef({ open, picker })
  current.current = { open, picker }

  useEffect(() => {
    opening.current++
    if (open && allowed) {
      clearError()
      picker.start()
      preview.shown.set(true)
    } else {
      preview.shown.set(false)
      picker.stop()
      if (open) close.current()
    }
    return () => {
      opening.current++
      preview.shown.set(false)
      picker.stop()
    }
  }, [open, allowed, picker, preview, clearError])

  async function choose(row: HilosTimeZonePickerRow): Promise<void> {
    if (
      !current.current.open ||
      current.current.picker !== picker ||
      busy ||
      sending.current ||
      !row.available ||
      !available()
    ) {
      return
    }
    const sentFrom = opening.current
    // React state lags a render: lock dispatch before a second click this tick.
    sending.current = true
    setSubmitted(row.timeZone)
    let success: boolean
    try {
      success = await action.run(picker.pick(row.timeZone))
    } finally {
      sending.current = false
    }
    if (
      success &&
      opening.current === sentFrom &&
      current.current.open &&
      available()
    ) {
      close.current()
    }
  }

  const groups = [
    ...(automatic ? [{ heading: null, rows: [automatic] }] : []),
    { heading: automatic ? 'All time zones' : 'Found', rows },
  ]

  return (
    <HilosModal
      open={open && allowed}
      title="Time zone"
      busy={busy}
      onClose={onClose}
      actions={({ requestClose }) => (
        <button
          type="button"
          className="btn btn-outline-secondary"
          disabled={busy}
          data-id="profile-time-zone-close"
          onClick={requestClose}
        >
          Close
        </button>
      )}
    >
      <div data-id="profile-time-zone-modal">
        <HilosActionError
          action={action}
          detailsTitle="Could not change time zone"
        />
        <input
          type="search"
          className="form-control"
          placeholder="Search time zones"
          aria-label="Search time zones"
          data-id="profile-time-zone-search"
          data-autofocus
          value={query}
          disabled={busy}
          onChange={(event) => picker.setQuery(event.target.value)}
        />
        {groups.map((group, index) => (
          <div key={index} className="mt-3">
            {group.heading ? (
              <h6 className="small fw-semibold text-body-secondary mb-1">
                {group.heading}
              </h6>
            ) : null}
            <div className="list-group">
              {group.rows.map((row) => {
                const pending = loading && submitted === row.timeZone
                return (
                  <button
                    key={row.timeZone ?? 'automatic'}
                    type="button"
                    className={`list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative${row.selected ? ' active' : ''}`}
                    aria-current={row.selected ? 'true' : undefined}
                    disabled={busy || !row.available}
                    aria-busy={
                      busy && submitted === row.timeZone ? true : undefined
                    }
                    data-id={
                      row.timeZone === null
                        ? 'profile-time-zone-automatic'
                        : `profile-time-zone-option-${row.timeZone}`
                    }
                    onClick={() => void choose(row)}
                  >
                    <span className="flex-grow-1 text-break">
                      {row.timeZone === null ? 'Automatic' : row.label}
                      {row.timeZone === null || !row.available ? (
                        <span
                          className={`d-block small${row.selected ? '' : ' text-body-secondary'}`}
                        >
                          {row.timeZone === null
                            ? `${row.label} — from this browser`
                            : 'Unavailable in this browser'}
                        </span>
                      ) : null}
                    </span>
                    <span className="small text-nowrap">{row.time ?? '—'}</span>
                    <i
                      className={`bi bi-check2${!row.selected || pending ? ' invisible' : ''}`}
                      aria-hidden="true"
                    />
                    {pending ? (
                      <span
                        className="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
                        role="status"
                        data-id="profile-time-zone-loading"
                      >
                        <span className="visually-hidden">Loading…</span>
                      </span>
                    ) : null}
                  </button>
                )
              })}
            </div>
          </div>
        ))}
        {rows.length === 0 ? (
          <p
            className="small text-body-secondary mt-3 mb-0"
            data-id="profile-time-zone-empty"
          >
            No time zones match
          </p>
        ) : null}
      </div>
    </HilosModal>
  )
}
