import type { HilosI18nLegendRow } from '@hilos/core'

/** The shared explanation of the marks below an i18n list. */
export function HilosI18nMarksLegend({
  rows,
  dataId,
  className,
}: {
  rows: readonly HilosI18nLegendRow[]
  dataId: string
  className?: string
}) {
  return (
    <section data-id={dataId} className={className}>
      <h2 className="small text-body-secondary mb-2">
        <i className="bi bi-info-circle me-1" aria-hidden="true" />
        What the marks mean
      </h2>
      <dl className="row row-cols-1 row-cols-md-2 g-1 mb-0 small">
        {rows.map((row) => (
          <div
            key={row.key}
            className="col d-flex gap-2 align-items-baseline"
            data-id={`${dataId}-${row.key}`}
          >
            <dt className="fw-normal">
              <span
                className={`badge me-1 ${row.mark.tone}`}
                title={row.mark.label}
                aria-hidden="true"
              >
                <i className={`bi ${row.mark.icon}`} />
              </span>
              {row.mark.label}
            </dt>
            <dd className="mb-0 text-body-secondary">{row.meaning}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}
