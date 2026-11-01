import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalChecksTable,
  createHilosLegalOverview,
  createHilosLegalSettingsTable,
  describeHilosLegalCheck,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  HILOS_LEGAL_VALUE_COPY,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  LEGAL_CATALOG_REFUSAL_SECTION,
  type HilosLegalOverview,
  type HilosLegalSettingRow,
} from '@hilos/core/admin/legal'
import {
  HilosPages,
  subscribeSignal,
  type HilosLegalContext,
  type HilosTableBody,
  type TableViewportRow,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  HilosSkeleton,
  HilosTableCell,
  HilosTableEmptyState,
  HilosViewportTable,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/**
 * The Legal Overview: the page answer's summary per document, the two
 * settings read from the settings carrier, and the declaration checks. It
 * holds no window of the documents table.
 */
@Component({
  selector: 'hilos-legal-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    HilosSkeleton,
    HilosTableCell,
    HilosTableEmptyState,
    HilosViewportTable,
  ],
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
        <ng-template #noFallback></ng-template>
        @if (overview(); as summary) {
          <div
            class="row row-cols-2 row-cols-lg-3 g-3 mb-3"
            data-id="legal-overview"
          >
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-documents"
              >
                <h2 class="small text-body-secondary fw-normal">Documents</h2>
                <div class="fs-4 fw-semibold" data-id="legal-document-count">
                  {{ summary.documentCount }}
                </div>
                <div class="small text-body-secondary">
                  {{ documentLabels(summary) }}
                </div>
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-window"
              >
                <h2 class="small text-body-secondary fw-normal">
                  Waiting for acceptance
                </h2>
                @if (summary.documents.length) {
                  <dl class="mb-0">
                    @for (
                      document of summary.documents;
                      track document.document
                    ) {
                      <div
                        class="d-flex flex-wrap justify-content-between gap-2"
                        [attr.data-document]="document.document"
                      >
                        <dt class="small fw-normal">
                          {{ document.documentLabel }}
                        </dt>
                        <dd
                          class="fw-semibold mb-0"
                          data-id="legal-count-window"
                        >
                          {{ document.window }}
                        </dd>
                      </div>
                    }
                  </dl>
                } @else {
                  <div class="fs-4 fw-semibold" data-id="legal-count-window">
                    0
                  </div>
                }
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-lapsed"
              >
                <h2 class="small text-body-secondary fw-normal">
                  {{ lapsedLabel() }}
                </h2>
                @if (summary.documents.length) {
                  <dl class="mb-0">
                    @for (
                      document of summary.documents;
                      track document.document
                    ) {
                      <div
                        class="d-flex flex-wrap justify-content-between gap-2"
                        [attr.data-document]="document.document"
                      >
                        <dt class="small fw-normal">
                          {{ document.documentLabel }}
                        </dt>
                        <dd class="fw-semibold mb-0">
                          @if (document.lapsed > 0) {
                            <a
                              [hilosLink]="lapsedHref(document.document)"
                              data-id="legal-count-lapsed-link"
                              ><span data-id="legal-count-lapsed">{{
                                document.lapsed
                              }}</span></a
                            >
                          } @else {
                            <span data-id="legal-count-lapsed">{{
                              document.lapsed
                            }}</span>
                          }
                        </dd>
                      </div>
                    }
                  </dl>
                } @else {
                  <div class="fs-4 fw-semibold" data-id="legal-count-lapsed">
                    0
                  </div>
                }
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-newer-sets"
              >
                <h2 class="small text-body-secondary fw-normal">
                  New framework sets
                </h2>
                <div class="fs-4 fw-semibold" data-id="legal-newer-set-count">
                  {{ summary.newerSetCount }}
                </div>
                <div class="small text-body-secondary">
                  Documents with a newer framework set available.
                </div>
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-consent"
              >
                <h2 class="small text-body-secondary fw-normal">Consent</h2>
                @if (consent(); as setting) {
                  <div class="fs-4 fw-semibold">
                    <hilos-hideable [value]="setting.value"
                      ><ng-template let-shown>{{
                        valueCopy(shown)
                      }}</ng-template></hilos-hideable
                    >
                  </div>
                } @else if (settingsBody() === 'unavailable') {
                  <hilos-table-empty-state
                    [controller]="carriers().settings.controller"
                    kind="unavailable"
                    [fallback]="noFallback"
                  />
                } @else {
                  <hilos-skeleton />
                }
                <div class="small text-body-secondary">
                  How people agree at registration.
                </div>
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="legal-overview-refusal"
              >
                <h2 class="small text-body-secondary fw-normal">Refusal</h2>
                @if (policy(); as setting) {
                  <div class="fs-4 fw-semibold">
                    <hilos-hideable [value]="setting.value"
                      ><ng-template let-shown>{{
                        valueCopy(shown)
                      }}</ng-template></hilos-hideable
                    >
                  </div>
                } @else if (settingsBody() === 'unavailable') {
                  <hilos-table-empty-state
                    [controller]="carriers().settings.controller"
                    kind="unavailable"
                    [fallback]="noFallback"
                  />
                } @else {
                  <hilos-skeleton />
                }
                <div class="small text-body-secondary">
                  What happens after a deadline.
                </div>
              </section>
            </div>
          </div>
        } @else {
          <hilos-skeleton />
        }
        @if (checksBody() !== 'empty') {
          <div class="mt-4">
            <hilos-viewport-table [controller]="carriers().checks.controller"
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
      }
    </hilos-admin-page>
  `,
})
export class HilosLegalPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL
  protected readonly keys = HilosLegalRowKey
  protected readonly lapsedHref = hilosLegalLapsedHref
  protected readonly describeCheck = describeHilosLegalCheck
  protected readonly refusal = signal<string | null>(null)
  protected readonly overview = signal<HilosLegalOverview | null>(null)
  protected readonly checksBody = signal<HilosTableBody>('loading')
  protected readonly settingsBody = signal<HilosTableBody>('loading')
  private readonly settingsRows = signal<
    readonly TableViewportRow<HilosLegalSettingRow>[]
  >([])
  /** The summary of the page answer and the two carriers of the page. */
  protected readonly carriers = computed(() => {
    const context = this.context()

    return {
      overview: createHilosLegalOverview(context),
      checks: createHilosLegalChecksTable(context),
      settings: createHilosLegalSettingsTable(context, HilosPages.LEGAL),
    }
  })
  protected readonly consent = computed(() => this.setting('consentForm'))
  protected readonly policy = computed(() =>
    this.setting('refusalAfterDeadline'),
  )
  /** Frozen or Past deadline by the refusal setting; Past deadline when it is hidden. */
  protected readonly lapsedLabel = computed(() =>
    hilosLegalLapsedLabel(this.policy()?.value),
  )

  constructor() {
    effect((onCleanup) => {
      const { overview, checks, settings } = this.carriers()
      const source = this.context().scopes.pageDataSignal(
        LEGAL_CATALOG_REFUSAL_SECTION,
      )
      const updateRefusal = () => {
        const value = source.get()
        this.refusal.set(typeof value === 'string' ? value : null)
      }
      updateRefusal()
      const off = [
        subscribeSignal(source, updateRefusal),
        mirrorHilosSignal(overview, this.overview),
        mirrorHilosSignal(checks.controller.frame.body, this.checksBody),
        mirrorHilosSignal(settings.controller.frame.body, this.settingsBody),
        mirrorHilosSignal(settings.controller.rows, this.settingsRows),
      ]
      checks.start()
      settings.start()
      onCleanup(() => {
        for (const stop of off) stop()
        checks.dispose()
        settings.dispose()
      })
    })
  }

  /**
   * The names of the declared documents, in the summary's order.
   *
   * @param summary The page answer's summary.
   */
  protected documentLabels(summary: HilosLegalOverview): string {
    return summary.documents
      .map(({ documentLabel }) => documentLabel)
      .join(' · ')
  }

  /**
   * The words of a setting value, or the value itself when it has none.
   *
   * @param value The value the server sent, not hidden.
   */
  protected valueCopy(value: string): string {
    return HILOS_LEGAL_VALUE_COPY[value] ?? value
  }

  /**
   * The settings carrier's row of one legal setting, absent until it arrives.
   *
   * @param key The setting's name in {@link HilosLegalSettingKey}.
   */
  private setting(
    key: keyof typeof HilosLegalSettingKey,
  ): HilosLegalSettingRow | undefined {
    return (
      this.settingsRows().find(
        ({ row }) => row?.rowKey === HilosLegalSettingKey[key],
      )?.row ?? undefined
    )
  }
}
