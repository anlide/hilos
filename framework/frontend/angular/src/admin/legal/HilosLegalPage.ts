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
  createHilosLegalChecksTable,
  createHilosLegalSettingsTable,
  HilosPages,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  HILOS_TABLE_ACTIONS_KEY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  describeHilosLegalCheck,
  hilosLegalDocumentLabel,
  hilosLegalLapsedHref,
  resolveHilosPath,
  subscribeSignal,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Read-only legal declarations and acceptance tallies. */
@Component({
  selector: 'hilos-legal-page',
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
        <div class="alert alert-secondary small">
          Document texts live in code. Standard sets belong to Hilos; revisions
          and deviations belong to the project. Publishing a revision means
          deploying it. This section records what is declared and who accepted
          it.
        </div>
        <hilos-viewport-table [controller]="documents().controller">
          <ng-template [hilosTableCell]="keys.rowKey" let-row
            ><span
              data-id="legal-document-row"
              [attr.data-document]="row.rowKey"
              class="fw-semibold"
              >{{ documentLabel(row.rowKey) }}</span
            >
            @if (!row.declared) {
              <span class="badge text-bg-warning ms-2">Not in code</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.revision" let-row>
            @if (row.revision) {
              <div>
                {{ row.revision.publishedOn }} · {{ row.revision.significance }}
              </div>
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
          people list (HIL-945); nobody to open, nothing to follow. -->
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
            <span class="small text-body-secondary">{{
              lapsedLabel()
            }}</span></ng-template
          >
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
        @if (hasDocuments()) {
          <div class="mt-4">
            <hilos-viewport-table [controller]="checks().controller"
              ><ng-template [hilosTableCell]="keys.rowKey" let-row>
                <div data-id="legal-check-row" [attr.data-check]="row.rowKey">
                  <span
                    [class]="
                      row.ok
                        ? 'badge me-2 text-bg-success'
                        : 'badge me-2 text-bg-warning'
                    "
                    >{{ row.ok ? 'OK' : 'Review' }}</span
                  ><strong>{{ describeCheck(row).title }}</strong>
                  @if (row.items.length) {
                    <ul class="small mt-2 mb-0">
                      @for (line of describeCheck(row).lines; track $index) {
                        <li>{{ line }}</li>
                      }
                    </ul>
                  }
                </div>
              </ng-template></hilos-viewport-table
            >
          </div>
        }
        <section class="mt-4 small text-body-secondary">
          <h2 class="h6">What this section does not do</h2>
          <p>
            There is no text editor, publish button, or acceptance-window
            setting. Those decisions are declared in the project's code.
          </p>
          <h2 class="h6">Why this is under Access &amp; identity</h2>
          <p class="mb-0">
            Agreement belongs to a person and an exact revision. Its deadline
            can affect that person's access.
          </p>
        </section>
      }
    </hilos-admin-page>
  `,
})
export class HilosLegalPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL
  protected readonly pages = HilosPages
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly path = resolveHilosPath
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly lapsedHref = hilosLegalLapsedHref
  protected readonly describeCheck = describeHilosLegalCheck
  protected readonly refusal = signal<string | null>(null)
  protected readonly lapsedLabel = signal('Frozen')
  protected readonly hasDocuments = signal(false)
  protected readonly documents = computed(() =>
    createHilosLegalDocumentsTable(this.context()),
  )
  protected readonly checks = computed(() =>
    createHilosLegalChecksTable(this.context()),
  )
  private readonly settings = computed(() =>
    createHilosLegalSettingsTable(this.context(), HilosPages.LEGAL),
  )

  constructor() {
    effect((onCleanup) => {
      const documents = this.documents(),
        checks = this.checks(),
        settings = this.settings()
      const source = this.context().scopes.pageDataSignal(
        LEGAL_CATALOG_REFUSAL_SECTION,
      )
      const updateRefusal = () => {
        const value = source.get()
        this.refusal.set(typeof value === 'string' ? value : null)
      }
      const updateSettings = () =>
        this.lapsedLabel.set(
          settings.controller.rows
            .get()
            .find(
              ({ row }) =>
                row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
            )?.row?.value === 'remind'
            ? 'Past deadline'
            : 'Frozen',
        )
      const updateDocuments = () =>
        this.hasDocuments.set(documents.controller.rows.get().length > 0)
      updateRefusal()
      updateSettings()
      updateDocuments()
      const off = [
        subscribeSignal(source, updateRefusal),
        subscribeSignal(settings.controller.rows, updateSettings),
        subscribeSignal(documents.controller.rows, updateDocuments),
      ]
      documents.start()
      checks.start()
      settings.start()
      onCleanup(() => {
        for (const stop of off) stop()
        documents.dispose()
        checks.dispose()
        settings.dispose()
      })
    })
  }
}
