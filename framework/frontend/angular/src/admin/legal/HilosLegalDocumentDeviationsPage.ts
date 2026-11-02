import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  createHilosLegalDocumentDeviationsTable,
  HilosLegalRowKey,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
} from '@hilos/angular/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'

/** The project deviations for one legal document. */
@Component({
  selector: 'hilos-legal-document-deviations-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentPageShell, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-legal-document-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <hilos-viewport-table [controller]="table().controller">
          <ng-template [hilosTableCell]="keys.statement" let-row>
            <div class="fw-semibold">{{ row.statement }}</div>
            <p class="small text-body-secondary">
              Standard: {{ row.standardStatement }}
            </p>
            @for (paragraph of row.text.split('\\n\\n'); track $index) {
              <p class="small mb-2">{{ paragraph }}</p>
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.direction" let-row>
            @if (row.direction) {
              <span class="badge text-bg-secondary">{{ row.direction }}</span>
            } @else {
              <span class="text-body-secondary">—</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.clauseKey" let-row
            ><code>{{ row.clauseKey }}</code></ng-template
          >
        </hilos-viewport-table>
      </ng-template>
    </hilos-legal-document-page-shell>
  `,
})
export class HilosLegalDocumentDeviationsPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()

  protected readonly page = HilosPages.LEGAL_DOCUMENT_DEVIATIONS
  protected readonly keys = HilosLegalRowKey
  private readonly router = inject(HILOS_ROUTER)
  private readonly documentKey = computedSignal(() =>
    String(this.router.currentRoute.get().params['documentKey'] ?? ''),
  )
  protected readonly table = computed(() =>
    createHilosLegalDocumentDeviationsTable(this.context(), this.documentKey),
  )

  constructor() {
    effect((onCleanup) => {
      const table = this.table()
      table.start()
      onCleanup(() => {
        table.dispose()
        table.controller.dispose()
      })
    })
  }
}
