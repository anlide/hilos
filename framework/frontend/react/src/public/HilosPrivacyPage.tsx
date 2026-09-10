// HilosPrivacyPage — the tier-2 public /privacy page: the project's own privacy
// prose (the children) and, under it, the one thing that exists nowhere else in
// the product — erase everything this browser keeps and end its session.
//
// The block is the same for a guest and for a signed-in person, deliberately. A
// browser with no account still holds things worth erasing, and "sign out" is
// literally true for it too: what the erase ends is the BROWSER SESSION, which a
// guest has like anybody else.
//
// The list is not typed by hand. It is the registry of declared browser values
// (@hilos/core, browser/browserValue.ts) — the framework's own plus whatever the
// project hands in — and the confirmation names its entries one by one, so the
// person agrees to a list rather than to an adjective.
//
// THE ORDER IS LOAD-BEARING: the browser half runs first and the server half
// second. The rotation ticket the server answers with rides a cookie the
// framework writes into this browser, and that cookie is itself a registry entry
// — a sweep running after the dispatch would delete the one value the new session
// arrives on, silently, leaving the old cookie in place. The plainer reason is
// the second one: the browser half cannot fail and the server half can.
//
// The page carries no `title` prop: the heading moves with the frame, and the
// project's file holds prose alone. Nothing here is read at render time — a
// declaration is data and the sweep is a click handler — which is what lets
// /privacy be prerendered with this block whole and inert until the SPA mounts.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import {
  BROWSER_ERASE_ACTION,
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
  type ActionLifecycle,
  type HilosBrowserValue,
  type HilosConnection,
} from '@hilos/core'

import { HilosStaticPage } from '../HilosStaticPage.js'
import { useTrackedAction } from '../useTrackedAction.js'
import { HilosPrivacyEraseModal } from './HilosPrivacyEraseModal.js'

/** Props for {@link HilosPrivacyPage}. */
export interface HilosPrivacyPageProps {
  /** The connection whose session is ended, and whose in-memory pass is dropped. */
  connection: HilosConnection
  /** The reply lifecycle the erase is dispatched on — the app's one instance. */
  actions: ActionLifecycle
  /** The project's own declarations, swept beside the framework's. */
  values?: readonly HilosBrowserValue[]
  /** The project's own privacy prose, above the erase block. */
  children?: ReactNode
}

/**
 * The /privacy page: the project's prose, and under it the erase block with its
 * confirmation and the outcome it becomes.
 *
 * @param props The connection, the reply lifecycle, the project's own declared
 *   browser values, and its prose.
 */
export function HilosPrivacyPage({
  connection,
  actions,
  values = [],
  children,
}: HilosPrivacyPageProps) {
  /** Everything this browser is declared to hold: the framework's list plus the project's. */
  const registry = useMemo(() => [...HILOS_BROWSER_VALUES, ...values], [values])
  /** One line per registry entry, in declaration order, as the confirmation shows them. */
  const labels = useMemo(() => registry.map((value) => value.label), [registry])

  const erase = useTrackedAction()
  const [confirming, setConfirming] = useState(false)
  /** What the erase did, once it has run; null while the block is still an offer. */
  const [swept, setSwept] = useState<string[] | null>(null)

  // The modal is brought in only once there is a browser to hold it, exactly as
  // the licence page brings its own in: this page is also rendered at build time
  // by React's static server renderer, where HilosModal's own hooks throw.
  const [browserReady, setBrowserReady] = useState(false)
  useEffect(() => {
    setBrowserReady(true)
  }, [])

  async function onConfirm(): Promise<void> {
    if (erase.busy) {
      return
    }

    // 1. The browser half, first and synchronous. It cannot fail: a storage that
    // is absent or refuses is reported as nothing swept rather than as an error.
    const erased = eraseBrowserValues(registry, {
      sessionCookieName: connection.sessionCookieName,
    })

    // 2. The pass the connection also holds IN MEMORY and re-presents on every
    // reconnect. Erasing only its mirror in storage would buy the admission back
    // on the socket that comes next.
    connection.forgetProtectedModePass()

    // 3. The server half. The confirmation stays open with its button loading
    // until this settles, and only then does the block become the outcome —
    // either way, success or refusal. Drawing the outcome before the reply would
    // state that this browser's session was ended while the question was still on
    // the wire, and on a timeout it would state it wrongly for thirty seconds.
    await erase.run(actions.dispatch(BROWSER_ERASE_ACTION, {}))
    setConfirming(false)
    setSwept(erased)
  }

  return (
    <HilosStaticPage title="Privacy">
      {children}

      <h2 className="h6 text-uppercase text-body-secondary mb-2 mt-4">
        Erase everything stored on this device
      </h2>

      {swept === null ? (
        <>
          <p className="small text-body-secondary">
            Signs you out and clears every cookie and local value this site has
            kept &mdash; as if you had never opened it.
          </p>
          {/* Never disabled, in any state, including with the socket down: a
              control that is off and cannot say why is the defect HIL-661 exists
              to fix, and the browser half runs offline just as well. */}
          <button
            type="button"
            className="btn btn-outline-danger btn-sm"
            data-id="privacy-erase"
            onClick={() => setConfirming(true)}
          >
            <i className="bi bi-eraser me-1" aria-hidden="true" />
            Erase and sign out
          </button>
        </>
      ) : (
        <div data-id="privacy-erase-done">
          <p className="small mb-2">
            {swept.length} of the things this browser kept were erased.
          </p>
          <ul className="small text-body-secondary mb-2 ps-3">
            {swept.map((label) => (
              <li key={label}>{label}</li>
            ))}
          </ul>
          {erase.error === null ? (
            <p className="small mb-2">
              This browser&apos;s session was ended and a new one begun, so the
              sign-in cookie is not the one it arrived with. Other tabs come
              back on the new one, signed out.
            </p>
          ) : (
            <p className="small mb-2" data-id="privacy-erase-partial">
              The values above are gone, but this browser&apos;s session could
              not be ended: {erase.error}
            </p>
          )}
          <p className="small text-body-secondary mb-0">
            Nothing about your account changed. This is not account deletion.
          </p>
        </div>
      )}

      {browserReady ? (
        <HilosPrivacyEraseModal
          open={confirming}
          labels={labels}
          busy={erase.busy}
          loading={erase.loading}
          onClose={() => setConfirming(false)}
          onConfirm={onConfirm}
        />
      ) : null}
    </HilosStaticPage>
  )
}
