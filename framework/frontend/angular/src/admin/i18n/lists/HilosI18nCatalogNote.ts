// HilosI18nCatalogNote — the built-in catalog callout of the i18n section
// (HIL-112, HIL-1481). Drawn on the list pages (languages, countries) once the
// page answer carries the builtInCatalog tally. The heading tells the tallies the
// framework knows on its own; the page projects its explanatory text into the
// body. Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosI18nBuiltInCatalog } from '@hilos/core'

/** The built-in catalog callout above an i18n list. */
@Component({
  selector: 'hilos-i18n-catalog-note',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section
      class="alert alert-info small d-flex gap-2 align-items-start"
      [attr.data-id]="dataId()"
    >
      <i class="bi bi-database-check flex-shrink-0 mt-1" aria-hidden="true"></i>
      <div>
        <h2 class="h6 mb-1">
          The system knows {{ catalog().languageCount }} languages and
          {{ catalog().countryCount }} countries on its own
        </h2>
        <p class="mb-0"><ng-content /></p>
      </div>
    </section>
  `,
})
export class HilosI18nCatalogNote {
  /** The built-in catalog tally with language and country counts. */
  readonly catalog = input.required<HilosI18nBuiltInCatalog>()
  /** Test hook identifying the catalog callout. */
  readonly dataId = input.required<string>()
}
