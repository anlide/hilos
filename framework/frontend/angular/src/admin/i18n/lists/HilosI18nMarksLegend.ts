// HilosI18nMarksLegend — the marks legend of the i18n section (HIL-112,
// HIL-1481): what each badge on the section's tables means. The mark is drawn
// beside its label, so no meaning rests on color alone. The host is a block, so
// the spacing class a page puts on it applies. Bootstrap classes only, following
// WCAG 2.1 AA (accessibility.md, styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosI18nLegendRow } from '@hilos/core'

/** The legend explaining the marks of an i18n table. */
@Component({
  selector: 'hilos-i18n-marks-legend',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'd-block' },
  template: `
    <section [attr.data-id]="dataId()">
      <h2 class="small text-body-secondary mb-2">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>What the marks
        mean
      </h2>
      <dl class="row row-cols-1 row-cols-md-2 g-1 mb-0 small">
        @for (row of rows(); track row.key) {
          <div
            class="col d-flex gap-2 align-items-baseline"
            [attr.data-id]="dataId() + '-' + row.key"
          >
            <dt class="fw-normal">
              <span
                [class]="'badge me-1 ' + row.mark.tone"
                [title]="row.mark.label"
                aria-hidden="true"
              >
                <i [class]="'bi ' + row.mark.icon"></i>
              </span>
              {{ row.mark.label }}
            </dt>
            <dd class="mb-0 text-body-secondary">{{ row.meaning }}</dd>
          </div>
        }
      </dl>
    </section>
  `,
})
export class HilosI18nMarksLegend {
  /** Rows explaining the marks used in the table. */
  readonly rows = input.required<readonly HilosI18nLegendRow[]>()
  /** Base test hook identifier for the legend section. */
  readonly dataId = input.required<string>()
}
