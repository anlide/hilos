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
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_REVISION_SECTION,
  legalAdminRevisionSchema,
  resolveHilosPath,
  subscribeSignal,
  type HilosLegalContext,
  type HilosLegalAdminRevision,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosLegalChanges } from '../../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../../legal/HilosLegalRevisionText.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** Exact revision text, changes and its live acceptance count. */
@Component({
  selector: 'hilos-legal-revision-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosLink,
    HilosLegalChanges,
    HilosLegalRevisionText,
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
      } @else if (details(); as data) {
        @if (!data.declared) {
          <div class="alert alert-warning" data-id="legal-revision-undeclared">
            This revision is no longer in code. Its text and comparison are
            unavailable.
          </div>
        } @else if (data.revision; as revision) {
          <p>
            <strong>{{ data.revisionId }}</strong>
            @if (data.current) {
              <span class="badge text-bg-primary ms-2">Current</span>
            }
            <span class="badge text-bg-secondary ms-2">{{
              revision.significance
            }}</span>
          </p>
          <p class="text-body-secondary small">
            Published {{ revision.publishedOn }} · Effective
            {{ revision.effectiveOn }}
          </p>
          <section class="mb-4">
            <h2 class="h5">Changes from the previous revision</h2>
            @if (data.changes !== null && data.predecessorId !== null) {
              <hilos-legal-changes
                [changes]="data.changes"
                [fromLabel]="data.predecessorId"
                [toLabel]="data.revisionId"
              />
            } @else {
              <p class="text-body-secondary">
                This is the first revision; there is nothing to compare it with.
              </p>
            }
          </section>
          @if (data.clauses) {
            <section class="mb-4">
              <h2 class="h5">Complete text</h2>
              <hilos-legal-revision-text [clauses]="data.clauses" />
            </section>
          }
        }
        <section>
          <h2 class="h5">Who accepted this revision</h2>
          <p data-id="legal-revision-accepted">
            {{ accepted() }}
          </p>
          <a
            [hilosLink]="acceptancesPath"
            class="btn btn-outline-secondary"
            data-id="legal-open-acceptances"
            >Open acceptances</a
          >
        </section>
      }
    </hilos-admin-page>
  `,
})
export class HilosLegalRevisionPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_REVISION
  protected readonly acceptancesPath = resolveHilosPath(
    HilosPages.LEGAL_ACCEPTANCES,
  )
  protected readonly details = signal<HilosLegalAdminRevision | null>(null)
  protected readonly refusal = signal<string | null>(null)
  protected readonly accepted = signal('Loading acceptance count…')
  private readonly router = inject(HILOS_ROUTER)
  private readonly document = computedSignal(() =>
    String(this.router.currentRoute.get().params['documentKey'] ?? ''),
  )
  private readonly revisionId = computedSignal(() =>
    String(this.router.currentRoute.get().params['revisionId'] ?? ''),
  )
  private readonly revisions = computed(() =>
    createHilosLegalRevisionsTable(
      this.context(),
      this.document,
      HilosPages.LEGAL_REVISION,
      this.revisionId,
    ),
  )

  constructor() {
    effect((onCleanup) => {
      const context = this.context(),
        revisions = this.revisions()
      const source = context.scopes.pageDataSignal(LEGAL_REVISION_SECTION)
      const refusal = context.scopes.pageDataSignal(
        LEGAL_CATALOG_REFUSAL_SECTION,
      )
      const summary = createHilosLegalRevisionAcceptanceSummary(
        revisions.controller,
        this.revisionId,
      )
      const readCount = () => this.accepted.set(summary.get())
      const read = () => {
        const parsed = legalAdminRevisionSchema.safeParse(source.get())
        this.details.set(parsed.success ? parsed.data : null)
        readCount()
      }
      const readRefusal = () => {
        const value = refusal.get()
        this.refusal.set(typeof value === 'string' ? value : null)
      }
      read()
      readRefusal()
      const off = [
        subscribeSignal(source, read),
        subscribeSignal(refusal, readRefusal),
        subscribeSignal(summary, readCount),
      ]
      revisions.start()
      onCleanup(() => {
        for (const stop of off) stop()
        revisions.dispose()
      })
    })
  }
}
