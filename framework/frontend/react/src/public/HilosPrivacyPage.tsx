// The public Privacy page reads the policy and the reader's standing from one
// page answer. The project supplies an optional introduction. The browser erase
// stays available without a policy or a socket; its result belongs to the erase,
// independently of legal refreshes and of the new guest session.
// The browser values are erased synchronously before forgetting the in-memory
// protected-mode pass and dispatching the server erase. Nothing starts on render.
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import {
  BROWSER_ERASE_ACTION,
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
  createHilosLegalPrivacyStore,
  describeHilosLegalPrivacyReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_PRIVACY_COPY,
  LEGAL_RECONSENT_COPY,
  sessionAccountStanding,
  type HilosLegalContext,
  type HilosBrowserValue,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalDocumentReader } from '../legal/HilosLegalDocumentReader.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { useSignal } from '../useSignal.js'

const COPY = LEGAL_PRIVACY_COPY
import { useTrackedAction } from '../useTrackedAction.js'
import { HilosPrivacyEraseModal } from './HilosPrivacyEraseModal.js'

/** Props for {@link HilosPrivacyPage}. */
export interface HilosPrivacyPageProps {
  /** The connection, scopes and action lifecycle for public reading and browser erase. */
  context: HilosLegalContext
  /** The project's own declarations, swept beside the framework's. */
  values?: readonly HilosBrowserValue[]
  /** The project's own privacy prose, above the policy text. */
  children?: ReactNode
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}

/**
 * The public Privacy reader, policy text and browser erase with its outcome.
 *
 * @param props The legal context, the project's own declared browser values
 *   and prose, and optional public-page navigation.
 */
export function HilosPrivacyPage({
  context,
  values = [],
  children,
  navigation,
}: HilosPrivacyPageProps) {
  const store = useMemo(() => createHilosLegalPrivacyStore(context), [context])
  const standingSignal = useMemo(
    () => sessionAccountStanding(context.scopes),
    [context],
  )
  useEffect(() => {
    setComparing(false)
    const stop = store.start()
    return () => {
      stop()
    }
  }, [store])
  const privacy = useSignal(store.privacy)
  const agreement = useSignal(store.agreement)
  const accepting = useSignal(store.accepting)
  const refusal = useSignal(store.refusal)
  const person = useSignal(hilosLegalReconsentPerson)
  const standing = useSignal(standingSignal)
  const [comparing, setComparing] = useState(false)

  const line = describeHilosLegalPrivacyReader(privacy, agreement, {
    person,
    frozen: standing?.frozen === true,
    now: Date.now(),
  })
  const changes =
    comparing && line.canCompare ? (privacy?.changes ?? null) : null
  // A comparison that has nothing left to show - accepted, or the revision moved - is closed, not parked.
  useEffect(() => {
    if (changes === null) setComparing(false)
  }, [changes])

  /**
   * The publication day of a revision the page lists, or its id when it lists none.
   *
   * @param revisionId The revision.
   */
  const publishedOf = (revisionId: string): string => {
    const revision = privacy?.revisions.find(
      (item) => item.revisionId === revisionId,
    )

    return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
  }
  const onAccept = async (): Promise<void> => {
    if (line.canAccept && (await store.accept())) setComparing(false)
  }

  /** Everything this browser is declared to hold: the framework's list plus the project's. */
  const registry = useMemo(() => [...HILOS_BROWSER_VALUES, ...values], [values])
  /** One line per registry entry, in declaration order, as the confirmation shows them. */
  const labels = useMemo(() => registry.map((value) => value.label), [registry])

  const erase = useTrackedAction()
  const [confirming, setConfirming] = useState(false)
  /** What the erase did, once it has run; null while the block is still an offer. */
  const [swept, setSwept] = useState<string[] | null>(null)

  async function onConfirm(): Promise<void> {
    if (erase.busy) {
      return
    }

    // 1. The browser half, first and synchronous. It cannot fail: a storage that
    // is absent or refuses is reported as nothing swept rather than as an error.
    const erased = eraseBrowserValues(registry, {
      sessionCookieName: context.connection.sessionCookieName,
    })

    // 2. The pass the connection also holds IN MEMORY and re-presents on every
    // reconnect. Erasing only its mirror in storage would buy the admission back
    // on the socket that comes next.
    context.connection.forgetProtectedModePass()

    // 3. The server half. The confirmation stays open with its button loading
    // until this settles, and only then does the block become the outcome —
    // either way, success or refusal. Drawing the outcome before the reply would
    // state that this browser's session was ended while the question was still on
    // the wire, and on a timeout it would state it wrongly for thirty seconds.
    await erase.run(context.actions.dispatch(BROWSER_ERASE_ACTION, {}))
    setConfirming(false)
    setSwept(erased)
  }

  return (
    <HilosStaticPage
      title="Privacy"
      icon="bi-shield-lock"
      subtitle="What this project keeps about you: the revision in force, every one before it, and wiping this browser."
      navigation={navigation}
    >
      {line.state !== 'unpublished' && (
        <div className="mb-4" data-id="privacy-reader" data-state={line.state}>
          <HilosLegalDocumentReader
            line={line}
            copy={COPY}
            accepting={accepting}
            refusal={comparing ? null : refusal}
            onAccept={() => void onAccept()}
            onCompare={() => setComparing(true)}
          />
        </div>
      )}

      {children}

      {privacy === undefined ? (
        <p className="text-body-secondary" data-id="privacy-loading">
          {COPY.loading}
        </p>
      ) : privacy === null ? (
        <p className="text-body-secondary" data-id="privacy-none">
          {COPY.nonePublished}
        </p>
      ) : (
        <div data-id="privacy-text">
          <HilosLegalRevisionText clauses={privacy.clauses} />
        </div>
      )}

      <section className="border rounded p-3 mt-4 mb-3">
        <h2 className="h6 text-uppercase text-body-secondary mb-2">
          Erase everything stored on this device
        </h2>

        {swept === null && (
          <>
            <p className="small text-body-secondary">
              Signs you out and clears every cookie and local value this site
              has kept &mdash; as if you had never opened it.
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
        )}
        <div role="status" aria-live="polite">
          {swept !== null && (
            <div
              data-id="privacy-erase-done"
              className={`alert mb-0 ${erase.error === null ? 'alert-success' : 'alert-warning'}`}
            >
              <p className="small mb-2">
                {swept.length} of the things this browser kept were erased.
              </p>
              <ul className="small mb-2 ps-3">
                {swept.map((label) => (
                  <li key={label}>{label}</li>
                ))}
              </ul>
              {erase.error === null ? (
                <p className="small mb-2">
                  This browser&apos;s session was ended and a new one begun, so
                  the sign-in cookie is not the one it arrived with. Other tabs
                  come back on the new one, signed out.
                </p>
              ) : (
                <p className="small mb-2" data-id="privacy-erase-partial">
                  The values above are gone, but this browser&apos;s session
                  could not be ended: {erase.error}
                </p>
              )}
              <p className="small mb-0">
                Nothing about your account changed. This is not account
                deletion.
              </p>
            </div>
          )}
        </div>
      </section>

      <HilosPrivacyEraseModal
        open={confirming}
        labels={labels}
        busy={erase.busy}
        onClose={() => setConfirming(false)}
        onConfirm={onConfirm}
      />
      <HilosModal
        open={changes !== null}
        busy={accepting}
        title={
          changes === null
            ? ''
            : COPY.changesTitle
                .replace('{from}', publishedOf(changes.fromRevisionId))
                .replace('{to}', publishedOf(changes.toRevisionId))
        }
        initialFocus="dialog"
        size="wide"
        onClose={() => setComparing(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="privacy-changes-close"
              disabled={accepting}
              onClick={requestClose}
            >
              {LEGAL_RECONSENT_COPY.close}
            </button>
            {line.canAccept && (
              <LoadingButton
                className="btn-primary"
                data-id="privacy-changes-accept"
                loading={accepting}
                onClick={() => void onAccept()}
              >
                {LEGAL_RECONSENT_COPY.accept}
              </LoadingButton>
            )}
          </>
        )}
      >
        {changes && (
          <div data-id="privacy-changes-modal">
            <HilosLegalChanges
              changes={changes.changes}
              fromLabel={publishedOf(changes.fromRevisionId)}
              toLabel={publishedOf(changes.toRevisionId)}
            />
            {line.canAccept && (
              <div className="mt-3">
                <HilosFormError
                  message={refusal}
                  dataId="privacy-changes-refusal"
                  announce
                />
              </div>
            )}
          </div>
        )}
      </HilosModal>
    </HilosStaticPage>
  )
}
