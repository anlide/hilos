import type { ReactNode } from 'react'
import type { HilosI18nBuiltInCatalog } from '@hilos/core'

/** The list's built-in catalog tallies, with the page's explanation. */
export function HilosI18nCatalogNote({
  catalog,
  dataId,
  children,
}: {
  catalog: HilosI18nBuiltInCatalog
  dataId: string
  children: ReactNode
}) {
  return (
    <section
      className="alert alert-info small d-flex gap-2 align-items-start"
      data-id={dataId}
    >
      <i
        className="bi bi-database-check flex-shrink-0 mt-1"
        aria-hidden="true"
      />
      <div>
        <h2 className="h6 mb-1">
          The system knows {catalog.languageCount} languages and{' '}
          {catalog.countryCount} countries on its own
        </h2>
        <p className="mb-0">{children}</p>
      </div>
    </section>
  )
}
