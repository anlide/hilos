import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosLegalClause } from '@hilos/core'

/** Numbered effective clauses and the origin of each project deviation. */
@Component({
  selector: 'hilos-legal-revision-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ol class="ps-4 mb-0 text-break" data-id="legal-revision-text">
      @for (clause of clauses(); track clause.clauseKey) {
        <li class="mb-3" data-id="legal-revision-clause">
          <strong>{{ clause.statement }}.</strong>
          @for (paragraph of clause.text.split('\\n\\n'); track $index) {
            <p class="mb-2">{{ paragraph }}</p>
          }
          @if (clause.source === 'deviation') {
            <div
              class="small text-body-secondary"
              data-id="legal-revision-clause-deviation"
            >
              Project deviation from: {{ clause.standardStatement }} ·
              {{ clause.direction }}
            </div>
          }
        </li>
      }
    </ol>
  `,
})
export class HilosLegalRevisionText {
  readonly clauses = input.required<HilosLegalClause[]>()
}
