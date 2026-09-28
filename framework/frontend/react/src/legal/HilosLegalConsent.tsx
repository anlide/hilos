import {
  Fragment,
  useEffect,
  useId,
  useImperativeHandle,
  useRef,
  useState,
  type Ref,
} from 'react'
import {
  hilosLegalClauseIcon,
  hilosLegalConsentDeviations,
  hilosLegalDocumentLabel,
  type HilosLegalConsentTerms,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import { HilosLegalRevisionText } from './HilosLegalRevisionText.js'

/** The same document body serves live registration and its read-only admin preview. */
export function HilosLegalConsent({
  terms,
  accepted,
  reading,
  disabled = false,
  onAcceptedChange,
  onReadingChange,
  ref,
}: {
  terms: HilosLegalConsentTerms
  accepted: boolean
  reading: HilosLegalDocumentKey | null
  disabled?: boolean
  onAcceptedChange: (accepted: boolean) => void
  onReadingChange: (document: HilosLegalDocumentKey | null) => void
  ref?: Ref<{ focus(): void }>
}) {
  const [expanded, setExpanded] = useState(false)
  const checkboxId = useId()
  const acceptInput = useRef<HTMLInputElement>(null)
  const standardToggle = useRef<HTMLButtonElement>(null)
  const readingHeading = useRef<HTMLHeadingElement>(null)
  const previousReading = useRef<HilosLegalDocumentKey | null>(null)
  const clauses = terms.documents.flatMap((document) => document.clauses)
  const deviations = hilosLegalConsentDeviations(terms)
  const document = terms.documents.find((item) => item.document === reading)
  useImperativeHandle(
    ref,
    () => ({
      focus: () => {
        if (reading !== null) readingHeading.current?.focus()
        else (acceptInput.current ?? standardToggle.current)?.focus()
      },
    }),
    [reading],
  )
  useEffect(() => {
    if (reading === previousReading.current) return
    previousReading.current = reading
    if (reading !== null) readingHeading.current?.focus()
    else standardToggle.current?.focus()
  }, [reading])

  return (
    <div data-id="legal-consent">
      <div hidden={document !== undefined}>
        <p className="small text-body-secondary mb-3">
          This project runs on the standard Hilos terms. They are the same in
          every project built on the framework — you may have read them before.
          {deviations.length > 0 &&
            ' Below is only what this project does differently.'}
        </p>
        <div className="border rounded mb-3">
          <button
            ref={standardToggle}
            type="button"
            className="btn w-100 d-flex align-items-center gap-2 px-3 py-2 small text-start"
            data-id="legal-consent-standard-toggle"
            aria-expanded={expanded}
            onClick={() => setExpanded(!expanded)}
          >
            <i className="bi bi-shield-check text-success" aria-hidden="true" />
            <span className="flex-grow-1">
              Standard Hilos terms · {clauses.length} clauses
            </span>
            <i
              className={expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down'}
              aria-hidden="true"
            />
          </button>
          {expanded && (
            <div className="border-top">
              {clauses.map((clause) => (
                <div
                  key={clause.clauseKey}
                  className="px-3 py-2 border-bottom small text-body-secondary text-break"
                  data-id="legal-consent-standard-item"
                >
                  {clause.standardStatement}
                </div>
              ))}
            </div>
          )}
        </div>
        {deviations.length > 0 ? (
          <section className="border border-warning-subtle rounded mb-3">
            <h3 className="h6 d-flex align-items-center gap-2 px-3 py-2 mb-0 border-bottom bg-warning-subtle text-warning-emphasis">
              <i className="bi bi-exclamation-triangle" aria-hidden="true" />
              Different in this project · {deviations.length}
            </h3>
            <ul className="list-unstyled mb-0 px-3">
              {deviations.map((clause) => (
                <li
                  key={clause.clauseKey}
                  className="d-flex gap-2 py-2 border-bottom text-break"
                  data-id="legal-consent-deviation"
                  data-clause={clause.clauseKey}
                >
                  <i
                    className={`bi text-body-secondary mt-1 ${hilosLegalClauseIcon(clause.clauseKey)}`}
                    aria-hidden="true"
                  />
                  <div className="flex-grow-1">
                    <div className="small fw-semibold">
                      {clause.statement}{' '}
                      <span
                        className={`badge border ms-1 ${
                          clause.direction === 'stricter'
                            ? 'text-bg-warning-subtle bg-warning-subtle text-warning-emphasis border-warning-subtle'
                            : 'text-bg-success-subtle bg-success-subtle text-success-emphasis border-success-subtle'
                        }`}
                        data-id="legal-consent-direction"
                      >
                        {clause.direction}
                      </span>
                    </div>
                    <div className="small text-body-secondary">
                      Hilos standard: {clause.standardStatement}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        ) : (
          <div
            className="alert alert-success small py-2 mb-3"
            data-id="legal-consent-no-deviations"
          >
            <i className="bi bi-check-circle me-1" aria-hidden="true" />
            This project does not differ from the standard — neither stricter
            nor looser. Only the standard terms need accepting.
          </div>
        )}
        {terms.form === 'checkbox' && (
          <>
            <div className="form-check mb-1">
              <input
                id={checkboxId}
                ref={acceptInput}
                type="checkbox"
                className="form-check-input"
                data-id="auth-consent-accept"
                checked={accepted}
                disabled={disabled}
                onChange={(event) => onAcceptedChange(event.target.checked)}
              />
              <label htmlFor={checkboxId} className="form-check-label small">
                {deviations.length
                  ? 'I have read the differences and accept the terms'
                  : 'I accept the terms'}
              </label>
            </div>
            <div className="small mb-3">
              {terms.documents.map((item, index) => (
                <Fragment key={item.document}>
                  {index > 0 && ' · '}
                  <button
                    type="button"
                    className="btn btn-link btn-sm p-0"
                    data-id="legal-consent-read"
                    data-document={item.document}
                    onClick={() => onReadingChange(item.document)}
                  >
                    {hilosLegalDocumentLabel(item.document)}
                  </button>
                </Fragment>
              ))}
            </div>
          </>
        )}
      </div>
      {document && (
        <div data-id="legal-consent-reading">
          <h3 ref={readingHeading} tabIndex={-1} className="h6">
            {hilosLegalDocumentLabel(document.document)}
          </h3>
          <HilosLegalRevisionText clauses={document.clauses} />
          <button
            type="button"
            className="btn btn-link btn-sm px-0 mb-3"
            data-id="legal-consent-back"
            onClick={() => onReadingChange(null)}
          >
            Back to the differences
          </button>
        </div>
      )}
    </div>
  )
}
