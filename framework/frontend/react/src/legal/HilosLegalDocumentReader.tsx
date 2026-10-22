import type {
  HilosLegalPublicDocumentReader,
  HilosLegalPublicDocumentCopy,
} from '@hilos/core/base'
import { HilosFormError } from '../HilosFormError.js'
import { LoadingButton } from '../LoadingButton.js'

export interface HilosLegalDocumentReaderProps {
  line: HilosLegalPublicDocumentReader
  copy: HilosLegalPublicDocumentCopy
  accepting: boolean
  refusal: string | null
  onAccept: () => void
  onCompare: () => void
}

/** The shared public Terms/Privacy reader line; verdicts and words come from core. */
export function HilosLegalDocumentReader({
  line,
  copy: COPY,
  accepting,
  refusal,
  onAccept,
  onCompare,
}: HilosLegalDocumentReaderProps) {
  const hidden = line.state === 'loading'
  const green = line.state === 'covered' || line.state === 'reworded'
  const compareButton = line.canCompare && (
    <button
      type="button"
      className="btn btn-sm btn-outline-secondary"
      data-id="legal-document-changes-open"
      onClick={() => onCompare()}
    >
      {COPY.whatChanged}
    </button>
  )

  return (
    <>
      {line.state === 'due' ? (
        <div className="alert alert-warning mb-0">
          <p className="mb-2" data-id="legal-document-reader-line">
            <i
              className="bi bi-exclamation-triangle-fill me-1"
              aria-hidden="true"
            />
            {line.line}
          </p>
          {line.plate !== null && (
            <div
              className={`d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small bg-${line.plate.tone}-subtle text-${line.plate.tone}-emphasis`}
              data-id="legal-document-reader-plate"
              data-tone={line.plate.tone}
            >
              <i className={`bi ${line.plate.icon}`} aria-hidden="true" />
              <strong>{line.plate.text}</strong>
              {line.plate.detail !== null && <span>{line.plate.detail}</span>}
            </div>
          )}
          {line.onlyPerson !== null && (
            <p
              className="small mb-2"
              data-id="legal-document-reader-impersonated"
            >
              {line.onlyPerson}
            </p>
          )}
          <div className="d-flex flex-wrap gap-2">
            {line.canAccept && (
              <LoadingButton
                className="btn-sm btn-primary"
                data-id="legal-document-accept"
                loading={accepting}
                onClick={() => onAccept()}
              >
                {COPY.accept}
              </LoadingButton>
            )}
            {compareButton}
          </div>
          {line.canAccept && (
            <HilosFormError
              message={refusal}
              dataId="legal-document-accept-refusal"
              announce
            />
          )}
        </div>
      ) : (
        <div className="d-flex flex-wrap align-items-center gap-2">
          <p
            className={`mb-0${hidden ? ' invisible' : ''}`}
            data-id="legal-document-reader-line"
            aria-hidden={hidden}
          >
            {green && (
              <i
                className="bi bi-check-circle-fill text-success me-1"
                aria-hidden="true"
              />
            )}
            {line.line}
          </p>
          {compareButton}
        </div>
      )}
    </>
  )
}
