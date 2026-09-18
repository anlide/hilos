// HilosSupportModal — the dialog behind the one button of the support block on
// /about: pick a tier, press Subscribe, and get told the truth. The tiers and the
// sentence are core data (@hilos/core, public/supportTiers.ts); the rest of the
// words are markup here, the way every other SDK page carries its text.
//
// Nothing is sent. The refusal is a fixed sentence this surface carries, and that
// is the point rather than a shortcut: a signal, a payload or an error class
// would hand the payment ticket a protocol nobody designed. For the same reason
// HilosActionError is not used even though the plate copies its shape — that
// component draws the failure of a TrackedAction, and faking one with no request
// behind it would put a lie in the one place the framework tells the truth about
// failures. No toast either: nothing reached the server, so there is no outcome
// to report to a corner, and the person is looking at this dialog.
//
// The plate belongs to the ATTEMPT that raised it: choosing another tier re-arms
// the dialog, and closing it starts the next visit clean — the same rule the
// framework already keeps for a failure panel.
//
// Not exported from the package: what a project mounts is the page. The block has
// exactly one home, the end of About, and a modal in the SDK's public surface
// would be a component projects are invited to reuse. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useState } from 'react'
import {
  HILOS_SUPPORT_DEFAULT_TIER,
  HILOS_SUPPORT_REFUSAL,
  HILOS_SUPPORT_TIERS,
  type HilosSupportTierKey,
} from '@hilos/core'

import { HilosModal } from '../HilosModal.js'

/** Props for {@link HilosSupportModal}. */
export interface HilosSupportModalProps {
  /** Whether the dialog is open (controlled). */
  open: boolean
  /** Called when the dialog is dismissed. */
  onClose: () => void
}

/**
 * The support dialog: the tiers, the note, and the honest refusal.
 *
 * @param props The open state and the dismissal.
 */
export function HilosSupportModal({ open, onClose }: HilosSupportModalProps) {
  /** The tier the person is subscribing at; never empty, so Subscribe is never dead. */
  const [selected, setSelected] = useState<HilosSupportTierKey>(
    HILOS_SUPPORT_DEFAULT_TIER,
  )
  /** Whether the honest refusal is on screen — the answer to the last attempt. */
  const [refused, setRefused] = useState(false)

  // A reopened dialog is a new visit: the tier goes back to the drawn default and
  // the refusal goes with it. The state is the dialog's, not the page's.
  useEffect(() => {
    if (open) {
      setSelected(HILOS_SUPPORT_DEFAULT_TIER)
      setRefused(false)
    }
  }, [open])

  /**
   * What the screen reader is told, and nothing while there is nothing to tell.
   *
   * The sentence is announced from a region that stands in the dialog before it
   * has anything to say — a role arriving together with its text is not
   * announced at all (accessibility.md) — and the visible plate carries no role.
   */
  const announced = refused ? HILOS_SUPPORT_REFUSAL : ''

  /**
   * Take a tier and re-arm the dialog.
   *
   * @param key The tier the person pressed.
   */
  function pick(key: HilosSupportTierKey): void {
    setSelected(key)
    setRefused(false)
  }

  return (
    <HilosModal
      open={open}
      title="Support the project"
      initialFocus="dialog"
      onClose={onClose}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-outline-secondary"
            data-id="hilos-about-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <button
            type="button"
            className="btn btn-primary"
            data-id="hilos-about-subscribe"
            onClick={() => setRefused(true)}
          >
            Subscribe
          </button>
        </>
      )}
    >
      {/* The refusal is announced from here and not from the plate that shows
          it. The region lives inside the dialog because the dialog is
          aria-modal, which hides the page under it from a screen reader. */}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="hilos-about-live-assertive"
      >
        {announced}
      </div>

      <p className="small text-body-secondary mb-3">
        Pick what you can spare. The money goes to servers and coffee.
      </p>

      <div className="d-grid gap-2 mb-3" role="group" aria-label="Support tier">
        {HILOS_SUPPORT_TIERS.map((tier) => (
          <button
            key={tier.key}
            type="button"
            className={`btn btn-outline-secondary text-start d-flex align-items-center gap-3${selected === tier.key ? ' border-primary' : ''}`}
            aria-pressed={selected === tier.key}
            data-id={`hilos-about-tier-${tier.key}`}
            onClick={() => pick(tier.key)}
          >
            <i className={`bi ${tier.icon} fs-5`} aria-hidden="true" />
            <span className="flex-grow-1">
              <span className="d-block fw-semibold small">
                {tier.label} · {tier.price}
              </span>
              <span className="d-block small text-body-secondary">
                {tier.blurb}
              </span>
            </span>
          </button>
        ))}
      </div>

      <div className="position-relative" data-id="hilos-about-refusal-slot">
        <div
          className="alert alert-secondary small py-2 mb-0 invisible"
          aria-hidden="true"
          data-id="hilos-about-refusal-idle"
        >
          <i className="bi bi-info-circle me-1" aria-hidden="true" />
          {HILOS_SUPPORT_REFUSAL}
        </div>
        {refused ? (
          <div
            className="alert alert-secondary small py-2 mb-0 position-absolute top-0 start-0 w-100"
            data-id="hilos-about-refusal"
          >
            <i className="bi bi-info-circle me-1" aria-hidden="true" />
            {HILOS_SUPPORT_REFUSAL}
          </div>
        ) : (
          <div
            className="alert alert-secondary small py-2 mb-0 position-absolute top-0 start-0 w-100"
            data-id="hilos-about-note"
          >
            <i className="bi bi-info-circle me-1" aria-hidden="true" />A
            subscription unlocks nothing: every feature is open to everyone
            anyway.
          </div>
        )}
      </div>
    </HilosModal>
  )
}
