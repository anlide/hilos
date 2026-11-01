import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalDocumentsTable,
  createHilosLegalSettingsTable,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  hilosLegalLapsedHref,
  LEGAL_CATALOG_REFUSAL_SECTION,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  HILOS_TABLE_ACTIONS_KEY,
  resolveHilosPath,
  subscribeSignal,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
} from '@hilos/angular/base'

/** Declared documents and their acceptance tallies, searched and ordered on the server. */
@Component({
  selector: 'hilos-legal-documents-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      @if (refusal() !== null) {
        <div
          class="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 class="h6">The legal document catalog could not be loaded</h2>
          <p class="mb-0">{{ refusal() }}</p>
        </div>
      } @else {
        <hilos-viewport-table [controller]="tables().documents.controller">
          <ng-template [hilosTableCell]="keys.documentLabel" let-row>
            <div
              class="fw-semibold"
              data-id="legal-document-row"
              [attr.data-document]="row.rowKey"
            >
              {{ row.documentLabel }}
            </div>
            <div class="small text-body-secondary">{{ row.rowKey }}</div>
            @if (!row.declared) {
              <span class="badge text-bg-warning">Not in code</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.publishedOn" let-row>
            @if (row.revision) {
              <div>{{ row.publishedOn }} · {{ row.revision.significance }}</div>
              <div class="small text-body-secondary">
                Effective from {{ row.revision.effectiveOn }}
              </div>
            } @else {
              <span class="text-body-secondary">Not in code</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.covered" let-row
            ><span data-id="legal-count-covered">{{
              row.covered
            }}</span></ng-template
          >
          <ng-template [hilosTableCell]="keys.window" let-row
            ><span data-id="legal-count-window">{{
              row.window
            }}</span></ng-template
          >
          <!-- The count of the people past the deadline opens them in the
          people list (HIL-945); nobody to open, nothing to follow. Its label
          is the column header. -->
          <ng-template [hilosTableCell]="keys.lapsed" let-row>
            @if (row.lapsed > 0) {
              <a
                [hilosLink]="lapsedHref(row.rowKey)"
                data-id="legal-count-lapsed-link"
                [attr.data-document]="row.rowKey"
                ><span data-id="legal-count-lapsed">{{ row.lapsed }}</span></a
              >
            } @else {
              <span data-id="legal-count-lapsed">{{ row.lapsed }}</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="actionsKey" let-row
            ><a
              [hilosLink]="
                path(pages.LEGAL_DOCUMENT, { documentKey: row.rowKey })
              "
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-document-open"
              [attr.data-document]="row.rowKey"
              >Open</a
            ></ng-template
          >
        </hilos-viewport-table>
      }
    </hilos-admin-page>
  `,
})
export class HilosLegalDocumentsPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_DOCUMENTS
  protected readonly pages = HilosPages
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly path = resolveHilosPath
  protected readonly lapsedHref = hilosLegalLapsedHref
  protected readonly refusal = signal<string | null>(null)
  /** Both carriers of the page; the refusal policy names the past-deadline column. */
  protected readonly tables = computed(() => {
    const context = this.context()
    const settings = createHilosLegalSettingsTable(
      context,
      HilosPages.LEGAL_DOCUMENTS,
    )
    const policy = computedSignal(
      () =>
        settings.controller.rows
          .get()
          .find(
            ({ row }) =>
              row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
          )?.row?.value,
    )
    return {
      settings,
      documents: createHilosLegalDocumentsTable(
        context,
        HilosPages.LEGAL_DOCUMENTS,
        policy,
      ),
    }
  })

  constructor() {
    effect((onCleanup) => {
      const { settings, documents } = this.tables()
      const source = this.context().scopes.pageDataSignal(
        LEGAL_CATALOG_REFUSAL_SECTION,
      )
      const updateRefusal = () => {
        const value = source.get()
        this.refusal.set(typeof value === 'string' ? value : null)
      }
      updateRefusal()
      const off = subscribeSignal(source, updateRefusal)
      settings.start()
      documents.start()
      onCleanup(() => {
        off()
        documents.dispose()
        settings.dispose()
        documents.controller.dispose()
        settings.controller.dispose()
      })
    })
  }
}
