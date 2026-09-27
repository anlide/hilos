import type { HilosLegalChange, HilosLegalSide } from '@hilos/core'

export interface HilosLegalChangesProps {
  changes: HilosLegalChange[]
  fromLabel: string
  toLabel: string
}

function ClauseSide({ side }: { side: HilosLegalSide | null }) {
  if (!side) return <span className="text-body-secondary">Not present</span>
  return (
    <>
      <div className="small text-body-secondary">
        {side.source === 'standard'
          ? 'Hilos standard text'
          : 'Project deviation'}
      </div>
      <strong>{side.statement}.</strong>
      {side.text.split('\n\n').map((paragraph, index) => (
        <p key={index} className="mb-2">
          {paragraph}
        </p>
      ))}
      {side.direction && (
        <span className="small text-body-secondary">{side.direction}</span>
      )}
    </>
  )
}

/** Both responsive layouts retain the before and after sides inside each changed clause. */
export function HilosLegalChanges({
  changes,
  fromLabel,
  toLabel,
}: HilosLegalChangesProps) {
  return (
    <div data-id="legal-changes" className="text-break">
      {changes.length === 0 ? (
        <p className="text-body-secondary mb-0" data-id="legal-changes-empty">
          No clause changed
        </p>
      ) : (
        <>
          <div
            className="d-none d-md-block border rounded"
            data-id="legal-changes-wide"
          >
            <div className="row g-0 border-bottom small fw-semibold bg-body-tertiary">
              <div className="col-6 px-3 py-2 border-end">
                Before — revision {fromLabel}
              </div>
              <div className="col-6 px-3 py-2">After — revision {toLabel}</div>
            </div>
            {changes.map((change) => (
              <div
                key={change.clauseKey}
                className="row g-0 border-bottom"
                data-id="legal-change-row"
              >
                <div className="col-12 px-3 pt-2 small fw-semibold">
                  {change.title}
                  <span
                    className="badge text-bg-light border ms-1 text-capitalize"
                    data-id="legal-change-kind"
                  >
                    {change.kind}
                  </span>
                  <span className="text-body-secondary fw-normal ms-1">
                    {change.clauseKey}
                  </span>
                </div>
                <div className="col-6 px-3 py-2 border-end">
                  <ClauseSide side={change.before} />
                </div>
                <div className="col-6 px-3 py-2">
                  <ClauseSide side={change.after} />
                </div>
              </div>
            ))}
          </div>
          <div className="d-md-none" data-id="legal-changes-narrow">
            {changes.map((change) => (
              <div
                key={change.clauseKey}
                className="border rounded mb-2"
                data-id="legal-change-row"
              >
                <div className="px-3 py-2 border-bottom small fw-semibold bg-body-tertiary">
                  {change.title}
                  <span
                    className="badge text-bg-light border ms-1 text-capitalize"
                    data-id="legal-change-kind"
                  >
                    {change.kind}
                  </span>
                  <span className="text-body-secondary fw-normal ms-1">
                    {change.clauseKey}
                  </span>
                </div>
                <div className="px-3 py-2 border-bottom">
                  <div className="small fw-semibold">
                    Before — revision {fromLabel}
                  </div>
                  <ClauseSide side={change.before} />
                </div>
                <div className="px-3 py-2">
                  <div className="small fw-semibold">
                    After — revision {toLabel}
                  </div>
                  <ClauseSide side={change.after} />
                </div>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
