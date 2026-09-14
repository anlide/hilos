// HilosAboutPage — the tier-2 public /about page: the project's own prose (the
// children) and, at the end of it, the block offering to support the project.
//
// The block is the surface and nothing behind it. Pressing a tier and confirming
// answers with an honest "the payment system is not built yet" — a real refusal
// rather than a dead button or a pretend success — and the whole payment door
// (providers, charges, cancelling, the tick in the profile, and what a person who
// already subscribes sees in this same place) is HIL-355's, undecomposed and
// un-interviewed. This page hands it no wire name, no storage and no
// configuration to inherit.
//
// The one decision that outlives the stub, and the reason the copy reads the way
// it does: the subscription unlocks NOTHING. Every feature is open to everyone
// without it, and what it buys is a tick and thanks. A support screen that hints
// at unlocking is read as a paywall however it is worded afterwards.
//
// Guest and signed-in see the same block — no per-reader part, no session read —
// which is also what keeps /about prerenderable whole: the plate and its button
// are in the static HTML, and everything browser-touching happens on the click.
//
// The page carries no `title` prop: the heading moves with the frame, and the
// project's file holds prose alone. Bootstrap classes only (styling-rules.md).
import { useState } from 'react'
import type { ReactNode } from 'react'

import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosSupportModal } from './HilosSupportModal.js'

/** Props for {@link HilosAboutPage}. */
export interface HilosAboutPageProps {
  /** The project's own About prose, above the support block. */
  children?: ReactNode
}

/**
 * The /about page: the project's prose, and under it the support block with its
 * dialog and the honest refusal.
 *
 * @param props The project's prose.
 */
export function HilosAboutPage({ children }: HilosAboutPageProps) {
  const [open, setOpen] = useState(false)

  return (
    <HilosStaticPage title="About">
      {children}

      <div
        className="border rounded p-4 mt-4 text-center"
        data-id="hilos-about-support"
      >
        <i
          className="bi bi-heart-fill text-danger fs-3 d-block mb-2"
          aria-hidden="true"
        />
        <h2 className="h6 mb-1">
          The project runs with no ads and no data selling
        </h2>
        <p className="small text-body-secondary mb-3">
          If it has been useful to you, you can chip in for its upkeep. In
          return you get nothing but a tick.
        </p>
        <button
          type="button"
          className="btn btn-primary"
          data-id="hilos-about-support-open"
          onClick={() => setOpen(true)}
        >
          Support the project
        </button>
      </div>

      <HilosSupportModal open={open} onClose={() => setOpen(false)} />
    </HilosStaticPage>
  )
}
