import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalDocument,
  createHilosLegalDocumentSetTable,
  HilosLegalRowKey,
  type HilosLegalAdminDocument,
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
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'

/** The adopted standard clauses for one legal document. */
@Component({
  selector: 'hilos-legal-document-set-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentPageShell, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-legal-document-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (details(); as data) {
          @if (data.set; as set) {
            <p class="small text-body-secondary" data-id="legal-set-metadata">
              Set {{ set.version }} from {{ set.publishedOn }} ·
              {{ set.significance }}
            </p>
          }
          <hilos-viewport-table [controller]="table().controller">
            <ng-template [hilosTableCell]="keys.clauseKey" let-row
              ><code>{{ row.clauseKey }}</code></ng-template
            >
            <ng-template [hilosTableCell]="keys.statement" let-row>{{
              row.statement
            }}</ng-template>
          </hilos-viewport-table>
        }
      </ng-template>
    </hilos-legal-document-page-shell>
  `,
})
export class HilosLegalDocumentSetPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()

  protected readonly page = HilosPages.LEGAL_DOCUMENT_SET
  protected readonly keys = HilosLegalRowKey
  protected readonly details = signal<
    HilosLegalAdminDocument | null | undefined
  >(undefined)

  private readonly router = inject(HILOS_ROUTER)
  private readonly documentKey = computedSignal(() =>
    String(this.router.currentRoute.get().params['documentKey'] ?? ''),
  )
  private readonly document = computed(() =>
    createHilosLegalDocument(this.context()),
  )
  protected readonly table = computed(() =>
    createHilosLegalDocumentSetTable(this.context(), this.documentKey),
  )

  constructor() {
    effect((onCleanup) => {
      const details = this.document()
      const table = this.table()
      const offDetails = mirrorHilosSignal(details, this.details)
      table.start()
      onCleanup(() => {
        offDetails()
        table.dispose()
        table.controller.dispose()
      })
    })
  }
}
