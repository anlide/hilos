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
  createHilosLegalRevisionsTable,
  HilosLegalRowKey,
  type HilosLegalAdminDocument,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  HILOS_TABLE_ACTIONS_KEY,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'

/** The declared and recorded revision history for one legal document. */
@Component({
  selector: 'hilos-legal-document-revisions-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosLegalDocumentPageShell,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-legal-document-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (details(); as data) {
          <hilos-viewport-table [controller]="table().controller">
            <ng-template [hilosTableCell]="keys.publishedOn" let-row>
              <div class="fw-semibold">{{ row.publishedOn ?? '—' }}</div>
              <div class="small text-body-secondary">{{ row.rowKey }}</div>
              @if (row.current) {
                <span class="badge text-bg-primary">Current</span>
              }
              @if (!row.declared) {
                <span class="badge text-bg-warning">Not in code</span>
              }
            </ng-template>
            <ng-template [hilosTableCell]="keys.significance" let-row>{{
              row.significance ?? '—'
            }}</ng-template>
            <ng-template [hilosTableCell]="keys.effectiveOn" let-row>{{
              row.effectiveOn ?? '—'
            }}</ng-template>
            <ng-template [hilosTableCell]="keys.setVersion" let-row>{{
              row.setVersion ?? '—'
            }}</ng-template>
            <ng-template [hilosTableCell]="keys.heldCount" let-row>{{
              row.heldCount
            }}</ng-template>
            <ng-template [hilosTableCell]="actionsKey" let-row>
              <a
                [hilosLink]="
                  path(pages.LEGAL_REVISION, {
                    documentKey: data.document,
                    revisionId: row.rowKey,
                  })
                "
                class="btn btn-sm btn-outline-secondary"
                data-id="legal-revision-open"
                [attr.data-revision]="row.rowKey"
                >Open</a
              >
            </ng-template>
          </hilos-viewport-table>
        }
      </ng-template>
    </hilos-legal-document-page-shell>
  `,
})
export class HilosLegalDocumentRevisionsPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()

  protected readonly page = HilosPages.LEGAL_DOCUMENT_REVISIONS
  protected readonly pages = HilosPages
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly path = resolveHilosPath
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
    createHilosLegalRevisionsTable(
      this.context(),
      this.documentKey,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
    ),
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
