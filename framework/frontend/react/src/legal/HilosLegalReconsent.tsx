// HilosLegalReconsent — the "the terms have changed" screen (HIL-500), one
// component in three places: the window HilosLayout raises over the page on a
// sign-in while a document waits for a decision (variant "window"), the screen
// that takes the content's place once the deadline has passed and the account
// is frozen (variant "frozen"), and the administrator's read-only preview on a
// document's page (variant "preview"). One markup for the three, because two
// copies of it would drift apart exactly in the words.
//
// A section per document: its plate, the revision the person accepted, the list
// of what changed, and the way into the full text and the line-by-line
// comparison, both drawn inside the same body with "Back to the changes". "I do
// not accept" opens the refusal step in the body and sends nothing. The frozen
// variant keeps only "Accept" and lays the exits out as actions; under a
// takeover the acceptance is not offered at all. The state lives in the core
// store; the component draws it and reports what was pressed. Bootstrap classes
// only (styling-rules.md).
import { useId } from 'react'
import {
  describeHilosLegalReconsentAccepted,
  describeHilosLegalReconsentAddress,
  describeHilosLegalReconsentChange,
  describeHilosLegalReconsentPlate,
  describeHilosLegalReconsentRefusal,
  HILOS_PAGE_ROUTES,
  hilosLegalDocumentLabel,
  hilosLegalReconsentSections,
  HilosPages,
  keepMyAccount,
  LEGAL_RECONSENT_COPY,
  signOut,
  type HilosLegalReconsentContent,
  type HilosLegalReconsentPerson,
  type HilosLegalReconsentPreview,
  type HilosLegalReconsentVariant,
  type HilosLegalReconsentView,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosLink } from '../HilosLink.js'
import { LoadingButton } from '../LoadingButton.js'
import { useTrackedAction } from '../useTrackedAction.js'
import { HilosLegalChanges } from './HilosLegalChanges.js'
import { HilosLegalRevisionText } from './HilosLegalRevisionText.js'

const COPY = LEGAL_RECONSENT_COPY
const DATA_HREF = HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA] ?? '/'
const PROFILE_HREF = HILOS_PAGE_ROUTES[HilosPages.PROFILE] ?? '/'

export interface HilosLegalReconsentProps {
  /** Where the screen stands. */
  variant: HilosLegalReconsentVariant
  /** What was read, or null while it is read or after it failed. */
  content: HilosLegalReconsentContent | HilosLegalReconsentPreview | null
  /** What the body shows. */
  view: HilosLegalReconsentView
  /** Whether the content is being read. */
  loading?: boolean
  /** The refusal to show, or null. */
  error?: string | null
  /** Whether the acceptance is in flight. */
  busy?: boolean
  /** The person the screen speaks to; null in the preview. */
  person?: HilosLegalReconsentPerson | null
  /** Whether the person's deletion is scheduled, which offers "Keep my account" on the freeze. */
  deletionScheduled?: boolean
  /** The moment the days are counted from, LOCAL epoch ms. */
  now?: number
  onAccept?: () => void
  onLater?: () => void
  onRetry?: () => void
  onShow?: (view: HilosLegalReconsentView) => void
}

/** The "the terms have changed" screen: window, freeze screen or admin preview. */
export function HilosLegalReconsent({
  variant,
  content,
  view,
  loading = false,
  error = null,
  busy = false,
  person = null,
  deletionScheduled = false,
  now = Date.now(),
  onAccept,
  onLater,
  onRetry,
  onShow,
}: HilosLegalReconsentProps) {
  const headingId = useId()
  const signOutAction = useTrackedAction()
  const keepAction = useTrackedAction()
  const sections = content === null ? [] : hilosLegalReconsentSections(content)
  const rows = sections.map((section) => ({
    section,
    plate: describeHilosLegalReconsentPlate(section, variant, now),
    accepted: describeHilosLegalReconsentAccepted(section),
    changes: section.changes.map(describeHilosLegalReconsentChange),
  }))
  const firstRevision =
    variant === 'preview' && sections.length === 1 && sections[0]!.held === null
  const impersonated = person?.impersonated === true
  const viewed =
    view.kind === 'text' || view.kind === 'compare'
      ? (sections.find((item) => item.document === view.document) ?? null)
      : null
  const refusal =
    content !== null && 'refusal' in content
      ? describeHilosLegalReconsentRefusal(
          content as HilosLegalReconsentContent,
        )
      : COPY.refuseRemind
  const acceptOffered = variant !== 'frozen' || !impersonated
  const acceptDisabled =
    variant === 'preview' ||
    content === null ||
    sections.length === 0 ||
    loading ||
    busy
  const onSignOut = (): void => {
    if (signOutAction.busy) return
    void signOutAction.run(signOut())
  }
  const onKeepAccount = (): void => {
    if (keepAction.busy) return
    void keepAction.run(keepMyAccount())
  }
  const address = describeHilosLegalReconsentAddress(content)

  return (
    <section
      data-id="legal-reconsent"
      data-variant={variant}
      aria-labelledby={headingId}
    >
      {variant !== 'preview' && person !== null && (
        <div
          className="d-flex flex-wrap align-items-center gap-2 border rounded px-3 py-2 mb-3 small"
          data-id="legal-reconsent-person"
        >
          <i
            className="bi bi-person-circle text-body-secondary"
            aria-hidden="true"
          />
          <div className="lh-sm text-break">
            <div className="fw-semibold">{person.name}</div>
            {address !== null && (
              <div
                className="text-body-secondary small"
                data-id="legal-reconsent-address"
              >
                {address}
              </div>
            )}
          </div>
          {!person.impersonated && (
            <LoadingButton
              className="btn-link btn-sm p-0 ms-auto"
              data-id="legal-reconsent-not-you"
              loading={signOutAction.busy}
              onClick={onSignOut}
            >
              {COPY.notYou}
            </LoadingButton>
          )}
        </div>
      )}
      <h2 id={headingId} className="h5 mb-3" data-id="legal-reconsent-heading">
        {variant === 'frozen' ? COPY.frozenHeading : COPY.heading}
      </h2>
      <div className="visually-hidden" role="status" aria-live="polite">
        {error ?? (loading ? 'Loading terms…' : '')}
      </div>
      {loading && (
        <div
          className="placeholder-glow mb-3"
          data-id="legal-reconsent-loading"
          aria-busy="true"
        >
          <span className="placeholder col-12" aria-hidden="true" />
          <span className="placeholder col-9" aria-hidden="true" />
          <span className="placeholder col-10" aria-hidden="true" />
        </div>
      )}
      {firstRevision ? (
        <p
          className="text-body-secondary mb-0"
          data-id="legal-reconsent-preview-first"
        >
          {COPY.previewFirst}
        </p>
      ) : content !== null && view.kind === 'changes' ? (
        <>
          {rows.map((row) => (
            <section
              key={row.section.document}
              className="mb-3"
              data-id="legal-reconsent-document"
              data-document={row.section.document}
            >
              <h3 className="h6 mb-2">
                {hilosLegalDocumentLabel(row.section.document)}
              </h3>
              {row.plate !== null && (
                <div
                  className={`d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small bg-${row.plate.tone}-subtle text-${row.plate.tone}-emphasis`}
                  data-id="legal-reconsent-badge"
                  data-tone={row.plate.tone}
                >
                  <i className={`bi ${row.plate.icon}`} aria-hidden="true" />
                  <strong>{row.plate.text}</strong>
                  {row.plate.detail !== null && <span>{row.plate.detail}</span>}
                </div>
              )}
              <p className="small text-body-secondary mb-2">{row.accepted}</p>
              <ul className="list-unstyled mb-2">
                {row.changes.map((change) => (
                  <li
                    key={change.clauseKey}
                    className="d-flex gap-2 py-2 border-bottom text-break"
                    data-id="legal-reconsent-change"
                    data-clause={change.clauseKey}
                  >
                    <i
                      className={`bi text-body-secondary mt-1 ${change.icon}`}
                      aria-hidden="true"
                    />
                    <div className="flex-grow-1">
                      <div className="small fw-semibold">
                        {change.statement}{' '}
                        <span
                          className="badge text-bg-light border ms-1"
                          data-id="legal-reconsent-change-kind"
                        >
                          {change.kind}
                        </span>
                      </div>
                      {change.before !== null && (
                        <div className="small text-body-secondary">
                          {change.before}
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              <div className="small">
                <button
                  type="button"
                  className="btn btn-link btn-sm p-0"
                  data-id="legal-reconsent-full-text"
                  data-document={row.section.document}
                  onClick={() =>
                    onShow?.({ kind: 'text', document: row.section.document })
                  }
                >
                  {COPY.fullText}
                </button>
                {row.section.held !== null && (
                  <>
                    {' · '}
                    <button
                      type="button"
                      className="btn btn-link btn-sm p-0"
                      data-id="legal-reconsent-compare"
                      data-document={row.section.document}
                      onClick={() =>
                        onShow?.({
                          kind: 'compare',
                          document: row.section.document,
                        })
                      }
                    >
                      {COPY.compare}
                    </button>
                  </>
                )}
              </div>
            </section>
          ))}
          {variant === 'frozen' && (
            <section
              className="border rounded px-3 py-2 mb-3"
              data-id="legal-reconsent-exits"
            >
              <h3 className="h6">{COPY.freezeKeeps}</h3>
              <div className="d-flex flex-wrap gap-2">
                <HilosLink
                  to={DATA_HREF}
                  className="btn btn-sm btn-outline-secondary"
                  data-id="legal-reconsent-data-link"
                >
                  <i className="bi bi-download me-1" aria-hidden="true" />
                  {COPY.dataLink}
                </HilosLink>
                {deletionScheduled && !impersonated && (
                  <LoadingButton
                    className="btn-sm btn-outline-secondary"
                    data-id="legal-reconsent-keep-account"
                    loading={keepAction.busy}
                    onClick={onKeepAccount}
                  >
                    {COPY.keepAccount}
                  </LoadingButton>
                )}
                <LoadingButton
                  className="btn-sm btn-outline-secondary"
                  data-id="legal-reconsent-sign-out"
                  loading={signOutAction.busy}
                  onClick={onSignOut}
                >
                  {COPY.signOut}
                </LoadingButton>
              </div>
            </section>
          )}
          {variant === 'frozen' && impersonated && person !== null && (
            <p
              className="small text-body-secondary"
              data-id="legal-reconsent-impersonated"
            >
              {COPY.onlyPerson.replace('{name}', person.name)}
            </p>
          )}
        </>
      ) : viewed !== null && view.kind === 'text' ? (
        <div>
          <h3 className="h6">{hilosLegalDocumentLabel(viewed.document)}</h3>
          <HilosLegalRevisionText clauses={[...viewed.clauses]} />
        </div>
      ) : viewed !== null && view.kind === 'compare' ? (
        <div>
          <h3 className="h6">{hilosLegalDocumentLabel(viewed.document)}</h3>
          <HilosLegalChanges
            changes={[...viewed.changes]}
            fromLabel={viewed.held?.revisionId ?? ''}
            toLabel={viewed.current.revisionId}
          />
        </div>
      ) : content !== null && view.kind === 'refuse' ? (
        <div data-id="legal-reconsent-refuse-step">
          <p>{refusal}</p>
          <div className="d-flex flex-wrap gap-3 small mb-3">
            <HilosLink
              to={DATA_HREF}
              data-id="legal-reconsent-data-link"
              onClick={() => onLater?.()}
            >
              {COPY.dataLink}
            </HilosLink>
            <HilosLink
              to={PROFILE_HREF}
              className="link-danger"
              data-id="legal-reconsent-delete-link"
              onClick={() => onLater?.()}
            >
              {COPY.deleteLink}
            </HilosLink>
          </div>
        </div>
      ) : null}
      {viewed !== null && (
        <button
          type="button"
          className="btn btn-link btn-sm px-0 mb-3"
          data-id="legal-reconsent-back"
          onClick={() => onShow?.({ kind: 'changes' })}
        >
          {COPY.backToChanges}
        </button>
      )}
      <HilosFormError message={error} dataId="legal-reconsent-error" />
      {!firstRevision && (
        <div className="d-flex flex-wrap justify-content-end gap-2 mt-2">
          {error !== null && content === null && (
            <button
              type="button"
              className="btn btn-outline-primary"
              data-id="legal-reconsent-retry"
              onClick={() => onRetry?.()}
            >
              {COPY.retry}
            </button>
          )}
          {view.kind === 'refuse' ? (
            <>
              <button
                type="button"
                className="btn btn-outline-secondary"
                data-id="legal-reconsent-back"
                onClick={() => onShow?.({ kind: 'changes' })}
              >
                {COPY.back}
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                data-id="legal-reconsent-close"
                onClick={() => onLater?.()}
              >
                {COPY.close}
              </button>
            </>
          ) : (
            <>
              {variant !== 'frozen' && (
                <button
                  type="button"
                  className="btn btn-outline-danger"
                  data-id="legal-reconsent-refuse"
                  disabled={variant === 'preview' || content === null}
                  onClick={() => onShow?.({ kind: 'refuse' })}
                >
                  {COPY.refuse}
                </button>
              )}
              {variant !== 'frozen' && (
                <button
                  type="button"
                  className="btn btn-outline-secondary"
                  data-id="legal-reconsent-later"
                  disabled={variant === 'preview'}
                  onClick={() => onLater?.()}
                >
                  {COPY.later}
                </button>
              )}
              {acceptOffered && (
                <LoadingButton
                  className="btn-primary"
                  data-id="legal-reconsent-accept"
                  loading={busy}
                  disabled={acceptDisabled}
                  onClick={() => onAccept?.()}
                >
                  {COPY.accept}
                </LoadingButton>
              )}
            </>
          )}
        </div>
      )}
    </section>
  )
}
