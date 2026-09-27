import type { HilosLegalClause } from '@hilos/core'

export interface HilosLegalRevisionTextProps {
  clauses: HilosLegalClause[]
}

/** Numbered effective clauses with the project's deviations identified. */
export function HilosLegalRevisionText({
  clauses,
}: HilosLegalRevisionTextProps) {
  return (
    <ol className="ps-4 mb-0 text-break" data-id="legal-revision-text">
      {clauses.map((clause) => (
        <li
          key={clause.clauseKey}
          className="mb-3"
          data-id="legal-revision-clause"
        >
          <strong>{clause.statement}.</strong>
          {clause.text.split('\n\n').map((paragraph, index) => (
            <p key={index} className="mb-2">
              {paragraph}
            </p>
          ))}
          {clause.source === 'deviation' && (
            <div
              className="small text-body-secondary"
              data-id="legal-revision-clause-deviation"
            >
              Project deviation from: {clause.standardStatement} ·{' '}
              {clause.direction}
            </div>
          )}
        </li>
      ))}
    </ol>
  )
}
